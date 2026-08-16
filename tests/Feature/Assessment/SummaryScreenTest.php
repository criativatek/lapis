<?php

namespace Tests\Feature\Assessment;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Quadro Síntese: the year of a class, read whole.
 *
 * It adds no arithmetic of its own. Everything on it — the standalone average
 * of each period, the accumulated one, the movement between them, what the
 * student said about each domain and about the period, the proposal and the
 * decision — comes from BuildResultsProgression, in a single call, and is
 * arranged rather than computed. A second opinion about those numbers is the
 * one thing this screen must never have.
 */
class SummaryScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function screen(): string
    {
        return (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/results/Summary.vue')),
        );
    }

    private function seedDemo(): User
    {
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

    private function open(User $teacher, string $classUlid): TestResponse
    {
        return $this->actingAs($teacher)->get("/classes/{$classUlid}/results/quadro-sintese");
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

    // ------------------------------------------------------- 1. navegação

    #[Test]
    public function the_summary_has_its_own_route_and_is_not_mistaken_for_a_period(): void
    {
        $teacher = $this->seedDemo();

        $this->open($teacher, $this->classUlid($teacher))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('results/Summary'));
    }

    #[Test]
    public function the_per_period_screen_offers_it_after_the_real_periods(): void
    {
        $perPeriod = (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/results/Show.vue')),
        );

        // The buttons are built from the periods the year actually has, and this
        // one comes after them — never one of them, and never a fixed list (§2).
        $this->assertStringContainsString('v-for="period in periods"', $perPeriod);
        $this->assertStringContainsString('/results/quadro-sintese', $perPeriod);
        $this->assertLessThan(
            strpos($perPeriod, 'Quadro Síntese'),
            (int) strpos($perPeriod, 'v-for="period in periods"'),
        );
    }

    #[Test]
    public function another_organizations_teacher_cannot_open_it(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $this->actingAs(User::factory()->create())
            ->get("/classes/{$classUlid}/results/quadro-sintese")
            ->assertNotFound();
    }

    // ------------------------------------------- 2. o que a vista recebe

    #[Test]
    public function the_screen_receives_the_read_model_whole_and_untouched(): void
    {
        $teacher = $this->seedDemo();
        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        // Periods and domains come from the configuration — their number and
        // their names are the class's, not this screen's.
        $this->assertNotEmpty($props['progression']['periods']);
        $this->assertNotEmpty($props['progression']['domains']);
        $this->assertCount(6, $props['progression']['students']);

        $student = $props['progression']['students'][0];
        $this->assertCount(count($props['progression']['periods']), $student['periods']);

        $period = $student['periods'][0];

        foreach (['weighted_average', 'accumulated_average', 'evolution', 'domains', 'self_assessment', 'classification'] as $key) {
            $this->assertArrayHasKey($key, $period);
        }

        // Every domain of the profile appears inside every period, so the table
        // can be built without asking for anything else.
        $this->assertCount(count($props['progression']['domains']), $period['domains']);

        foreach (['weighted_average', 'accumulated_average', 'evolution', 'self_assessment'] as $key) {
            $this->assertArrayHasKey($key, $period['domains'][0]);
        }
    }

    #[Test]
    public function the_first_period_has_no_evolution_and_the_later_ones_do(): void
    {
        $teacher = $this->seedDemo();
        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $found = ['up' => false, 'down' => false];

        foreach ($props['progression']['students'] as $student) {
            // Nothing before the first period to compare against, in the whole
            // row and in every domain of it. An absence is not a regression.
            $this->assertNull($student['periods'][0]['evolution']);

            foreach ($student['periods'][0]['domains'] as $domain) {
                $this->assertNull($domain['evolution']);
            }

            $direction = $student['periods'][1]['evolution']['direction'] ?? null;

            if ($direction !== null) {
                $found[$direction] = true;
            }
        }

        // The demo class carries both readings, which is what makes it worth
        // looking at.
        $this->assertTrue($found['up'], 'faltou um aluno em progressão');
        $this->assertTrue($found['down'], 'faltou um aluno em regressão');
    }

    #[Test]
    public function the_decision_of_each_period_is_kept_as_it_was(): void
    {
        $teacher = $this->seedDemo();

        // A proposal in the first period, and a decision on it — so the quadro
        // has a real history to show rather than an empty column.
        $this->asTenant($teacher, function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $first = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($class, $first);

            $decided = Classification::query()
                ->where('academic_period_id', $first->id)
                ->where('scope', ClassificationScope::Period)
                ->firstOrFail();

            app(ConfirmClassification::class)->confirm($decided, $teacher);
        });

        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $seen = ['proposal' => false, 'decision' => false];

        foreach ($props['progression']['students'] as $student) {
            foreach ($student['periods'] as $period) {
                if ($period['classification'] === null) {
                    continue;
                }

                // The proposal read on the scale, on every kind of scale — and
                // the decision beside it, never derived from it.
                $this->assertArrayHasKey('proposal', $period['classification']);
                $this->assertArrayHasKey('final', $period['classification']);
                $this->assertArrayHasKey('is_published', $period['classification']);

                $seen['proposal'] = $seen['proposal'] || $period['classification']['proposal']['value'] !== null;
                $seen['decision'] = $seen['decision'] || $period['classification']['final'] !== null;
            }
        }

        $this->assertTrue($seen['proposal']);
        $this->assertTrue($seen['decision']);
    }

    // -------------------------------------------------------- 3. a tabela

    #[Test]
    public function the_student_stays_put_while_the_year_scrolls_past(): void
    {
        $screen = $this->screen();

        // Sticky first column and a scrolling container — the table is wide on
        // purpose and the name must never leave the screen (§15).
        $this->assertStringContainsString('sticky left-0', $screen);
        $this->assertStringContainsString('overflow-auto', $screen);

        // …and only that one column is frozen: the «Aluno» heading and the
        // «Aluno» cell of each row, and nothing else (§15).
        $frozenLeft = substr_count($screen, 'sticky left-0') + substr_count($screen, 'sticky top-0 left-0');
        $this->assertSame(2, $frozenLeft, 'só a coluna Aluno fica congelada à esquerda');
    }

    #[Test]
    public function the_headers_are_grouped_and_every_abbreviation_says_what_it_means(): void
    {
        $screen = $this->screen();

        // Two levels: the domain (or the period's synthesis) over its columns…
        $this->assertStringContainsString('scope="colgroup"', $screen);
        $this->assertStringContainsString(':colspan="domainColumns"', $screen);
        $this->assertStringContainsString('Síntese · {{ period.label }}', $screen);

        // …and every short label carries the full one, for the pointer and for
        // a screen reader alike (§14).
        foreach (['Evol.', 'Acum.', 'Prop.', 'Autoav.'] as $abbreviation) {
            $this->assertStringContainsString($abbreviation, $screen);
        }

        $this->assertStringContainsString(':aria-label="`Média Ponderada Acumulada — ${domain.name}`"', $screen);
        $this->assertStringContainsString(':aria-label="`Autoavaliação global do aluno — ${period.label}`"', $screen);
        $this->assertStringContainsString(':aria-label="`${decision.label} — ${period.label}`"', $screen);
    }

    #[Test]
    public function the_columns_are_counted_from_the_year_and_never_fixed(): void
    {
        $screen = $this->screen();

        // One column per period, an evolution column after each but the first,
        // and the accumulated closing the block.
        $this->assertStringContainsString('periods.value.length * 2', $screen);
        $this->assertStringContainsString('index === 0 ? 5 : 6', $screen);
        // Nothing here knows how many periods a year has.
        $this->assertStringNotContainsString('P1</th>', $screen);
        $this->assertStringNotContainsString('1.º Período', $screen);
    }

    #[Test]
    public function trend_and_performance_are_drawn_with_different_ink(): void
    {
        $screen = $this->screen();

        // Both read from the canonical modules — no colour is decided here.
        $this->assertStringContainsString("from '@/lib/results'", $screen);
        $this->assertStringContainsString("from '@/lib/qualitativeTone'", $screen);
        $this->assertStringContainsString('qualitativeToneClasses[qualitativeToneFor(level, props.scaleBands)]', $screen);
        $this->assertStringNotContainsString('bg-emerald-50', explode('<template>', $screen)[0]);

        // The decision is the strongest of the three judgements, and the only
        // one in bold.
        $this->assertSame(1, substr_count($screen, 'font-bold'));
    }

    #[Test]
    public function nothing_is_recomputed_in_the_browser(): void
    {
        $screen = $this->screen();

        // No averaging, no summing, no dividing: the read model already did it.
        foreach (['.reduce(', 'Math.round', '/ periods', '* 100'] as $arithmetic) {
            $this->assertStringNotContainsString($arithmetic, $screen);
        }
    }

    // ----------------------------------------------------- 4. performance

    #[Test]
    public function the_page_costs_the_same_whether_the_class_has_six_students_or_nine(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $before = $this->queriesToOpen($teacher, $classUlid);

        $this->asTenant($teacher, function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $organization = $teacher->personalOrganization();

            for ($number = 90; $number < 93; $number++) {
                $student = Student::factory()->recycle($organization)->create();
                StudentIdentity::create([
                    'student_id' => $student->getKey(),
                    'organization_id' => $organization->getKey(),
                    'display_name' => "Aluno de Teste {$number}",
                ]);
                Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->id,
                    'student_id' => $student->getKey(),
                    'class_number' => $number,
                    'enrolled_on' => now()->subMonths(9)->toDateString(),
                ]);
            }
        });

        $after = $this->queriesToOpen($teacher, $classUlid);

        // One call to the read model, and nothing per student: the cost does not
        // track the roll. Three more students would add at least three queries
        // to a page with an N+1 in it, and a real class has thirty (§17).
        $this->assertLessThanOrEqual(
            $before,
            $after,
            "as consultas passaram de {$before} para {$after} ao acrescentar 3 alunos",
        );
    }

    private function queriesToOpen(User $teacher, string $classUlid): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->open($teacher, $classUlid)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
