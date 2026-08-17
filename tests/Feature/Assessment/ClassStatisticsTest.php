<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Estatística agrees with Resultados, or it is worse than useless.
 *
 * A teacher who reads 72,4% on one screen and 71,9% on another stops trusting
 * both. So what these mostly assert is not that a number is right in the
 * abstract — that is BuildResultsProgression's job and it has its own tests —
 * but that this layer says the SAME thing the canonical read model said, having
 * only counted and averaged it.
 *
 * The demo class is deliberately awkward and that is why it is used here: a
 * student absent from the only test of the first period, one whose grid is half
 * marked, and one who enrolled after it. None of them is a zero, and none of
 * them may quietly become one on the way into a mean.
 */
class ClassStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    /**
     * Both of these enter the tenant themselves — runFor restores whatever was
     * there before, so calling them from inside another one is safe and calling
     * them from a bare test body works too.
     */
    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    /**
     * @return array<string, mixed>
     */
    private function statistics(?int $sequence = null): array
    {
        return $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for(
            $this->schoolClass(),
            $sequence === null ? null : $this->period($sequence),
        ));
    }

    // ------------------------------------------------------- 1. o resumo

    #[Test]
    public function a_class_with_no_results_at_all_reports_nothing_rather_than_zero(): void
    {
        $this->asTenant(function (): void {
            // Every score gone: nobody has evidence anywhere.
            StudentItemScore::query()->delete();
        });

        $summary = $this->statistics(1)['summary'];

        $this->assertSame(6, $summary['students_total']);
        $this->assertSame(0, $summary['students_with_result']);
        $this->assertSame(6, $summary['students_without_result']);
        // NOT «0%». A class with no results has no average (§37).
        $this->assertNull($summary['class_average']);
        $this->assertNull($summary['accumulated_average']);
        $this->assertNull($summary['most_common_band']);
    }

    #[Test]
    public function a_class_with_no_students_reports_an_empty_shape(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $enrollments = DB::table('enrollments')->where('class_id', $class->id)->pluck('id');

            // Everything that points at an enrolment goes first — the foreign
            // keys are restrictOnDelete on purpose.
            DB::table('student_item_scores')->whereIn('enrollment_id', $enrollments)->delete();
            DB::table('self_assessments')->whereIn('enrollment_id', $enrollments)->delete();
            DB::table('classifications')->whereIn('enrollment_id', $enrollments)->delete();
            DB::table('enrollments')->whereIn('id', $enrollments)->delete();
        });

        $statistics = $this->statistics(1);

        $this->assertSame(0, $statistics['summary']['students_total']);
        $this->assertNull($statistics['summary']['class_average']);
        $this->assertSame([], $statistics['students']);
    }

    #[Test]
    public function the_class_average_is_the_mean_of_the_students_own_canonical_averages(): void
    {
        $statistics = $this->statistics(1);
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));

        $period = $this->period(1)->id;
        $values = [];

        foreach ($progression['students'] as $student) {
            foreach ($student['periods'] as $row) {
                if ($row['period_id'] === $period && $row['weighted_average'] !== null) {
                    $values[] = (float) $row['weighted_average'];
                }
            }
        }

        $this->assertNotEmpty($values);
        // The same figures Resultados shows, averaged — not recomputed.
        $this->assertSame(
            round(array_sum($values) / count($values), 1),
            (float) $statistics['summary']['class_average'],
        );
        $this->assertSame(count($values), $statistics['summary']['students_with_result']);
    }

    #[Test]
    public function a_student_without_evidence_never_enters_the_mean(): void
    {
        // Diogo was absent from the only test of the first period; Filipe
        // enrolled after it. Neither has a result, and a mean that counted them
        // as zero would report a class doing worse than it is (§37).
        $statistics = $this->statistics(1);

        $this->assertSame(6, $statistics['summary']['students_total']);
        $this->assertSame(4, $statistics['summary']['students_with_result']);
        $this->assertSame(2, $statistics['summary']['students_without_result']);

        $withoutResult = array_values(array_filter(
            $statistics['students'],
            fn (array $student): bool => $student['weighted_average'] === null,
        ));

        $this->assertCount(2, $withoutResult);
        $this->assertSame(['Diogo Ferreira', 'Filipe Andrade'], array_column($withoutResult, 'name'));
    }

    #[Test]
    public function partial_coverage_is_counted_and_never_hidden(): void
    {
        $statistics = $this->statistics(1);

        // A result built on part of what was expected. It is a result, and it is
        // flagged rather than quietly presented as complete (§24).
        $this->assertGreaterThan(0, $statistics['summary']['partial_coverage_count']);

        $flagged = array_values(array_filter(
            $statistics['students'],
            fn (array $student): bool => $student['coverage_warning'] === true
                && $student['weighted_average'] !== null,
        ));

        $this->assertSame($statistics['summary']['partial_coverage_count'], count($flagged));
    }

    #[Test]
    public function a_student_with_no_result_is_never_counted_as_having_a_partial_one(): void
    {
        // THE ENGINE RAISES ONE FLAG FOR TWO DIFFERENT THINGS: a result built on
        // part of the evidence, and no result at all because nothing could
        // produce one. Counting both would report a class that has not been
        // assessed as one whose every result is partial.
        $statistics = $this->statistics(1);

        $withoutResult = array_values(array_filter(
            $statistics['students'],
            fn (array $student): bool => $student['weighted_average'] === null,
        ));

        $this->assertNotEmpty($withoutResult);

        foreach ($withoutResult as $student) {
            // The flag is theirs and stays theirs — it just does not mean partial.
            $this->assertTrue($student['coverage_warning']);
        }

        $this->assertLessThanOrEqual(
            $statistics['summary']['students_with_result'],
            $statistics['summary']['partial_coverage_count'],
            'não pode haver mais resultados parciais do que resultados',
        );
    }

    #[Test]
    public function a_domain_nobody_has_evidence_in_reports_no_partial_results(): void
    {
        $statistics = $this->statistics(1);

        // The first period's only instrument does not touch every domain. The
        // ones it misses have no results — not results with a problem.
        $untouched = array_values(array_filter(
            $statistics['domain_statistics'],
            fn (array $domain): bool => $domain['students_with_result'] === 0,
        ));

        $this->assertNotEmpty($untouched);

        foreach ($untouched as $domain) {
            $this->assertSame(0, $domain['partial_coverage_count'], "«{$domain['label']}» não tem resultados para serem parciais");
        }

        foreach ($statistics['domain_statistics'] as $domain) {
            $this->assertLessThanOrEqual($domain['students_with_result'], $domain['partial_coverage_count']);
        }
    }

    // -------------------------------------------------- 2. a distribuição

    #[Test]
    public function the_distribution_follows_the_scale_and_is_not_a_hardcoded_one_to_five(): void
    {
        $statistics = $this->statistics(1);
        $levels = $this->asTenant(
            fn () => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->levels()->orderBy('sequence')->get(),
        );

        $this->assertCount($levels->count(), $statistics['distribution']);
        $this->assertSame(
            $levels->pluck('label')->all(),
            array_column($statistics['distribution'], 'label'),
        );
        $this->assertSame(
            $levels->pluck('sequence')->all(),
            array_column($statistics['distribution'], 'sequence'),
        );
    }

    #[Test]
    public function a_band_nobody_is_in_still_appears_with_zero(): void
    {
        $statistics = $this->statistics(1);
        $empty = array_values(array_filter(
            $statistics['distribution'],
            fn (array $band): bool => $band['count'] === 0,
        ));

        // Not an accident of this data — a level with nobody in it is a fact
        // about the class, and dropping it would redraw the axis per class.
        $this->assertNotEmpty($empty);

        foreach ($empty as $band) {
            $this->assertSame('0.0', $band['percentage']);
        }
    }

    #[Test]
    public function the_distribution_counts_and_shares_agree_with_each_other(): void
    {
        $statistics = $this->statistics(1);
        $placed = array_sum(array_column($statistics['distribution'], 'count'));

        $this->assertGreaterThan(0, $placed);

        $shares = array_map(
            fn (array $band): float => (float) $band['percentage'],
            $statistics['distribution'],
        );

        // Rounded to one decimal each, so the total is allowed to miss 100 by a
        // rounding step — but not by more.
        $this->assertEqualsWithDelta(100.0, array_sum($shares), 0.5);
    }

    #[Test]
    public function renaming_a_band_does_not_change_where_anybody_falls(): void
    {
        $before = $this->statistics(1)['distribution'];

        $this->asTenant(function (): void {
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()
                ->levels()->where('code', '4')->update(['label' => 'Desempenho Bom']);
        });

        $after = $this->statistics(1)['distribution'];

        // Same counts, in the same order, under a new name: the band is placed
        // by its structure, never by the words on it.
        $this->assertSame(array_column($before, 'count'), array_column($after, 'count'));
        $this->assertContains('Desempenho Bom', array_column($after, 'label'));
    }

    // ---------------------------------------------------- 3. a evolução

    #[Test]
    public function the_first_period_has_nothing_to_compare_against(): void
    {
        $statistics = $this->statistics(1);

        $this->assertNull($statistics['previous_period']);
        $this->assertSame(6, $statistics['evolution']['no_comparison']);
        $this->assertSame(0, $statistics['evolution']['progressed']);
        $this->assertSame(0, $statistics['evolution']['regressed']);
        $this->assertSame(0, $statistics['evolution']['stable']);
        // «Sem comparação» is not «manteve-se», and no average can be taken of it.
        $this->assertNull($statistics['evolution']['average_change']);
    }

    #[Test]
    public function movement_is_read_from_the_progression_and_counted_by_direction(): void
    {
        $statistics = $this->statistics(2);
        $evolution = $statistics['evolution'];

        $this->assertNotNull($statistics['previous_period']);
        $this->assertSame($this->period(1)->id, $statistics['previous_period']['id']);

        $counted = $evolution['progressed'] + $evolution['stable'] + $evolution['regressed'] + $evolution['no_comparison'];

        $this->assertSame($statistics['summary']['students_total'], $counted);
        $this->assertSame($evolution['comparable'], $evolution['progressed'] + $evolution['stable'] + $evolution['regressed']);

        // Every direction agrees with the one the read model itself decided.
        foreach ($statistics['students'] as $student) {
            $this->assertArrayHasKey('evolution', $student);
        }
    }

    #[Test]
    public function a_student_with_no_previous_result_is_not_counted_as_standing_still(): void
    {
        $statistics = $this->statistics(2);

        // Diogo and Filipe have a second-period result and no first-period one.
        // Their movement is unknown, not flat.
        $this->assertGreaterThanOrEqual(2, $statistics['evolution']['no_comparison']);

        $withoutComparison = array_values(array_filter(
            $statistics['students'],
            fn (array $student): bool => $student['evolution'] === null,
        ));

        $this->assertSame($statistics['evolution']['no_comparison'], count($withoutComparison));
    }

    #[Test]
    public function the_average_change_is_taken_only_over_students_who_could_be_compared(): void
    {
        $statistics = $this->statistics(2);
        $changes = [];

        foreach ($statistics['students'] as $student) {
            if ($student['evolution'] !== null) {
                $changes[] = (float) $student['evolution']['points'];
            }
        }

        $this->assertNotEmpty($changes);
        $this->assertSame(count($changes), $statistics['evolution']['comparable']);
        $this->assertSame(
            round(array_sum($changes) / count($changes), 1),
            (float) $statistics['evolution']['average_change'],
        );
    }

    #[Test]
    public function the_comparison_is_against_the_previous_period_standalone(): void
    {
        $statistics = $this->statistics(2);
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));

        foreach ($progression['students'] as $student) {
            $second = collect($student['periods'])->firstWhere('period_id', $this->period(2)->id);
            $first = collect($student['periods'])->firstWhere('period_id', $this->period(1)->id);

            if ($second['evolution'] === null) {
                continue;
            }

            // The read model compares standalone against standalone. Accumulated
            // would carry the first period inside the second and report movement
            // that did not happen (§10).
            $this->assertSame($first['weighted_average'], $second['evolution']['previous'] === null ? null : $first['weighted_average']);
            $this->assertEqualsWithDelta(
                round((float) $first['weighted_average'], 1),
                (float) $second['evolution']['previous'],
                0.05,
            );
        }

        $this->assertGreaterThan(0, $statistics['evolution']['comparable']);
    }

    // ----------------------------------------------------- 4. os domínios

    #[Test]
    public function every_domain_of_the_profile_is_reported_even_without_data(): void
    {
        $statistics = $this->statistics(1);
        $names = array_column($statistics['domain_statistics'], 'label');

        $this->assertSame(array_column($statistics['domains'], 'name'), $names);

        // The first period's only instrument touches three of the five domains.
        // The other two are reported with no average — never with a zero.
        $withoutAverage = array_values(array_filter(
            $statistics['domain_statistics'],
            fn (array $domain): bool => $domain['period_average'] === null,
        ));

        $this->assertNotEmpty($withoutAverage);

        foreach ($withoutAverage as $domain) {
            $this->assertSame(0, $domain['students_with_result']);
            $this->assertNull($domain['qualitative_band']);
        }
    }

    #[Test]
    public function a_domain_average_matches_the_students_own_domain_figures(): void
    {
        $statistics = $this->statistics(1);
        $leitura = $this->asTenant(fn (): Domain => Domain::where('code', 'LEITURA')->firstOrFail());

        $row = collect($statistics['domain_statistics'])->firstWhere('domain_id', $leitura->id);
        $this->assertNotNull($row['period_average']);

        $values = [];

        foreach ($statistics['students'] as $student) {
            $cell = collect($student['domains'])->firstWhere('domain_id', $leitura->id);

            if ($cell !== null && $cell['weighted_average'] !== null) {
                $values[] = (float) $cell['weighted_average'];
            }
        }

        $this->assertSame(count($values), $row['students_with_result']);
        $this->assertSame(
            round(array_sum($values) / count($values), 1),
            (float) $row['period_average'],
        );
    }

    #[Test]
    public function the_domains_keep_the_profiles_own_order(): void
    {
        $statistics = $this->statistics(1);

        // The profile's order, and only that. Sorting by result would move a
        // domain around the chart whenever the class moved, which is not what an
        // axis is for (§14) — and would make two periods incomparable at a
        // glance.
        $this->assertSame(
            array_column($statistics['domains'], 'id'),
            array_column($statistics['domain_statistics'], 'domain_id'),
        );
        $this->assertSame(
            array_column($statistics['domains'], 'name'),
            array_column($statistics['domain_statistics'], 'label'),
        );
    }

    // ------------------------------------------------ 5. a série temporal

    #[Test]
    public function the_period_series_covers_every_real_period_of_the_year(): void
    {
        $statistics = $this->statistics(1);
        $periods = $this->asTenant(fn () => $this->schoolClass()->academicYear->periods()->orderBy('sequence')->get());

        $this->assertCount($periods->count(), $statistics['period_series']);
        $this->assertSame($periods->pluck('label')->all(), array_column($statistics['period_series'], 'label'));

        // Two semesters here, three periods elsewhere — nothing about the shape
        // of the year is written down (§4).
        foreach ($statistics['period_series'] as $row) {
            $this->assertCount(count($statistics['domains']), $row['domains']);
        }
    }

    #[Test]
    public function the_series_uses_standalone_figures_and_not_accumulated_ones(): void
    {
        $statistics = $this->statistics(2);
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));

        foreach ($statistics['period_series'] as $row) {
            $values = [];

            foreach ($progression['students'] as $student) {
                $entry = collect($student['periods'])->firstWhere('period_id', $row['period_id']);

                if ($entry['weighted_average'] !== null) {
                    $values[] = (float) $entry['weighted_average'];
                }
            }

            $expected = $values === [] ? null : round(array_sum($values) / count($values), 1);

            $this->assertSame(
                $expected,
                $row['class_average'] === null ? null : (float) $row['class_average'],
                "a série do período {$row['label']} não é a média standalone",
            );
        }
    }

    // -------------------------------------------------------- 6. o aluno

    #[Test]
    public function each_student_carries_the_reading_the_progression_gave_them(): void
    {
        $statistics = $this->statistics(2);
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));

        $this->assertCount(count($progression['students']), $statistics['students']);

        foreach ($statistics['students'] as $index => $student) {
            $source = collect($progression['students'][$index]['periods'])
                ->firstWhere('period_id', $this->period(2)->id);

            $this->assertSame($progression['students'][$index]['name'], $student['name']);
            $this->assertSame($source['weighted_average'], $student['weighted_average']);
            $this->assertSame($source['accumulated_average'], $student['accumulated_average']);
            $this->assertSame($source['evolution'], $student['evolution']);
            $this->assertSame($source['self_assessment'], $student['self_assessment']);
            $this->assertSame($source['classification'], $student['classification']);
            $this->assertSame($source['domains'], $student['domains']);
        }
    }

    #[Test]
    public function a_students_band_comes_from_their_accumulated_average(): void
    {
        $statistics = $this->statistics(1);

        foreach ($statistics['students'] as $student) {
            if ($student['accumulated_average'] === null) {
                $this->assertNull($student['band'], 'sem acumulado não há menção');

                continue;
            }

            $this->assertNotNull($student['band']);
            // The same band the Quadro Síntese places on the same figure.
            $this->assertArrayHasKey('scale_level_id', $student['band']);
            $this->assertArrayHasKey('code', $student['band']);
        }
    }

    // -------------------------------------------------- 7. o período certo

    #[Test]
    public function without_a_period_it_opens_on_the_latest_one_that_has_results(): void
    {
        $statistics = $this->statistics();

        // Both semesters have results in the demo class, so the second is where
        // a teacher is working.
        $this->assertSame($this->period(2)->id, $statistics['selected_period']['id']);
    }

    #[Test]
    public function a_year_with_no_results_anywhere_opens_on_its_first_period(): void
    {
        $this->asTenant(fn () => StudentItemScore::query()->delete());

        $statistics = $this->statistics();

        $this->assertSame($this->period(1)->id, $statistics['selected_period']['id']);
    }

    // ------------------------------------------------------ 8. desempenho

    #[Test]
    public function the_query_count_does_not_grow_with_the_class(): void
    {
        $baseline = $this->countQueries();

        // Ten more students, each their own person, each with their own scores
        // on the same instruments.
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $template = $class->enrollments()->orderBy('class_number')->first();
            $scores = StudentItemScore::where('enrollment_id', $template->id)->get();

            for ($number = 7; $number <= 16; $number++) {
                $student = app(StudentEnrollmentService::class)->enrollNew($class, [
                    'name' => "Aluno de Carga {$number}",
                    'class_number' => $number,
                    'enrolled_on' => '2026-09-14',
                ]);

                foreach ($scores as $score) {
                    $copy = $score->replicate(['id']);
                    $copy->enrollment_id = $student->id;
                    $copy->save();
                }
            }
        });

        $grown = $this->countQueries();

        $this->assertSame(16, $this->statistics(1)['summary']['students_total']);
        // FLAT, not merely sublinear. The read model is one call whose queries
        // are per period and per scope, never per student — so ten more students
        // buy no more queries at all. Two of slack, and no more, so that a query
        // slipped inside a student loop fails here rather than in a school.
        $this->assertLessThanOrEqual(
            $baseline + 2,
            $grown,
            "o número de queries cresceu de {$baseline} para {$grown} ao juntar 10 alunos",
        );
    }

    private function countQueries(): int
    {
        $count = 0;

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->statistics(1);

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    // ------------------------------------------------------- 9. a página

    #[Test]
    public function the_page_renders_with_the_datasets_it_needs(): void
    {
        $class = $this->asTenant(fn (): SchoolClass => $this->schoolClass());

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('results/Statistics')
                ->has('statistics.summary')
                ->has('statistics.evolution')
                ->has('statistics.distribution')
                ->has('statistics.domain_statistics')
                ->has('statistics.period_series')
                ->has('statistics.students')
                ->has('statistics.scale')
                ->where('schoolClass.label', '7.º A'),
            );
    }

    #[Test]
    public function a_period_can_be_chosen_from_the_url(): void
    {
        $class = $this->asTenant(fn (): SchoolClass => $this->schoolClass());
        $first = $this->asTenant(fn (): AcademicPeriod => $this->period(1));

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica/{$first->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statistics.selected_period.id', $first->id));
    }

    #[Test]
    public function a_teacher_from_another_organization_cannot_read_this_class(): void
    {
        $class = $this->asTenant(fn (): SchoolClass => $this->schoolClass());
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/results/estatistica")
            ->assertNotFound();
    }
}
