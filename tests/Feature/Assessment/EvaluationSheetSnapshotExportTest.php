<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exportar um momento guardado, em CSV e em Excel.
 *
 * A REGRA QUE ESTE FICHEIRO EXISTE PARA PROTEGER: o ficheiro de um momento
 * representa esse momento. Se o professor atribuiu 3 a 10 de novembro, guardou,
 * e a 12 mudou para 4, o ficheiro do momento de 10 continua a dizer 3 — para
 * sempre. Quem quiser o 4 guarda um momento novo e exporta esse (§9, §16).
 *
 * É fácil partir isto por distração: bastaria o exportador chamar
 * `BuildEvaluationSheet` «para ter os dados frescos». Por isso o controlador
 * que serve estes ficheiros nem sequer recebe o construtor da pauta, e há um
 * teste aqui que o verifica na própria assinatura.
 */
class EvaluationSheetSnapshotExportTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // Dentro do 1.º Semestre do cenário demo (14/09/2026 a 29/01/2027).
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        return $teacher;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    /**
     * A turma, o período e a matrícula da Carolina.
     *
     * @return array{string, string, string}
     */
    private function context(User $teacher): array
    {
        return $this->asTenant($teacher, function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            $enrollment = $class->enrollments()->with('student.identity')->get()
                ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === 'Carolina Nunes');

            return [$class->ulid, $period->ulid, $enrollment->ulid];
        });
    }

    private function levelId(User $teacher, string $code): int
    {
        return $this->asTenant($teacher, fn (): int => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
    }

    private function decide(User $teacher, string $classUlid, string $periodUlid, string $enrollmentUlid, string $code): void
    {
        $this->actingAs($teacher)->post(
            "/classes/{$classUlid}/classifications/{$periodUlid}/{$enrollmentUlid}/decide",
            ['final_scale_level_id' => $this->levelId($teacher, $code)],
        )->assertSessionHasNoErrors()->assertRedirect();
    }

    private function keep(User $teacher, string $classUlid, string $periodUlid, string $label): string
    {
        $this->actingAs($teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
            'moment_label' => $label,
            'effective_at' => '2026-12-15',
            'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $this->asTenant($teacher, fn (): string => EvaluationSheetExport::query()
            ->orderByDesc('id')->firstOrFail()->ulid);
    }

    private function csv(User $teacher, string $classUlid, string $exportUlid): TestResponse
    {
        return $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/historico/{$exportUlid}/csv");
    }

    private function xlsx(User $teacher, string $classUlid, string $exportUlid): TestResponse
    {
        return $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/historico/{$exportUlid}/xlsx");
    }

    /**
     * O Excel devolvido, aberto de verdade — não bytes com a extensão certa.
     *
     * @return array<int, array<string, string|null>>
     */
    private function openXlsx(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis_test_xlsx_');
        $this->assertIsString($path);
        file_put_contents($path, (string) $response->getContent());

        try {
            $reader = new XlsxReader;
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, false, false);
            $spreadsheet->disconnectWorksheets();

            return $rows;
        } finally {
            @unlink($path);
        }
    }

    // ---------------------------------------------------- o ficheiro existe

    #[Test]
    public function a_kept_moment_can_be_exported_as_csv(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->context($teacher);

        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, '3');
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento intercalar');

        $response = $this->csv($teacher, $classUlid, $exportUlid);
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = (string) $response->getContent();

        // BOM UTF-8, para o Excel em Windows abrir «Educação Literária» bem.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Educação Literária', $body);

        // O ficheiro diz de que pauta se trata — um CSV descarregado perde o
        // contexto no segundo seguinte.
        $this->assertStringContainsString('Momento intercalar', $body);
        $this->assertStringContainsString('7.º A', $body);
        $this->assertStringContainsString('Português', $body);
        $this->assertStringContainsString('2026-12-15', $body);
        $this->assertStringContainsString('Momento guardado', $body);

        // A tabela, com a proposta e a decisão em colunas SEPARADAS.
        $this->assertStringContainsString('Proposta do Lapispro', $body);
        $this->assertStringContainsString('Nível atribuído', $body);
        $this->assertStringContainsString('Carolina Nunes', $body);
        $this->assertStringContainsString('Decisão do professor', $body);

        // E o nome do ficheiro identifica o momento, sem acentos partidos.
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('Pauta_7-o-A_Portugues', $disposition);
        $this->assertStringEndsWith('.csv"', $disposition);
    }

    #[Test]
    public function a_kept_moment_can_be_exported_as_a_real_xlsx(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->context($teacher);

        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, '3');
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento intercalar');

        $response = $this->xlsx($teacher, $classUlid, $exportUlid);
        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        // ABERTO DE VERDADE. Bytes com a extensão certa não são um `.xlsx`, e
        // um ficheiro que o Excel recusa é pior do que nenhum.
        $rows = $this->openXlsx($response);
        $flat = implode("\n", array_map(fn (array $row): string => implode('|', array_map('strval', $row)), $rows));

        $this->assertStringContainsString('Pauta de Avaliação — Momento intercalar', $flat);
        $this->assertStringContainsString('7.º A', $flat);
        $this->assertStringContainsString('Português', $flat);
        // Unicode português intacto depois de uma volta pelo ZIP do xlsx.
        $this->assertStringContainsString('Educação Literária', $flat);
        $this->assertStringContainsString('Carolina Nunes', $flat);
        $this->assertStringContainsString('Proposta do Lapispro', $flat);
        $this->assertStringContainsString('Nível atribuído', $flat);
        $this->assertStringContainsString('Momento guardado', $flat);

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('Pauta_7-o-A_Portugues', $disposition);
        $this->assertStringEndsWith('.xlsx"', $disposition);
    }

    #[Test]
    public function the_excel_is_laid_out_to_be_read_and_coloured_by_domain(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento intercalar');

        $path = tempnam(sys_get_temp_dir(), 'lapis_test_xlsx_');
        $this->assertIsString($path);
        file_put_contents($path, (string) $this->xlsx($teacher, $classUlid, $exportUlid)->getContent());

        try {
            $spreadsheet = (new XlsxReader)->load($path);
            $sheet = $spreadsheet->getActiveSheet();

            $this->assertSame('Pauta', $sheet->getTitle());

            // Painéis fixos: o nome do aluno e o cabeçalho ficam à vista. A
            // linha exata depende do bloco de título, e o que interessa é que
            // estão congelados abaixo e à direita dele.
            $frozen = (string) $sheet->getFreezePane();
            $this->assertStringStartsWith('C', $frozen);
            $this->assertGreaterThan(5, (int) substr($frozen, 1));

            $this->assertNotNull($sheet->getAutoFilter()->getRange());

            // A COR IDENTIFICA O DOMÍNIO. Procurada na linha do cabeçalho dos
            // domínios: alguma célula tem de estar pintada com uma cor que não
            // é o cinzento neutro nem branco.
            $painted = false;

            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $rgb = $cell->getStyle()->getFill()->getStartColor()->getRGB();

                    if ($rgb !== null && ! in_array(strtoupper((string) $rgb), ['FFFFFF', '000000', 'E5E7EB', 'F3F4F6'], true)) {
                        $painted = true;
                        break 2;
                    }
                }
            }

            $this->assertTrue($painted, 'O Excel da pauta não traz a cor dos domínios.');

            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------- o ficheiro representa o passado

    #[Test]
    public function changing_the_current_sheet_afterwards_changes_no_byte_of_the_kept_files(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->context($teacher);

        // 10 de novembro, por assim dizer: o professor decide 3 e guarda.
        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, '3');
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento de novembro');

        $csvBefore = (string) $this->csv($teacher, $classUlid, $exportUlid)->getContent();
        $xlsxBefore = $this->openXlsx($this->xlsx($teacher, $classUlid, $exportUlid));

        // 12 de novembro: muda de ideias para 4. A pauta ATUAL segue-o.
        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, '4');

        $csvAfter = (string) $this->csv($teacher, $classUlid, $exportUlid)->getContent();
        $xlsxAfter = $this->openXlsx($this->xlsx($teacher, $classUlid, $exportUlid));

        $this->assertSame($csvBefore, $csvAfter);
        $this->assertSame($xlsxBefore, $xlsxAfter);

        // E o que lá está é o 3, não o 4.
        $carolinaLine = collect(explode("\n", $csvAfter))->first(fn (string $line): bool => str_contains($line, 'Carolina Nunes'));
        $this->assertIsString($carolinaLine);
        $this->assertStringContainsString('"3"', '"'.str_replace(',', '","', trim($carolinaLine)).'"');

        // Guardar um momento NOVO é que traz o 4.
        $newUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento de dezembro');
        $this->assertNotSame($exportUlid, $newUlid);

        $fresh = (string) $this->csv($teacher, $classUlid, $newUlid)->getContent();
        $this->assertStringContainsString('Momento de dezembro', $fresh);
        $this->assertNotSame($csvBefore, $fresh);
    }

    #[Test]
    public function the_exporter_has_no_way_of_reaching_the_present(): void
    {
        // ESTRUTURAL, NÃO UM HÁBITO. O controlador que serve estes ficheiros não
        // recebe `BuildEvaluationSheet`, o calculador, nem a escala: uma edição
        // futura que quisesse «atualizar os valores» não teria por onde.
        $source = (string) file_get_contents(
            app_path('Http/Controllers/EvaluationSheetSnapshotExportController.php'),
        );

        // Nada disto está importado: o presente não tem como entrar na sala.
        // (O nome aparece nos comentários, a explicar precisamente porquê.)
        $this->assertStringNotContainsString('use App\Services\Assessment\BuildEvaluationSheet;', $source);
        $this->assertStringNotContainsString('use App\Services\Assessment\ClassResultsCalculator;', $source);
        $this->assertStringNotContainsString('use App\Models\Classification;', $source);
        $this->assertStringContainsString('EvaluationSheetDocument::fromSnapshot', $source);

        // E o documento congelado é construído a partir do payload, não da
        // configuração de hoje.
        $document = (string) file_get_contents(
            app_path('Services/Assessment/Export/EvaluationSheetDocument.php'),
        );
        $this->assertStringNotContainsString('use App\Services\Assessment\BuildEvaluationSheet;', $document);
        $this->assertStringNotContainsString('use App\Support\Assessment\DomainColorPalette;', $document);
    }

    #[Test]
    public function a_sheet_kept_before_the_self_assessment_existed_still_exports(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento antigo');

        // Um payload como os que já existem em produção: sem autoavaliação, e
        // sem código de nível (que só passou a viajar na 0.130.1).
        $this->asTenant($teacher, function (): void {
            $export = EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail();
            $payload = $export->payload;

            foreach ($payload['students'] as $index => $student) {
                unset($payload['students'][$index]['self_assessment']);
                unset($payload['students'][$index]['overall']['scale_level_code']);

                foreach ($student['domains'] as $domainIndex => $domain) {
                    unset($payload['students'][$index]['domains'][$domainIndex]['self_assessment']);
                    unset($payload['students'][$index]['domains'][$domainIndex]['scale_level_code']);
                }
            }

            foreach ($payload['domains'] as $index => $domain) {
                unset($payload['domains'][$index]['color']);
            }

            DB::table('evaluation_sheet_exports')->where('id', $export->id)->update([
                'payload' => json_encode($payload),
                'payload_hash' => CanonicalPayload::hash($payload),
            ]);
        });

        $csv = $this->csv($teacher, $classUlid, $exportUlid);
        $csv->assertOk();
        $body = (string) $csv->getContent();

        // Sem autoavaliação no documento, não há coluna de autoavaliação — como
        // não havia no ecrã de onde a fotografia foi tirada.
        $this->assertStringNotContainsString('Autoavaliação global', $body);
        $this->assertStringContainsString('Carolina Nunes', $body);

        $xlsx = $this->xlsx($teacher, $classUlid, $exportUlid);
        $xlsx->assertOk();
        $rows = $this->openXlsx($xlsx);
        $flat = implode("\n", array_map(fn (array $row): string => implode('|', array_map('strval', $row)), $rows));
        $this->assertStringContainsString('Carolina Nunes', $flat);
    }

    // --------------------------------------------------------- autorização

    #[Test]
    public function a_teacher_who_cannot_see_the_class_cannot_export_its_history(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento intercalar');

        $stranger = User::factory()->create();

        $this->csv($stranger, $classUlid, $exportUlid)->assertNotFound();
        $this->xlsx($stranger, $classUlid, $exportUlid)->assertNotFound();
    }

    #[Test]
    public function a_records_ulid_is_not_a_key_to_another_class(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento intercalar');

        $otherClassUlid = $this->asTenant($teacher, function (): string {
            $original = SchoolClass::where('label', '7.º A')->firstOrFail();
            $other = SchoolClass::factory()->recycle($original->organization)->create([
                'academic_year_id' => $original->academic_year_id,
            ]);
            $other->teachers()->attach($original->teachers()->first()->id, ['role' => 'owner']);

            return $other->ulid;
        });

        // A turma abre-se — é do professor. O registo é que não é dela.
        $this->csv($teacher, $otherClassUlid, $exportUlid)->assertNotFound();
        $this->xlsx($teacher, $otherClassUlid, $exportUlid)->assertNotFound();
    }

    #[Test]
    public function a_tampered_snapshot_is_refused_rather_than_dressed_up_as_a_spreadsheet(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento intercalar');

        $this->asTenant($teacher, function (): void {
            $export = EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail();
            $payload = $export->payload;
            $payload['students'][0]['name'] = 'Nome Trocado';

            // O selo NÃO é recalculado: é exatamente isto que a verificação de
            // integridade existe para apanhar.
            DB::table('evaluation_sheet_exports')->where('id', $export->id)
                ->update(['payload' => json_encode($payload)]);
        });

        $this->csv($teacher, $classUlid, $exportUlid)->assertStatus(409);
        $this->xlsx($teacher, $classUlid, $exportUlid)->assertStatus(409);
    }

    // ------------------------------------------------- a pauta atual, em Excel

    #[Test]
    public function the_current_sheet_has_an_excel_of_its_own_and_it_follows_the_decisions(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->context($teacher);

        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, '3');
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento de novembro');
        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, '4');

        $live = $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/xlsx/{$periodUlid}");
        $live->assertOk();
        $live->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $liveRows = $this->openXlsx($live);
        $keptRows = $this->openXlsx($this->xlsx($teacher, $classUlid, $exportUlid));

        $carolinaLive = $this->rowOf($liveRows, 'Carolina Nunes');
        $carolinaKept = $this->rowOf($keptRows, 'Carolina Nunes');

        // DUAS FONTES, DUAS RESPOSTAS, E É ASSIM QUE TEM DE SER: a pauta atual
        // diz 4, o momento guardado continua a dizer 3.
        $this->assertContains('4', $carolinaLive);
        $this->assertNotContains('4', array_slice($carolinaKept, -4));
        $this->assertContains('3', $carolinaKept);

        // E o ficheiro da pauta atual não se apresenta como um momento guardado.
        $liveFlat = implode("\n", array_map(fn (array $row): string => implode('|', array_map('strval', $row)), $liveRows));
        $this->assertStringNotContainsString('Momento guardado', $liveFlat);
        $this->assertStringNotContainsString('Momento de novembro', $liveFlat);
    }

    /**
     * A linha de um aluno numa folha lida, sem células vazias.
     *
     * @param  array<int, array<string, string|null>>  $rows
     * @return list<string>
     */
    private function rowOf(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            $values = array_values(array_map(fn ($cell): string => (string) $cell, $row));

            if (in_array($name, $values, true)) {
                return $values;
            }
        }

        $this->fail("A folha não tem a linha de {$name}.");
    }

    #[Test]
    public function exporting_a_kept_moment_leaves_a_trail_without_a_single_student_name(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $exportUlid = $this->keep($teacher, $classUlid, $periodUlid, 'Momento intercalar');

        $this->csv($teacher, $classUlid, $exportUlid)->assertOk();

        $this->asTenant($teacher, function (): void {
            $event = AuditEvent::query()->where('event', 'report.exported')
                ->orderByDesc('id')->firstOrFail();

            $this->assertSame('evaluation_sheet_snapshot', $event->properties['source']);
            $this->assertSame('csv', $event->properties['format']);
            $this->assertStringNotContainsString('Carolina', json_encode($event->properties).$event->summary);
        });
    }
}
