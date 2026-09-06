<?php

namespace Tests\Feature\Assessment;

use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A DECISÃO POR DOMÍNIO CONGELA COM O RESTO, E O QUE CONGELOU NÃO SE MEXE.
 *
 * O cenário que este ficheiro existe para proteger, por inteiro:
 *
 *   1. «Escrita» de um aluno calcula 47,5%; o Lapispro propõe a banda «2».
 *   2. O professor decide «3». A pauta passa a mostrar as três coisas:
 *      47,5% · proposta 2 · decisão 3.
 *   3. Guarda-se a pauta. A fotografia leva as três.
 *   4. Dias depois o professor muda a decisão para «4».
 *   5. A pauta de hoje diz 47,5% · 2 · 4. A fotografia de então continua a
 *      dizer 47,5% · 2 · 3 — para sempre, e os ficheiros dela também.
 *
 * É fácil partir isto por distração: bastaria o exportador de um momento
 * guardado ir buscar «os dados frescos». Uma fotografia é uma afirmação sobre
 * um dia, e recalculá-la é apagar esse dia (§10).
 */
class DomainDecisionSnapshotTest extends TestCase
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

    /** @return array{string, string} */
    private function context(User $teacher): array
    {
        return $this->asTenant($teacher, function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$class->ulid, $class->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid];
        });
    }

    private function decide(User $teacher, string $student, string $domain, ?string $levelCode): void
    {
        [$classUlid, $periodUlid] = $this->context($teacher);

        [$enrollmentUlid, $domainUlid, $levelId] = $this->asTenant($teacher, function () use ($student, $domain, $levelCode): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            $enrollment = $class->enrollments()->with('student.identity')->get()
                ->first(fn (Enrollment $row): bool => $row->student->identity->display_name === $student);

            return [
                $enrollment->ulid,
                Domain::where('name', $domain)->firstOrFail()->ulid,
                $levelCode === null
                    ? null
                    : (int) $class->profileVersion->scale->levels()->where('code', $levelCode)->firstOrFail()->id,
            ];
        });

        $this->actingAs($teacher)->post(
            "/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/dominios/{$enrollmentUlid}/{$domainUlid}",
            ['scale_level_id' => $levelId],
        )->assertRedirect();
    }

    private function save(User $teacher, string $label): void
    {
        [$classUlid, $periodUlid] = $this->context($teacher);

        $this->actingAs($teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
            'moment_label' => $label,
            'effective_at' => '2026-12-15',
        ])->assertRedirect();
    }

    /**
     * @return array<string, mixed>
     */
    private function liveCell(User $teacher, string $student, string $domain): array
    {
        [$classUlid] = $this->context($teacher);

        /** @var array<string, mixed> $page */
        $page = $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao")->viewData('page');

        foreach ($page['props']['sheet']['students'] as $row) {
            if ($row['name'] !== $student) {
                continue;
            }

            foreach ($row['domains'] as $cell) {
                if ($cell['name'] === $domain) {
                    return $cell;
                }
            }
        }

        $this->fail("Não há célula de «{$domain}» para {$student}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function keptCell(EvaluationSheetExport $export, string $student, string $domain): array
    {
        foreach ($export->payload['students'] as $row) {
            if ($row['name'] !== $student) {
                continue;
            }

            foreach ($row['domains'] as $cell) {
                if ($cell['name'] === $domain) {
                    return $cell;
                }
            }
        }

        $this->fail("A fotografia não tem «{$domain}» de {$student}.");
    }

    private function exportOf(User $teacher, string $label): EvaluationSheetExport
    {
        return $this->asTenant($teacher, fn (): EvaluationSheetExport => EvaluationSheetExport::query()
            ->where('moment_label', $label)->firstOrFail());
    }

    private function download(User $teacher, EvaluationSheetExport $export, string $format): TestResponse
    {
        [$classUlid] = $this->context($teacher);

        return $this->actingAs($teacher)
            ->get("/classes/{$classUlid}/pauta-avaliacao/historico/{$export->ulid}/{$format}");
    }

    // ---------------------------------------------------- o caso por inteiro

    #[Test]
    public function a_snapshot_keeps_the_quantitative_the_proposal_and_the_decision_of_that_day(): void
    {
        $teacher = $this->seedDemo();

        // «Eva Salgado» em «Escrita»: 47,5% na demonstração, banda «2».
        $before = $this->liveCell($teacher, 'Eva Salgado', 'Escrita');

        $this->assertSame('47.500000', $before['normalized_value']);
        $this->assertSame('2', $before['scale_level_code']);
        $this->assertNull($before['decided_scale_level_code']);

        $this->decide($teacher, 'Eva Salgado', 'Escrita', '3');
        $this->save($teacher, 'Momento A');

        $keptA = $this->keptCell($this->exportOf($teacher, 'Momento A'), 'Eva Salgado', 'Escrita');

        // As TRÊS afirmações, congeladas juntas.
        $this->assertSame('47.500000', $keptA['normalized_value']);
        $this->assertSame('2', $keptA['scale_level_code']);
        $this->assertSame('3', $keptA['decided_scale_level_code']);
        $this->assertSame('Suficiente', $keptA['decided_scale_level_label']);

        // Dias depois, o professor muda de ideias.
        $this->decide($teacher, 'Eva Salgado', 'Escrita', '4');

        $now = $this->liveCell($teacher, 'Eva Salgado', 'Escrita');
        $this->assertSame('47.500000', $now['normalized_value'], 'O quantitativo nunca se mexe.');
        $this->assertSame('2', $now['scale_level_code'], 'A proposta nunca se mexe.');
        $this->assertSame('4', $now['decided_scale_level_code']);

        // E a fotografia continua a dizer o que dizia.
        $stillA = $this->keptCell($this->exportOf($teacher, 'Momento A'), 'Eva Salgado', 'Escrita');
        $this->assertSame('3', $stillA['decided_scale_level_code']);

        // Uma fotografia nova leva a decisão nova. As duas coexistem.
        $this->save($teacher, 'Momento B');
        $this->assertSame('4', $this->keptCell($this->exportOf($teacher, 'Momento B'), 'Eva Salgado', 'Escrita')['decided_scale_level_code']);
        $this->assertSame('3', $this->keptCell($this->exportOf($teacher, 'Momento A'), 'Eva Salgado', 'Escrita')['decided_scale_level_code']);
    }

    #[Test]
    public function the_kept_payload_is_never_rewritten_and_its_hash_still_matches(): void
    {
        $teacher = $this->seedDemo();

        $this->decide($teacher, 'Eva Salgado', 'Escrita', '3');
        $this->save($teacher, 'Momento A');

        $export = $this->exportOf($teacher, 'Momento A');
        $hash = $export->payload_hash;

        $this->decide($teacher, 'Eva Salgado', 'Escrita', '5');

        $again = $this->exportOf($teacher, 'Momento A');

        $this->assertSame($hash, $again->payload_hash);
        $this->assertSame($hash, CanonicalPayload::hash($again->payload));
        $this->assertTrue($again->isIntact());
    }

    // ------------------------------------------------ os ficheiros históricos

    #[Test]
    public function the_historical_csv_carries_the_decision_of_that_moment_and_the_proposal_beside_it(): void
    {
        $teacher = $this->seedDemo();

        $this->decide($teacher, 'Eva Salgado', 'Escrita', '3');
        $this->save($teacher, 'Momento A');
        $this->decide($teacher, 'Eva Salgado', 'Escrita', '5');

        $csv = $this->download($teacher, $this->exportOf($teacher, 'Momento A'), 'csv');
        $csv->assertOk();

        $lines = array_values(array_filter(explode("\n", (string) $csv->getContent())));
        $header = str_getcsv(ltrim($lines[array_search(true, array_map(
            fn (string $line): bool => str_starts_with($line, '"Nº"') || str_starts_with($line, 'Nº'),
            $lines,
        ), true)], "\u{FEFF}"));

        $appreciation = array_search('Escrita — Apreciação', $header, true);
        $proposal = array_search('Escrita — Proposta do Lapispro', $header, true);
        $origin = array_search('Escrita — Origem da apreciação', $header, true);

        $this->assertNotFalse($appreciation, 'O CSV tem de nomear a apreciação de cada domínio.');
        $this->assertNotFalse($proposal);
        $this->assertNotFalse($origin);

        $row = null;
        foreach ($lines as $line) {
            $cells = str_getcsv($line);

            if (($cells[1] ?? null) === 'Eva Salgado') {
                $row = $cells;
            }
        }

        $this->assertNotNull($row, 'O CSV histórico tem de trazer a linha da aluna.');
        // A decisão daquele momento — «3» —, e nunca o «5» de hoje.
        $this->assertSame('3', $row[$appreciation]);
        $this->assertSame('2', $row[$proposal], 'A proposta viaja preservada, ao lado.');
        $this->assertSame('Decisão do professor', $row[$origin]);
    }

    #[Test]
    public function the_historical_xlsx_is_produced_and_never_recalculated(): void
    {
        $teacher = $this->seedDemo();

        $this->decide($teacher, 'Eva Salgado', 'Escrita', '3');
        $this->save($teacher, 'Momento A');
        $this->decide($teacher, 'Eva Salgado', 'Escrita', '5');

        $xlsx = $this->download($teacher, $this->exportOf($teacher, 'Momento A'), 'xlsx');
        $xlsx->assertOk();

        // O que o ficheiro leva é o payload congelado — e é ele que já foi
        // verificado acima. Aqui basta que saia, e que a fotografia continue
        // intacta depois de sair.
        $this->assertNotSame('', $xlsx->getContent());
        $this->assertTrue($this->exportOf($teacher, 'Momento A')->isIntact());
    }

    // ------------------------------- uma fotografia anterior a esta decisão

    #[Test]
    public function a_snapshot_kept_before_domain_decisions_existed_still_opens(): void
    {
        $teacher = $this->seedDemo();

        $this->save($teacher, 'Momento antigo');

        // Uma fotografia v1: sem `decided_*` em domínio nenhum, tal como as que
        // existem em produção. A ausência das chaves É a informação — ninguém
        // se pronunciou —, e o histórico continua a abrir e a dizer o mesmo.
        $this->asTenant($teacher, function () use ($teacher): void {
            $export = EvaluationSheetExport::query()->where('moment_label', 'Momento antigo')->firstOrFail();
            $payload = $export->payload;
            $payload['version'] = 1;

            foreach ($payload['students'] as $index => $student) {
                foreach ($student['domains'] as $domainIndex => $domain) {
                    unset(
                        $payload['students'][$index]['domains'][$domainIndex]['decided_scale_level_id'],
                        $payload['students'][$index]['domains'][$domainIndex]['decided_scale_level_code'],
                        $payload['students'][$index]['domains'][$domainIndex]['decided_scale_level_label'],
                    );
                }
            }

            EvaluationSheetExport::create([
                'class_id' => $export->class_id,
                'academic_period_id' => $export->academic_period_id,
                'scope' => $export->scope,
                'adapter' => 'snapshot',
                'moment_label' => 'Pauta de 0.132.0',
                'effective_at' => '2026-12-10',
                'payload' => $payload,
                'payload_hash' => CanonicalPayload::hash($payload),
                'warning_count' => 0,
                'exported_with_warnings' => false,
                'file_disk' => 'local',
                'exported_by' => $teacher->id,
                'exported_at' => Carbon::parse('2026-12-10 09:00:00'),
            ]);
        });

        [$classUlid] = $this->context($teacher);
        $old = $this->exportOf($teacher, 'Pauta de 0.132.0');

        $this->actingAs($teacher)
            ->get("/classes/{$classUlid}/pauta-avaliacao/historico/{$old->ulid}")
            ->assertOk();

        $this->download($teacher, $old, 'csv')->assertOk();
        $this->download($teacher, $old, 'xlsx')->assertOk();

        $cell = $this->keptCell($old, 'Eva Salgado', 'Escrita');
        $this->assertArrayNotHasKey('decided_scale_level_code', $cell);
        $this->assertSame('2', $cell['scale_level_code']);
    }
}
