<?php

namespace Tests\Feature\Export;

use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\Domain;
use App\Models\EvaluationSheetExport;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InovarGridFixture;
use Tests\TestCase;

/**
 * «Preparar exportação para o Inovar», a partir da Pauta de Avaliação.
 *
 * EM `tests/Feature/Export/` E NÃO EM `Assessment/`, deliberadamente. O que
 * está aqui em causa é o EXPORTADOR: a fidelidade do ficheiro, a
 * correspondência por n.º de processo, os códigos escritos e a coluna do nível.
 * Reutiliza a `InovarGridFixture` e as asserções de fidelidade que o
 * `InovarExportFlowTest` já estabeleceu — o registo no histórico é a
 * consequência, não o assunto. O que é sobre a fotografia em si continua em
 * `Assessment/EvaluationSheetHistoryTest`.
 */
class EvaluationSheetInovarExportTest extends TestCase
{
    use RefreshDatabase;

    /** Dentro do 1.º Semestre do cenário de demonstração (14/09/2026 – 29/01/2027). */
    protected const INSIDE_THE_PERIOD = '2026-12-15 10:00:00';

    /** Depois de o mesmo período ter terminado. */
    protected const AFTER_THE_PERIOD = '2027-02-10 10:00:00';

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->subscribeToPro();

        // O default do nível lê-se da configuração temporal REAL do período, por
        // isso todos os testes têm de saber onde no calendário estão.
        $this->travelTo(Carbon::parse(self::INSIDE_THE_PERIOD));
    }

    // ------------------------------------------------------------ andaimes

    private function subscribeToPro(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->teacher->personalOrganization()->getKey())->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        app(Entitlements::class)->flush();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback, ?User $user = null): mixed
    {
        return app(CurrentOrganization::class)->runFor(($user ?? $this->teacher)->personalOrganization(), $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', 1)->firstOrFail());
    }

    /**
     * Dá à turma n.ºs de processo inventados e constrói uma grelha que nomeia
     * esses alunos e os domínios deste perfil.
     *
     * @param  array<string, mixed>  $options
     * @return array{path: string, numbers: array<string, string>, domains: array<string, string>}
     */
    private function grid(array $options = []): array
    {
        $numbers = $this->asTenant(function (): array {
            $assigned = [];
            $next = 1234;

            foreach ($this->schoolClass()->enrollments()->with('student.identity')->orderBy('class_number')->get() as $enrollment) {
                $number = str_pad((string) $next++, 6, '0', STR_PAD_LEFT);
                $enrollment->student->identity->update(['school_number' => $number]);
                $assigned[$enrollment->student->identity->display_name] = $number;
            }

            return $assigned;
        });

        $domains = $this->asTenant(function (): array {
            $version = $this->schoolClass()->profileVersion;
            $names = Domain::whereIn('id', $version->domains()->pluck('domain_id'))->orderBy('name')->pluck('name')->all();
            $columns = [];

            foreach (array_slice($names, 0, 3) as $index => $name) {
                $columns[chr(ord('D') + $index)] = $name;
            }

            return $columns;
        });

        return [
            'path' => (new InovarGridFixture)->build([
                'domains' => $domains,
                'students' => array_flip($numbers),
                ...$options,
            ]),
            'numbers' => $numbers,
            'domains' => $domains,
        ];
    }

    /** A grelha real que TEM a coluna sem cabeçalho, como o `.xls` do 9.º D. */
    private function gridWithALevelColumn(): array
    {
        return $this->grid(['level_column' => 'I']);
    }

    private function base(): string
    {
        return "/classes/{$this->schoolClass()->ulid}/pauta-avaliacao/inovar/{$this->period()->ulid}";
    }

    private function upload(string $path, string $uploadedAs = 'grelha.xls'): TestResponse
    {
        return $this->actingAs($this->teacher)->post($this->base(), [
            'template' => UploadedFile::fake()->createWithContent($uploadedAs, (string) file_get_contents($path)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function preparation(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props']['preparation'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirm(string $token, array $payload = []): TestResponse
    {
        return $this->actingAs($this->teacher)->post("{$this->base()}/{$token}", [
            'include_level' => false,
            ...$payload,
        ]);
    }

    private function token(TestResponse $response): string
    {
        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return (string) $page['props']['token'];
    }

    private function latestExport(): EvaluationSheetExport
    {
        return $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());
    }

    /** A folha do ficheiro que ficou guardado no registo. */
    private function storedSheet(EvaluationSheetExport $export): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'inovar_guardado_').'.xls';
        file_put_contents($path, (string) Storage::disk($export->file_disk)->get((string) $export->file_path));

        $sheet = @IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    }

    /**
     * A coluna do nível, célula a célula, contra o que a revisão prometeu.
     *
     * Onde houve decisão, está lá a decisão. Onde não houve, a célula está tal
     * e qual como a grelha a trouxe — nunca um zero, nunca a proposta, nunca um
     * traço (§13.3).
     *
     * @param  array<string, mixed>  $preparation
     */
    private function assertLevelColumn(array $preparation, string $originalPath, EvaluationSheetExport $export, string $column): void
    {
        $original = @IOFactory::load($originalPath)->getActiveSheet();
        $written = $this->storedSheet($export);
        $decided = 0;

        foreach ($preparation['students'] as $student) {
            $coordinate = $column.$student['row'];

            if ($student['level'] !== null) {
                $this->assertSame($student['level'], $written->getCell($coordinate)->getValue(), "decisão em {$coordinate}");
                $decided++;

                continue;
            }

            $this->assertSame(
                $original->getCell($coordinate)->getValue(),
                $written->getCell($coordinate)->getValue(),
                "célula {$coordinate} devia ter ficado intacta",
            );
        }

        // Sem uma decisão sequer, este teste não estaria a provar nada.
        $this->assertGreaterThan(0, $decided);
    }

    /**
     * Decide um nível para todos os alunos da turma, exceto os nomes indicados.
     *
     * @param  list<string>  $except
     */
    private function decideLevels(string $levelCode, array $except = []): void
    {
        $class = $this->schoolClass();
        $period = $this->period();

        $this->asTenant(function () use ($class, $period, $levelCode, $except): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);

            $level = $class->profileVersion->scale->levels()->where('code', $levelCode)->firstOrFail();

            foreach (Classification::query()->where('academic_period_id', $period->id)->get() as $classification) {
                $name = $classification->enrollment->student->identity->display_name;

                if (in_array($name, $except, true)) {
                    continue;
                }

                app(ConfirmClassification::class)->confirm($classification, $this->teacher, $level->id);
            }
        });
    }

    // =========================================================== A · o nível

    #[Test]
    public function the_level_is_off_by_default_while_the_period_is_still_running(): void
    {
        $grid = $this->gridWithALevelColumn();

        $preparation = $this->preparation($this->upload($grid['path']));

        $this->assertFalse($preparation['level']['default_include']);
        // Há onde escrevê-lo — o default é uma escolha, não uma indisponibilidade.
        $this->assertNotSame([], $preparation['level']['candidates']);
    }

    #[Test]
    public function the_level_is_on_by_default_once_the_period_has_ended(): void
    {
        $grid = $this->gridWithALevelColumn();

        $this->travelTo(Carbon::parse(self::AFTER_THE_PERIOD));

        $preparation = $this->preparation($this->upload($grid['path']));

        $this->assertTrue($preparation['level']['default_include']);
    }

    #[Test]
    public function the_default_is_read_from_the_periods_dates_and_never_from_its_name(): void
    {
        $grid = $this->gridWithALevelColumn();

        // O período passa a chamar-se outra coisa qualquer. O default não muda,
        // porque nunca foi lido do nome (§6).
        $this->asTenant(fn () => $this->period()->update(['label' => 'Momento intercalar de novembro']));

        $this->assertFalse($this->preparation($this->upload($grid['path']))['level']['default_include']);

        $this->travelTo(Carbon::parse(self::AFTER_THE_PERIOD));

        $this->assertTrue($this->preparation($this->upload($grid['path']))['level']['default_include']);
    }

    #[Test]
    public function the_teacher_can_turn_the_level_on_against_the_default(): void
    {
        $this->decideLevels('4');
        $grid = $this->gridWithALevelColumn();

        $upload = $this->upload($grid['path']);
        $preparation = $this->preparation($upload);

        // Estamos a meio do período, portanto o default está desligado…
        $this->assertFalse($preparation['level']['default_include']);

        // …e o professor liga-o à mesma.
        $this->confirm($this->token($upload), ['include_level' => true, 'level_column' => 'I'])
            ->assertRedirect();

        $this->assertLevelColumn($preparation, $grid['path'], $this->latestExport(), 'I');
        $this->assertContains('4', array_column($preparation['students'], 'level'));
    }

    #[Test]
    public function nothing_is_written_in_the_level_column_when_the_teacher_leaves_it_off(): void
    {
        $this->decideLevels('4');
        $grid = $this->gridWithALevelColumn();

        $upload = $this->upload($grid['path']);
        $this->confirm($this->token($upload), ['include_level' => false])->assertRedirect();

        $sheet = $this->storedSheet($this->latestExport());

        // A coluna volta exatamente como estava: os valores que a grelha já
        // trazia, e nenhum nosso.
        $this->assertSame(['3', '4', '5'], [
            $sheet->getCell('I4')->getValue(),
            $sheet->getCell('I5')->getValue(),
            $sheet->getCell('I6')->getValue(),
        ]);
    }

    #[Test]
    public function the_level_written_is_the_decision_and_never_the_proposal(): void
    {
        $grid = $this->gridWithALevelColumn();

        // Todos propostos pelo motor, e todos decididos em «5» pela professora.
        $this->decideLevels('5');

        $proposedCodes = $this->asTenant(function (): array {
            $codes = [];

            foreach (Classification::query()->where('academic_period_id', $this->period()->id)->get() as $classification) {
                $level = $classification->proposed_scale_level_id === null
                    ? null
                    : ScaleLevel::find($classification->proposed_scale_level_id);

                if ($level !== null) {
                    $codes[] = (string) $level->code;
                }
            }

            return $codes;
        });

        // Se a proposta já fosse «5» para toda a gente, este teste não provava nada.
        $this->assertNotSame([], $proposedCodes);
        $this->assertNotSame(['5'], array_values(array_unique($proposedCodes)));

        $upload = $this->upload($grid['path']);
        $preparation = $this->preparation($upload);

        $this->confirm($this->token($upload), ['include_level' => true, 'level_column' => 'I'])->assertRedirect();

        $this->assertLevelColumn($preparation, $grid['path'], $this->latestExport(), 'I');

        // Todas as decisões são «5» — a decisão da professora — e nenhuma célula
        // carrega o código que o motor tinha proposto.
        $written = array_values(array_filter(array_column($preparation['students'], 'level')));
        $this->assertNotSame([], $written);
        $this->assertSame(['5'], array_values(array_unique($written)));
    }

    #[Test]
    public function a_template_with_nowhere_to_put_the_level_says_so_instead_of_inventing_a_column(): void
    {
        // A grelha sem coluna livre — como o `.xlsx` real, que não tem nenhuma.
        $grid = $this->grid();

        $preparation = $this->preparation($this->upload($grid['path']));

        $this->assertSame([], $preparation['level']['candidates']);
        $this->assertNotNull($preparation['level']['unavailable_reason']);

        // E pedi-lo à mesma é recusado — nunca «a coluna a seguir aos domínios».
        $this->confirm($this->token($this->upload($grid['path'])), ['include_level' => true, 'level_column' => 'J'])
            ->assertSessionHasErrors('level_column');
    }

    #[Test]
    public function nothing_is_pre_selected_when_the_template_does_not_name_the_column(): void
    {
        $preparation = $this->preparation($this->upload($this->gridWithALevelColumn()['path']));

        // A coluna existe, tem valores, e continua sem nome. Pré-selecioná-la
        // seria adivinhar (§ briefing A).
        $this->assertSame('I', $preparation['level']['candidates'][0]['column']);
        $this->assertNull($preparation['level']['candidates'][0]['header']);
        $this->assertSame(['3', '4', '5'], $preparation['level']['candidates'][0]['samples']);
        $this->assertNull($preparation['level']['suggested_column']);
    }

    // ================================================ B · dados incompletos

    #[Test]
    public function a_student_without_a_decided_level_warns_but_never_blocks(): void
    {
        $grid = $this->gridWithALevelColumn();
        $this->decideLevels('4', except: ['Carolina Nunes']);

        $upload = $this->upload($grid['path']);
        $preparation = $this->preparation($upload);

        // Nada disto é bloqueante: é pedagógico (§7).
        $this->assertSame([], $preparation['summary']['blocking_errors']);

        $carolina = collect($preparation['students'])->firstWhere('name', 'Carolina Nunes');
        $this->assertNotNull($carolina);
        $this->assertNull($carolina['level']);

        // O professor exporta na mesma.
        $this->confirm($this->token($upload), ['include_level' => true, 'level_column' => 'I'])->assertRedirect();

        $export = $this->latestExport();

        $this->assertTrue($export->exported_with_warnings);
        $this->assertContains(
            'Carolina Nunes: sem classificação decidida — a coluna do nível fica vazia na grelha exportada.',
            $export->payload['warnings'],
        );
        $this->assertSame(count($export->payload['warnings']), (int) $export->warning_count);

        // A célula da Carolina fica exatamente como a grelha a trouxe. NUNCA um
        // zero, nunca a proposta, nunca um traço (§13.3, §3.3).
        $this->assertLevelColumn($preparation, $grid['path'], $export, 'I');

        // E o aviso lê-se no histórico, em português, sem payload cru.
        $history = $this->actingAs($this->teacher)
            ->get("/classes/{$this->schoolClass()->ulid}/pauta-avaliacao/historico");

        /** @var array<string, mixed> $page */
        $page = $history->viewData('page');
        $entry = $page['props']['entries'][0];

        $this->assertSame('Exportado com avisos', $entry['status_label']);
        $this->assertTrue($entry['has_file']);
        $this->assertContains(
            'Carolina Nunes: sem classificação decidida — a coluna do nível fica vazia na grelha exportada.',
            $entry['warnings'],
        );
    }

    // ========================================================= C · o Excel

    #[Test]
    public function an_xlsx_grid_comes_back_as_xlsx_and_not_wearing_an_xls_name(): void
    {
        // O INOVAR produz `.xls`; uma escola que abra a grelha e a grave entrega
        // `.xlsx`. O ficheiro gerado tem de sair no formato em que entrou E com
        // o nome a dizer a verdade sobre os bytes — um `.xlsx` chamado `.xls` é
        // o que o INOVAR recusa importar.
        //
        // Regressão: a extensão era lida do CAMINHO do upload, e o
        // InovarTemplateStorage guarda tudo com o mesmo nome fixo
        // («template.xls»), pelo que a resposta era sempre «xls».
        $grid = $this->grid(['format' => 'xlsx']);

        $this->confirm($this->token($this->upload($grid['path'], 'grelha.xlsx')))->assertRedirect();

        $export = $this->latestExport();
        $this->assertSame('xlsx', $export->original_extension);
        $this->assertStringEndsWith('.xlsx', (string) $export->file_path);

        $download = $this->actingAs($this->teacher)
            ->get("/classes/{$this->schoolClass()->ulid}/pauta-avaliacao/historico/{$export->ulid}/ficheiro");

        $download->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $download->headers->get('content-disposition'));

        // E os bytes são mesmo os de um `.xlsx`: um ZIP, não um OLE2.
        $this->assertStringStartsWith('PK', $download->streamedContent());
    }

    #[Test]
    public function the_template_comes_back_whole_and_the_teachers_own_file_is_never_touched(): void
    {
        $grid = $this->grid(['level_column' => 'I', 'formula_cell' => ['D12' => '=SUM(A1:A2)']]);
        $this->decideLevels('4');

        $before = @IOFactory::load($grid['path'])->getActiveSheet();
        $uploadedChecksum = hash_file('sha256', $grid['path']);

        $upload = $this->upload($grid['path']);
        $preparation = $this->preparation($upload);

        $this->confirm($this->token($upload), ['include_level' => true, 'level_column' => 'I'])->assertRedirect();

        $export = $this->latestExport();
        $after = $this->storedSheet($export);

        // O FICHEIRO DO PROFESSOR NÃO FOI MEXIDO.
        $this->assertSame($uploadedChecksum, hash_file('sha256', $grid['path']));

        // A folha, as dimensões e as fusões.
        $this->assertSame($before->getTitle(), $after->getTitle());
        $this->assertSame($before->calculateWorksheetDimension(), $after->calculateWorksheetDimension());
        $this->assertSame(array_values($before->getMergeCells()), array_values($after->getMergeCells()));

        // A fórmula continua a ser uma fórmula, e não o número a que calhava dar.
        $this->assertSame('=SUM(A1:A2)', $after->getCell('D12')->getValue());

        // As células escritas, e só essas.
        $targets = [];

        foreach ($preparation['students'] as $student) {
            foreach ($student['domains'] as $cell) {
                $targets[$cell['column'].$student['row']] = true;
            }

            $targets['I'.$student['row']] = true;
        }

        $lastColumn = Coordinate::columnIndexFromString($before->getHighestDataColumn());

        for ($row = 1; $row <= $before->getHighestDataRow(); $row++) {
            for ($index = 1; $index <= $lastColumn; $index++) {
                $coordinate = Coordinate::stringFromColumnIndex($index).$row;

                if (isset($targets[$coordinate])) {
                    continue;
                }

                $this->assertSame(
                    $before->getCell($coordinate)->getValue(),
                    $after->getCell($coordinate)->getValue(),
                    "valor alterado em {$coordinate}",
                );
                $this->assertSame(
                    $before->getCell($coordinate)->getStyle()->getFont()->getBold(),
                    $after->getCell($coordinate)->getStyle()->getFont()->getBold(),
                    "estilo alterado em {$coordinate}",
                );
            }
        }

        $this->assertEqualsWithDelta(
            $before->getColumnDimension('C')->getWidth(),
            $after->getColumnDimension('C')->getWidth(),
            0.001,
        );
    }

    #[Test]
    public function the_cells_written_are_the_ones_the_review_promised(): void
    {
        $this->decideLevels('4');
        $grid = $this->gridWithALevelColumn();

        $upload = $this->upload($grid['path']);
        $preparation = $this->preparation($upload);

        $expected = [];

        foreach ($preparation['students'] as $student) {
            foreach ($student['domains'] as $cell) {
                if ($cell['writable']) {
                    $expected[$cell['column'].$student['row']] = $cell['code'];
                }
            }
        }

        $this->assertNotEmpty($expected);

        $this->confirm($this->token($upload), ['include_level' => true, 'level_column' => 'I'])->assertRedirect();

        $sheet = $this->storedSheet($this->latestExport());

        foreach ($expected as $coordinate => $code) {
            $this->assertSame($code, $sheet->getCell($coordinate)->getValue(), "célula {$coordinate}");
        }

        // E o checksum guardado é o dos BYTES do ficheiro que ficou.
        $export = $this->latestExport();
        $this->assertSame(
            hash('sha256', (string) Storage::disk($export->file_disk)->get((string) $export->file_path)),
            $export->file_checksum,
        );
    }

    // ==================================================== D · correspondência

    #[Test]
    public function a_line_that_matches_nobody_is_reported_and_blocks_the_file(): void
    {
        $grid = $this->grid();

        // Uma linha com um n.º de processo que não é de ninguém desta turma.
        $students = array_flip($grid['numbers']);
        $students['999999'] = 'Alguém De Outra Turma';

        $path = (new InovarGridFixture)->build(['domains' => $grid['domains'], 'students' => $students]);

        $upload = $this->upload($path);
        $preparation = $this->preparation($upload);

        $stranger = collect($preparation['students'])->firstWhere('process_number', '999999');
        $this->assertFalse($stranger['matched']);
        $this->assertContains('Não há nesta turma nenhum aluno com este N.º de processo.', $stranger['issues']);

        // Tecnicamente impossível (§8): não sai ficheiro nenhum.
        $this->confirm($this->token($upload))->assertSessionHasErrors('template');
        $this->assertSame(0, $this->asTenant(fn (): int => EvaluationSheetExport::query()->count()));
    }

    #[Test]
    public function a_duplicated_process_number_never_fills_silently(): void
    {
        $grid = $this->grid();
        $numbers = array_values($grid['numbers']);

        // A mesma pessoa duas vezes na grelha: quem é a segunda linha não tem
        // resposta, e adivinhar seria escrever a nota de alguém noutra linha.
        $sheet = (new InovarGridFixture)->build([
            'domains' => $grid['domains'],
            'students' => [$numbers[0] => 'Primeira Linha', $numbers[1] => 'Segunda Linha'],
            'numeric_last' => false,
        ]);

        // Reconstruído com o número repetido, que um array não deixaria repetir.
        $duplicated = $this->duplicateProcessNumber($sheet, $numbers[0]);

        $upload = $this->upload($duplicated);
        $preparation = $this->preparation($upload);

        $this->assertNotSame([], $preparation['summary']['blocking_errors']);
        $this->confirm($this->token($upload))->assertSessionHasErrors('template');
        $this->assertSame(0, $this->asTenant(fn (): int => EvaluationSheetExport::query()->count()));
    }

    /** Escreve o mesmo n.º de processo nas duas linhas de alunos da grelha. */
    private function duplicateProcessNumber(string $path, string $number): string
    {
        $spreadsheet = @IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValueExplicit('B4', $number, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B5', $number, DataType::TYPE_STRING);

        $destination = tempnam(sys_get_temp_dir(), 'inovar_duplicado_').'.xls';
        (new Xls($spreadsheet))->save($destination);
        $spreadsheet->disconnectWorksheets();

        return $destination;
    }

    // ======================================================= E · o histórico

    #[Test]
    public function re_exporting_the_same_moment_creates_a_new_record_and_replaces_nothing(): void
    {
        $this->decideLevels('4');
        $grid = $this->gridWithALevelColumn();

        $first = $this->upload($grid['path']);
        $this->confirm($this->token($first), ['include_level' => true, 'level_column' => 'I'])->assertRedirect();
        $one = $this->latestExport();

        $second = $this->upload($grid['path']);
        $this->confirm($this->token($second), ['include_level' => false])->assertRedirect();
        $two = $this->latestExport();

        $this->assertNotSame($one->id, $two->id);
        $this->assertNotSame($one->file_path, $two->file_path);
        $this->assertNotSame($one->file_checksum, $two->file_checksum);
        $this->assertSame(2, $this->asTenant(fn (): int => EvaluationSheetExport::query()->count()));

        // Os dois ficheiros continuam lá, cada um ligado ao seu registo.
        foreach ([$one, $two] as $export) {
            $this->assertSame('inovar', $export->adapter);
            $this->assertTrue(Storage::disk($export->file_disk)->exists((string) $export->file_path));

            $download = $this->actingAs($this->teacher)
                ->get("/classes/{$this->schoolClass()->ulid}/pauta-avaliacao/historico/{$export->ulid}/ficheiro");

            $download->assertOk();
            $this->assertStringContainsString('INOVAR_', (string) $download->headers->get('content-disposition'));
            $this->assertSame($export->file_checksum, hash('sha256', $download->streamedContent()));
        }
    }

    #[Test]
    public function a_record_kept_without_a_file_has_nothing_to_download(): void
    {
        $period = $this->period();

        $this->actingAs($this->teacher)->post(
            "/classes/{$this->schoolClass()->ulid}/pauta-avaliacao/{$period->ulid}/guardar",
            ['moment_label' => 'Meio do semestre', 'effective_at' => '2026-12-10'],
        )->assertRedirect();

        $export = $this->latestExport();

        $this->assertSame('snapshot', $export->adapter);
        $this->assertNull($export->file_path);

        $this->actingAs($this->teacher)
            ->get("/classes/{$this->schoolClass()->ulid}/pauta-avaliacao/historico/{$export->ulid}/ficheiro")
            ->assertNotFound();
    }

    #[Test]
    public function the_export_leaves_a_trail_and_no_personal_data_in_it(): void
    {
        $this->decideLevels('4');
        $grid = $this->gridWithALevelColumn();

        $upload = $this->upload($grid['path']);
        $this->confirm($this->token($upload), ['include_level' => true, 'level_column' => 'I'])->assertRedirect();

        $this->asTenant(function (): void {
            $event = AuditEvent::where('event', 'evaluation-sheet.inovar.exported')->firstOrFail();

            $this->assertArrayHasKey('students', $event->properties);
            $this->assertArrayHasKey('cells', $event->properties);
            $this->assertTrue($event->properties['level_included']);
            $this->assertSame('I', $event->properties['level_column']);

            foreach (['values', 'students_names', 'file', 'template'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $event->properties);
            }
        });
    }

    // ======================================================== F · segurança

    #[Test]
    public function a_teacher_from_another_organization_cannot_reach_the_export(): void
    {
        $base = $this->base();

        $this->actingAs(User::factory()->create())->get($base)->assertNotFound();
    }

    #[Test]
    public function a_record_from_another_class_is_not_downloadable_through_this_one(): void
    {
        $this->decideLevels('4');
        $grid = $this->gridWithALevelColumn();

        $upload = $this->upload($grid['path']);
        $this->confirm($this->token($upload), ['include_level' => false])->assertRedirect();

        $export = $this->latestExport();

        // Outra turma da MESMA professora e da mesma escola: o Gate diz que sim
        // e é só o registo que não é dela. Sem a verificação de posse, o ulid no
        // URL seria um IDOR entre turmas do próprio utilizador.
        $otherClass = $this->asTenant(function (): SchoolClass {
            $class = SchoolClass::factory()
                ->recycle($this->teacher->personalOrganization())
                ->create([
                    'academic_year_id' => $this->schoolClass()->academic_year_id,
                    'subject_id' => $this->schoolClass()->subject_id,
                    'label' => '7.º B',
                ]);

            $class->teachers()->attach($this->teacher->getKey(), ['role' => 'owner']);

            return $class;
        });

        $this->actingAs($this->teacher)
            ->get("/classes/{$otherClass->ulid}/pauta-avaliacao/historico")
            ->assertOk();

        // Um ulid não é uma chave para a turma do lado, nem dentro da mesma escola.
        $this->actingAs($this->teacher)
            ->get("/classes/{$otherClass->ulid}/pauta-avaliacao/historico/{$export->ulid}/ficheiro")
            ->assertNotFound();
    }

    #[Test]
    public function another_organizations_teacher_cannot_download_the_file(): void
    {
        $this->decideLevels('4');
        $grid = $this->gridWithALevelColumn();

        $upload = $this->upload($grid['path']);
        $this->confirm($this->token($upload), ['include_level' => false])->assertRedirect();

        $export = $this->latestExport();
        $classUlid = $this->schoolClass()->ulid;

        $this->actingAs(User::factory()->create())
            ->get("/classes/{$classUlid}/pauta-avaliacao/historico/{$export->ulid}/ficheiro")
            ->assertNotFound();
    }

    #[Test]
    public function the_file_lives_on_a_private_disk_with_no_url_of_its_own(): void
    {
        $this->decideLevels('4');
        $grid = $this->gridWithALevelColumn();

        $upload = $this->upload($grid['path']);
        $this->confirm($this->token($upload), ['include_level' => false])->assertRedirect();

        $export = $this->latestExport();

        // Disco privado, e não `public`.
        $this->assertSame('local', $export->file_disk);
        $this->assertStringStartsWith('evaluation-sheet-exports/', (string) $export->file_path);
        $this->assertFalse(Storage::disk('public')->exists((string) $export->file_path));

        // A pasta é um identificador próprio, que NÃO é o do registo: nada que
        // o browser tenha visto se transforma num caminho.
        $folder = explode('/', (string) $export->file_path)[1];
        $this->assertNotSame($export->ulid, $folder);

        // E o upload do professor não fica: já não tem trabalho nenhum.
        $this->assertFalse(Storage::disk('local')->exists('inovar-exports/'.$this->token($upload).'/template.xls'));
    }

    #[Test]
    public function the_base_plan_cannot_reach_the_export_at_all(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->teacher->personalOrganization()->getKey())->delete();
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)->get($this->base())->assertForbidden();
    }

    #[Test]
    public function the_old_inovar_flow_is_still_exactly_where_it_was(): void
    {
        // Esta fatia acrescenta um caminho; não substitui nenhum (§ fora de âmbito).
        $this->actingAs($this->teacher)
            ->get("/classes/{$this->schoolClass()->ulid}/exports/inovar/{$this->period()->ulid}")
            ->assertOk();
    }
}
