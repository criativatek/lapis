<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
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
 * OS MOMENTOS ESTRUTURAIS NO TOPO DA PAUTA.
 *
 * Cada unidade temporal do ano tem dois momentos que a escola reconhece: o
 * intercalar, a meio, e o final, que a fecha. O topo mostra os dois, por essa
 * ordem, e mostra APENAS esses — uma pauta guardada, uma exportação repetida ou
 * um título personalizado são registos e vivem no Histórico, não num separador
 * cada (§20).
 *
 * OS RÓTULOS DERIVAM DA CONFIGURAÇÃO. Uma escola com semestres vê semestres,
 * uma com períodos vê períodos, e uma com módulos vê módulos — sem que uma
 * linha de código saiba o que é um semestre (§19). É isso que o teste dos
 * períodos fixa: a mesma turma, com a configuração temporal trocada, produz os
 * separadores certos sem alterar código nenhum.
 */
class EvaluationSheetMomentsTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

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

    private function classUlid(User $teacher): string
    {
        return $this->asTenant($teacher, fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()->ulid);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function moments(User $teacher, string $query = ''): array
    {
        $classUlid = $this->classUlid($teacher);

        return $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao{$query}"))['periods'];
    }

    // ------------------------------------------------------------- a ordem

    #[Test]
    public function each_temporal_unit_offers_its_interim_moment_immediately_before_its_final_one(): void
    {
        $teacher = $this->seedDemo();

        $moments = $this->moments($teacher);

        // A demonstração tem dois semestres: quatro momentos, nesta ordem.
        $this->assertSame(
            ['Intercalar 1.º Semestre', '1.º Semestre', 'Intercalar 2.º Semestre', '2.º Semestre'],
            array_column($moments, 'label'),
        );

        $this->assertSame(
            ['interim', 'final', 'interim', 'final'],
            array_column($moments, 'moment'),
        );
    }

    #[Test]
    public function the_same_rule_produces_terms_when_the_year_is_configured_with_terms(): void
    {
        $teacher = $this->seedDemo();

        // A MESMA TURMA, com a configuração temporal trocada. Nada no código
        // muda; o que muda é o que a escola configurou.
        $this->asTenant($teacher, function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $periods = AcademicPeriod::query()
                ->where('academic_year_id', $class->academic_year_id)
                ->orderBy('sequence')
                ->get();

            // Os dois que já existem passam a períodos — reescritos, não
            // apagados: têm instrumentos pendurados neles, como na vida real.
            foreach ($periods as $index => $period) {
                $period->fill([
                    'label' => ($index + 1).'.º Período',
                    'kind' => AcademicPeriodKind::Term,
                ])->save();
            }

            AcademicPeriod::query()->create([
                'academic_year_id' => $class->academic_year_id,
                'label' => '3.º Período',
                'kind' => AcademicPeriodKind::Term,
                'sequence' => 3,
                'starts_on' => '2027-06-17',
                'ends_on' => '2027-07-16',
                'status' => 'draft',
            ]);
        });

        $this->assertSame(
            [
                'Intercalar 1.º Período', '1.º Período',
                'Intercalar 2.º Período', '2.º Período',
                'Intercalar 3.º Período', '3.º Período',
            ],
            array_column($this->moments($teacher), 'label'),
        );
    }

    // ---------------------------------------------------------- a navegação

    #[Test]
    public function the_closing_moment_is_what_an_address_without_a_moment_opens(): void
    {
        $teacher = $this->seedDemo();

        $moments = $this->moments($teacher);
        $selected = array_values(array_filter($moments, fn (array $moment): bool => $moment['selected']));

        $this->assertCount(1, $selected);
        $this->assertSame('final', $selected[0]['moment']);
        $this->assertSame('1.º Semestre', $selected[0]['label']);
    }

    #[Test]
    public function asking_for_the_interim_moment_selects_it_and_nothing_else(): void
    {
        $teacher = $this->seedDemo();

        $moments = $this->moments($teacher, '?momento=interim');
        $selected = array_values(array_filter($moments, fn (array $moment): bool => $moment['selected']));

        $this->assertCount(1, $selected);
        $this->assertSame('interim', $selected[0]['moment']);
        $this->assertSame('Intercalar 1.º Semestre', $selected[0]['label']);
    }

    #[Test]
    public function a_moment_nobody_recognises_is_the_closing_one_rather_than_an_error(): void
    {
        $teacher = $this->seedDemo();

        $classUlid = $this->classUlid($teacher);
        $props = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao?momento=inventado"));

        $this->assertSame('final', $props['moment']);
    }

    #[Test]
    public function the_two_moments_read_exactly_the_same_pauta(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $final = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"))['sheet'];
        $interim = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao?momento=interim"))['sheet'];

        // NENHUM NÚMERO MUDA. O que muda entre os dois momentos é o que se está
        // a preparar, nunca o que foi avaliado.
        $this->assertSame($final, $interim);
    }

    // ------------------------------------------------ o que o momento decide

    #[Test]
    public function an_interim_moment_never_closes_the_period_and_a_final_one_closes_it_when_the_dates_say_so(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        // 15/12/2026: o 1.º Semestre corre até 29/01/2027, logo nem o momento
        // final o fecha ainda — o separador existe para se PREPARAR o fecho.
        $running = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"));
        $this->assertFalse($running['readiness']['moment']['is_closing']);

        $interim = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao?momento=interim"));
        $this->assertFalse($interim['readiness']['moment']['is_closing']);
        $this->assertSame('interim', $interim['readiness']['moment']['kind']);

        // Passado o fim do semestre, o momento final fecha-o — e o intercalar
        // continua a não fechar coisa nenhuma, que é a sua definição.
        $this->travelTo(Carbon::parse('2027-02-10 10:00:00'));

        $closed = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"));
        $this->assertTrue($closed['readiness']['moment']['is_closing']);

        $stillInterim = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao?momento=interim"));
        $this->assertFalse($stillInterim['readiness']['moment']['is_closing']);
    }

    #[Test]
    public function the_suggested_title_says_which_moment_is_being_kept(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $final = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"));
        $interim = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao?momento=interim"));

        $this->assertSame('Semestre — 1.º Semestre', $final['saveDefaults']['moment_label']);
        $this->assertSame('Momento intercalar do 1.º Semestre', $interim['saveDefaults']['moment_label']);
    }

    // ---------------------------------------------- guardar, e só o histórico

    #[Test]
    public function a_kept_pauta_records_which_moment_it_was_and_never_becomes_a_tab(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);
        $periodUlid = $this->asTenant($teacher, fn (): string => SchoolClass::where('label', '7.º A')
            ->firstOrFail()->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid);

        $this->actingAs($teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
            'moment_label' => 'Momento intercalar do 1.º Semestre',
            'effective_at' => '2026-12-15',
            'moment' => 'interim',
        ])->assertRedirect();

        $kept = $this->asTenant($teacher, fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        $this->assertSame('interim', $kept->payload['moment']['kind']);
        $this->assertSame(2, $kept->payload['version']);

        // E o topo continua a ter QUATRO separadores: uma fotografia não é um
        // momento estrutural, e guardar mais nunca acrescenta separadores (§20).
        $this->assertCount(4, $this->moments($teacher));
    }

    #[Test]
    public function a_pauta_kept_without_saying_which_moment_defaults_to_the_closing_one(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);
        $periodUlid = $this->asTenant($teacher, fn (): string => SchoolClass::where('label', '7.º A')
            ->firstOrFail()->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid);

        $this->actingAs($teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
            'moment_label' => 'Sem momento indicado',
            'effective_at' => '2026-12-15',
        ])->assertRedirect();

        $kept = $this->asTenant($teacher, fn (): EvaluationSheetExport => EvaluationSheetExport::query()->latest('id')->firstOrFail());

        $this->assertSame(SheetMomentKind::Final->value, $kept->payload['moment']['kind']);
    }

    #[Test]
    public function history_shows_a_kept_moment_kind_and_leaves_older_snapshots_without_one(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);
        $periodUlid = $this->asTenant($teacher, fn (): string => SchoolClass::where('label', '7.º A')
            ->firstOrFail()->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid);

        $this->actingAs($teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
            'moment_label' => 'Momento intercalar',
            'effective_at' => '2026-12-15',
            'moment' => 'interim',
        ]);

        // Uma fotografia v1, como as que existem em produção: sem `kind`
        // nenhum no momento. NÃO lhe é atribuído um — null é «não foi
        // registado», e nunca «final» (§46).
        $this->asTenant($teacher, function () use ($teacher): void {
            $latest = EvaluationSheetExport::query()->latest('id')->firstOrFail();
            $payload = $latest->payload;
            $payload['version'] = 1;
            unset($payload['moment']['kind']);

            EvaluationSheetExport::create([
                'class_id' => $latest->class_id,
                'academic_period_id' => $latest->academic_period_id,
                'scope' => $latest->scope,
                'adapter' => 'snapshot',
                'moment_label' => 'Pauta antiga',
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

        $entries = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao/historico"))['entries'];

        $byLabel = [];
        foreach ($entries as $entry) {
            $byLabel[$entry['moment_label']] = $entry['moment_kind'];
        }

        $this->assertSame('interim', $byLabel['Momento intercalar']);
        $this->assertNull($byLabel['Pauta antiga']);
    }
}
