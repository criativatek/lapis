<?php

namespace Tests\Feature\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\OpenClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\StudentEnrollmentService;
use App\Support\Assessment\AssessmentCutoff;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /**
     * First name → enrolment id, read once from the read model's own names.
     *
     * @var array<string, int>|null
     */
    private ?array $enrollmentIds = null;

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

    // ------------------------------------------------ 10. a taxa de sucesso

    /**
     * The teacher's own decision, taken the way the decision screen takes it.
     *
     * Opened through OpenClassification — so a student the engine reached no
     * result for can still be graded, exactly as in the application — and then
     * confirmed through the one service that confirms one. Nothing here writes
     * a classification row by hand, so what the tests exercise is the real
     * domain rule about when a decision exists (§8).
     */
    private function assign(string $firstName, int $sequence, string $code): void
    {
        $this->asTenant(function () use ($firstName, $sequence, $code): void {
            $class = $this->schoolClass();
            $enrollment = $class->enrollments()->findOrFail($this->enrollmentIdOf($firstName));

            $classification = app(OpenClassification::class)->forDecision(
                $class,
                $this->period($sequence),
                $enrollment,
                ClassificationScope::Period,
            );

            $level = $class->profileVersion->scale->levels->firstWhere('code', $code);

            app(ConfirmClassification::class)->confirm($classification, $this->teacher, $level->id);
        });
    }

    /**
     * The scale's own statement about a band, changed without touching a grade.
     *
     * What makes a decision a pass is a flag on the scale, so moving that flag
     * has to move the rate — and nothing else may.
     */
    private function markBandAsNegative(string $code, bool $negative = true): void
    {
        $this->asTenant(fn () => Scale::withoutGlobalScope('scaleVisibility')
            ->where('name', 'Escala 1 a 5')->firstOrFail()
            ->levels()->where('code', $code)
            ->update(['is_negative' => $negative]));
    }

    /** Everything one student ever answered, gone — not zeroed, gone. */
    private function eraseTheScoresOf(string $firstName): void
    {
        $enrollmentId = $this->enrollmentIdOf($firstName);

        $this->asTenant(fn () => StudentItemScore::where('enrollment_id', $enrollmentId)->delete());
    }

    /** A decision written as a bare number, which is what a numeric scale takes. */
    private function assignValue(string $firstName, int $sequence, string $value): void
    {
        $this->asTenant(function () use ($firstName, $sequence, $value): void {
            $class = $this->schoolClass();
            $enrollment = $class->enrollments()->findOrFail($this->enrollmentIdOf($firstName));

            $classification = app(OpenClassification::class)->forDecision(
                $class,
                $this->period($sequence),
                $enrollment,
                ClassificationScope::Period,
            );

            app(ConfirmClassification::class)->confirm($classification, $this->teacher, null, $value);
        });
    }

    /**
     * A scale that has nothing to do with the system ones.
     *
     * Two bands and a line at 60 — the sort of thing a school actually writes
     * for a domain-based profile. Nothing in the read model knows about it.
     */
    private function useATwoBandScale(): void
    {
        $this->asTenant(function (): void {
            $scale = Scale::create([
                'name' => 'Atingiu / Não atingiu', 'kind' => 'level', 'min_value' => 0, 'max_value' => 1,
            ]);

            $scale->levels()->createMany([
                [
                    'code' => 'NA', 'label' => 'Não atingiu', 'sequence' => 1, 'is_negative' => true,
                    'band_min_normalized' => '0.000000', 'band_max_normalized' => '59.999999',
                ],
                [
                    'code' => 'A', 'label' => 'Atingiu', 'sequence' => 2, 'is_negative' => false,
                    'band_min_normalized' => '60.000000', 'band_max_normalized' => '100.000000',
                ],
            ]);

            DB::table('assessment_profile_versions')
                ->where('id', $this->schoolClass()->profileVersion->id)
                ->update(['scale_id' => $scale->id]);
        });
    }

    /** Moves the class onto «Escala 0 a 20», with or without qualitative bands. */
    private function useTheNumericScale(bool $withBands = false): void
    {
        $this->asTenant(function () use ($withBands): void {
            $scale = Scale::withoutGlobalScope('scaleVisibility')
                ->where('name', 'Escala 0 a 20')->firstOrFail();

            if ($withBands) {
                $scale->levels()->createMany([
                    [
                        'code' => 'N', 'label' => 'Não satisfaz', 'sequence' => 1, 'is_negative' => true,
                        'band_min_normalized' => '0.000000', 'band_max_normalized' => '47.499999',
                    ],
                    [
                        'code' => 'S', 'label' => 'Satisfaz', 'sequence' => 2, 'is_negative' => false,
                        'band_min_normalized' => '47.500000', 'band_max_normalized' => '100.000000',
                    ],
                ]);
            }

            DB::table('assessment_profile_versions')
                ->where('id', $this->schoolClass()->profileVersion->id)
                ->update(['scale_id' => $scale->id]);
        });
    }

    /** A proposal and no decision: the system has spoken, the teacher has not. */
    private function proposeOnly(int $sequence): void
    {
        $this->asTenant(fn () => app(ProposeClassifications::class)
            ->forPeriod($this->schoolClass(), $this->period($sequence)));
    }

    private function enrollmentIdOf(string $firstName): int
    {
        if ($this->enrollmentIds === null) {
            $this->enrollmentIds = [];

            foreach ($this->statistics(2)['students'] as $student) {
                $this->enrollmentIds[explode(' ', (string) $student['name'])[0]] = (int) $student['enrollment_id'];
            }
        }

        return $this->enrollmentIds[$firstName];
    }

    /**
     * The whole class graded, so the rate has something to count.
     *
     * @param  array<string, string>  $levels
     */
    private function assignAll(int $sequence, array $levels): void
    {
        foreach ($levels as $firstName => $code) {
            $this->assign($firstName, $sequence, $code);
        }
    }

    #[Test]
    public function the_success_rate_counts_the_classifications_the_teacher_assigned(): void
    {
        $this->assignAll(2, [
            'Ana' => '4', 'Bruno' => '2', 'Carolina' => '5',
            'Diogo' => '4', 'Eva' => '3', 'Filipe' => '1',
        ]);

        $success = $this->statistics(2)['summary']['success'];

        // «2» and «1» are the negative bands of this scale; the other four are not.
        $this->assertSame(4, $success['succeeded']);
        $this->assertSame(2, $success['failed']);
        $this->assertSame(6, $success['placed']);
        $this->assertSame('66.7', $success['rate']);
    }

    #[Test]
    public function a_calculated_mention_never_stands_in_for_a_grade(): void
    {
        // Ana's accumulated average lands in «Bom». Her teacher wrote «2».
        $this->assign('Ana', 2, '2');
        $this->assign('Bruno', 2, '3');

        $statistics = $this->statistics(2);

        $this->assertFalse($statistics['students'][0]['band']['is_negative'], 'a média continua positiva');
        $this->assertSame(1, $statistics['summary']['success']['failed'], 'e a classificação atribuída é negativa');
        $this->assertSame('50.0', $statistics['summary']['success']['rate']);
    }

    #[Test]
    public function a_low_average_with_a_positive_grade_counts_as_a_pass(): void
    {
        // Eva's own figure is the lowest of the class; her teacher decided «4».
        $this->markBandAsNegative('3');
        $this->assign('Eva', 2, '4');

        $success = $this->statistics(2)['summary']['success'];

        $this->assertSame(1, $success['succeeded']);
        $this->assertSame(0, $success['failed']);
    }

    #[Test]
    public function a_proposal_is_not_a_decision(): void
    {
        // Every student has a proposal on screen and nobody has been graded.
        $this->proposeOnly(2);

        $success = $this->statistics(2)['summary']['success'];

        $this->assertSame(6, $success['without_classification']);
        $this->assertSame(0, $success['placed']);
        $this->assertNull($success['rate'], 'o sistema propôs; o professor não decidiu');
    }

    #[Test]
    public function the_proposal_is_never_read_as_the_grade_when_the_teacher_decided_otherwise(): void
    {
        // LÁPIS proposed «4» for Ana. The teacher assigned «2».
        $this->proposeOnly(2);
        $this->assign('Ana', 2, '2');

        $statistics = $this->statistics(2);
        $classification = $this->student('Ana')['classification'];

        $this->assertSame('4', $classification['proposed']['code']);
        $this->assertSame('2', $classification['final']['code']);
        $this->assertSame(1, $statistics['summary']['success']['failed']);
        $this->assertSame(0, $statistics['summary']['success']['succeeded']);
    }

    #[Test]
    public function a_self_assessment_is_not_a_grade_either(): void
    {
        $this->assign('Ana', 2, '2');

        $statistics = $this->statistics(2);

        // Whatever the student said about themselves, the count follows the
        // teacher's decision — four different statements, kept apart (§14).
        $this->assertSame(1, $statistics['summary']['success']['failed']);
        $this->assertSame(0, $statistics['summary']['success']['succeeded']);
    }

    #[Test]
    public function the_passing_line_is_the_scales_own_flag_and_never_a_threshold_written_here(): void
    {
        $this->assignAll(2, [
            'Ana' => '4', 'Bruno' => '3', 'Carolina' => '5',
            'Diogo' => '4', 'Eva' => '3', 'Filipe' => '3',
        ]);

        $before = $this->statistics(2)['summary']['success'];

        // «Suficiente» is now a negative band, as far as this scale is
        // concerned. Not one grade, score or weight changed.
        $this->markBandAsNegative('3');

        $after = $this->statistics(2)['summary']['success'];

        $this->assertSame('100.0', $before['rate']);
        $this->assertSame(3, $after['failed'], 'os tres alunos com «Suficiente» passam a insucesso');
        $this->assertSame('50.0', $after['rate']);
    }

    #[Test]
    public function a_scale_whose_passing_line_sits_elsewhere_gives_a_different_rate(): void
    {
        $this->assignAll(2, [
            'Ana' => '4', 'Bruno' => '3', 'Carolina' => '5',
            'Diogo' => '4', 'Eva' => '3', 'Filipe' => '3',
        ]);

        // A school that only counts «Muito Bom» as success. If anything here
        // were hard-coded to «>= 3» or «>= 50%», this could not move.
        $this->markBandAsNegative('3');
        $this->markBandAsNegative('4');

        $success = $this->statistics(2)['summary']['success'];

        $this->assertSame(1, $success['succeeded']);
        $this->assertSame(5, $success['failed']);
        $this->assertSame('16.7', $success['rate']);
    }

    #[Test]
    public function a_student_without_an_assigned_classification_is_not_counted_as_a_failure(): void
    {
        $this->assign('Ana', 2, '4');

        $success = $this->statistics(2)['summary']['success'];

        $this->assertSame(5, $success['without_classification']);
        $this->assertSame(0, $success['failed'], 'não ter sido classificado não é uma negativa');
    }

    #[Test]
    public function students_without_an_assigned_classification_stay_out_of_the_denominator(): void
    {
        $this->assign('Ana', 2, '4');
        $this->assign('Bruno', 2, '3');

        $success = $this->statistics(2)['summary']['success'];

        $this->assertSame(2, $success['placed'], 'o denominador são os classificados, não a turma');
        $this->assertSame('100.0', $success['rate']);
    }

    #[Test]
    public function the_denominator_is_exactly_the_students_with_a_grade_the_scale_can_read(): void
    {
        $this->assignAll(2, ['Ana' => '4', 'Bruno' => '2', 'Carolina' => '5']);

        $success = $this->statistics(2)['summary']['success'];

        $this->assertSame(
            $success['succeeded'] + $success['failed'],
            $success['placed'],
            'nao ha terceiro grupo escondido dentro do denominador',
        );
    }

    #[Test]
    public function with_nobody_classified_the_rate_is_absent_rather_than_zero(): void
    {
        $success = $this->statistics(2)['summary']['success'];

        $this->assertSame(0, $success['placed']);
        $this->assertNull($success['rate'], 'nao sabemos nao se escreve 0%');
        $this->assertNull($success['failure_rate']);
        $this->assertSame(6, $success['without_classification']);
    }

    #[Test]
    public function the_three_groups_account_for_every_student_in_the_class(): void
    {
        $this->assignAll(2, ['Ana' => '4', 'Bruno' => '2']);

        $statistics = $this->statistics(2);
        $success = $statistics['summary']['success'];

        $this->assertSame(
            $statistics['summary']['students_total'],
            $success['succeeded'] + $success['failed'] + $success['unplaced'] + $success['without_classification'],
            'nenhum aluno desaparece nem e contado duas vezes',
        );
    }

    #[Test]
    public function the_success_and_failure_rates_complete_each_other(): void
    {
        $this->assignAll(2, ['Ana' => '4', 'Bruno' => '2', 'Carolina' => '5']);

        $success = $this->statistics(2)['summary']['success'];

        $this->assertSame(
            '100.0',
            Bc::round(Bc::add(Bc::of($success['rate']), Bc::of($success['failure_rate'])), 1, 'half_up'),
        );
    }

    #[Test]
    public function each_domain_reports_its_own_success_from_the_calculated_mention(): void
    {
        // A domain has no assigned classification — a decision is taken for the
        // period, not per domain — so this reading stays what it always was and
        // the screen says which figure it is about (§13).
        $domains = collect($this->statistics(2)['domain_statistics'])->keyBy('label');

        $this->assertSame(6, $domains['Escrita']['succeeded']);
        $this->assertSame(6, $domains['Escrita']['placed']);
        $this->assertSame('100.0', $domains['Escrita']['success_rate']);
    }

    #[Test]
    public function a_domain_success_rate_follows_the_scale_too(): void
    {
        $this->markBandAsNegative('3');

        $domains = collect($this->statistics(2)['domain_statistics'])->keyBy('label');

        $this->assertLessThan(
            6,
            $domains['Escrita']['succeeded'],
            'mover a linha da escala tem de mover tambem o sucesso por dominio',
        );
        $this->assertSame(6, $domains['Escrita']['placed'], 'o denominador do domínio não muda');
    }

    #[Test]
    public function a_domain_nobody_is_placed_in_has_no_rate(): void
    {
        $domains = collect($this->statistics(2)['domain_statistics'])->keyBy('label');

        // Nothing has been assessed in this domain in the demo class.
        $this->assertSame(0, $domains['Educação Literária']['placed']);
        $this->assertNull($domains['Educação Literária']['success_rate']);
    }

    #[Test]
    public function the_page_carries_the_success_figures(): void
    {
        $this->assign('Ana', 2, '4');
        $class = $this->asTenant(fn (): SchoolClass => $this->schoolClass());

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('statistics.summary.success.rate')
                ->has('statistics.summary.success.placed')
                ->has('statistics.summary.success.without_classification')
                ->where('statistics.summary.success.succeeded', 1),
            );
    }

    // -------------------------------------------- 11. o percurso do aluno

    /**
     * @return array<string, mixed>
     */
    private function student(string $firstName, ?int $sequence = 2): array
    {
        $student = collect($this->statistics($sequence)['students'])
            ->first(fn (array $row): bool => str_starts_with($row['name'], $firstName));

        $this->assertNotNull($student, "nao ha aluno {$firstName} na turma");

        return $student;
    }

    #[Test]
    public function a_students_series_has_one_entry_for_every_period_of_the_year(): void
    {
        $periods = $this->asTenant(fn (): int => $this->schoolClass()->academicYear->periods()->count());

        foreach ($this->statistics(2)['students'] as $student) {
            $this->assertCount($periods, $student['series'], "{$student['name']} tem o ano todo");
        }
    }

    #[Test]
    public function the_series_repeats_what_the_progression_said_and_computes_nothing(): void
    {
        $statistics = $this->statistics(2);
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));

        foreach ($statistics['students'] as $index => $student) {
            foreach ($student['series'] as $moment => $entry) {
                $source = $progression['students'][$index]['periods'][$moment];

                $this->assertSame($source['period_id'], $entry['period_id']);
                $this->assertSame($source['period_label'], $entry['period_label']);
                $this->assertSame($source['weighted_average'], $entry['weighted_average']);
                $this->assertSame($source['accumulated_average'], $entry['accumulated_average']);
                $this->assertSame($source['evolution'], $entry['evolution']);
            }
        }
    }

    #[Test]
    public function a_period_without_a_result_appears_as_a_blank_rather_than_a_zero(): void
    {
        // Diogo enrolled after the first period. It is empty for him, and empty
        // is not zero (§13.3).
        $first = $this->student('Diogo')['series'][0];

        $this->assertNull($first['weighted_average']);
    }

    #[Test]
    public function a_student_who_enrolled_late_still_has_the_earlier_periods_laid_out(): void
    {
        $series = $this->student('Diogo')['series'];

        // The period exists in their line — named, and empty. Hiding it would
        // make the year look shorter than it was.
        $this->assertNotEmpty($series[0]['period_label']);
        $this->assertNull($series[0]['weighted_average']);
        $this->assertNotNull($series[1]['weighted_average']);
    }

    #[Test]
    public function the_first_moment_has_no_movement_because_there_is_nothing_before_it(): void
    {
        $this->assertNull($this->student('Ana')['series'][0]['evolution']);
    }

    #[Test]
    public function movement_between_moments_is_the_progressions_own(): void
    {
        $series = $this->student('Ana')['series'];
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));

        $source = collect($progression['students'])->firstWhere('name', 'Ana Marques');

        $this->assertSame($source['periods'][1]['evolution'], $series[1]['evolution']);
        // Ana fell from 80,1 to 72,5 — and the panel must say so, not smooth it.
        $this->assertSame('down', $series[1]['evolution']['direction']);
    }

    #[Test]
    public function a_moment_without_a_previous_result_has_no_movement_either(): void
    {
        // Nothing precedes Diogo's only period, so there is nothing to compare
        // against — and «sem comparação» is not «manteve-se».
        $this->assertNull($this->student('Diogo')['series'][1]['evolution']);
    }

    #[Test]
    public function each_moment_carries_the_domains_as_they_stood_then(): void
    {
        $domains = count($this->statistics(2)['domains']);

        foreach ($this->student('Ana')['series'] as $moment) {
            $this->assertCount($domains, $moment['domains']);
            $this->assertArrayHasKey('domain_id', $moment['domains'][0]);
            $this->assertArrayHasKey('weighted_average', $moment['domains'][0]);
            $this->assertArrayHasKey('mention', $moment['domains'][0]);
        }
    }

    #[Test]
    public function the_series_is_the_whole_year_whichever_period_is_being_read(): void
    {
        $fromFirst = $this->student('Ana', 1)['series'];
        $fromSecond = $this->student('Ana', 2)['series'];

        // Reading the first period does not shorten anybody's history: the
        // panel answers «como evoluiu ao longo do ano», not «até aqui».
        $this->assertSame($fromFirst, $fromSecond);
    }

    #[Test]
    public function the_series_carries_the_coverage_warning_rather_than_deciding_one(): void
    {
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));
        $source = collect($progression['students'])->firstWhere('name', 'Ana Marques');

        foreach ($this->student('Ana')['series'] as $index => $moment) {
            $this->assertSame((bool) $source['periods'][$index]['coverage_warning'], $moment['coverage_warning']);
        }
    }

    #[Test]
    public function a_student_with_no_results_at_all_still_has_the_year_laid_out(): void
    {
        $this->eraseTheScoresOf('Bruno');

        $series = $this->student('Bruno')['series'];

        $this->assertNotEmpty($series);

        foreach ($series as $moment) {
            $this->assertNull($moment['weighted_average'], 'sem resultado e vazio, nao e zero');
            $this->assertNull($moment['evolution']);
        }
    }

    #[Test]
    public function every_students_whole_year_costs_no_extra_queries(): void
    {
        $baseline = $this->countQueries();

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
        $statistics = $this->statistics(1);
        $periods = $this->asTenant(fn (): int => $this->schoolClass()->academicYear->periods()->count());

        // Sixteen students, each with their own line through the year — and the
        // same query count as six. The series is a reshaping of what the
        // progression already handed over, never a second read (§14).
        $this->assertCount(16, $statistics['students']);

        foreach ($statistics['students'] as $student) {
            $this->assertCount($periods, $student['series']);
        }

        $this->assertLessThanOrEqual(
            $baseline + 2,
            $grown,
            "o número de queries cresceu de {$baseline} para {$grown} ao juntar 10 alunos",
        );
    }

    #[Test]
    public function the_page_carries_each_students_series(): void
    {
        $class = $this->asTenant(fn (): SchoolClass => $this->schoolClass());

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('statistics.students.0.series')
                ->has('statistics.students.0.series.0.period_label')
                ->has('statistics.students.0.series.0.domains'),
            );
    }

    // ------------------------------------------ 12. mudanças de patamar

    /**
     * @return array<string, mixed>
     */
    private function transitions(?int $sequence = 2): array
    {
        return $this->statistics($sequence)['evolution']['transitions'];
    }

    /**
     * The scenario the bug was reported from.
     *
     * A teacher looking at the Quadro Síntese saw a «4» become a «2» and a «2»
     * become a «4», and Estatística reported «Passaram a resultado negativo:
     * 0». It was reading the mention the averages landed on, which had not
     * moved, instead of the grades, which had.
     */
    #[Test]
    public function the_levels_the_teacher_assigned_are_what_decide_a_crossing(): void
    {
        $this->assignAll(1, ['Ana' => '4', 'Bruno' => '2', 'Carolina' => '3', 'Diogo' => '3', 'Eva' => '3']);
        $this->assignAll(2, ['Ana' => '2', 'Bruno' => '4', 'Carolina' => '4', 'Diogo' => '4', 'Eva' => '3', 'Filipe' => '4']);

        $transitions = $this->transitions();

        $this->assertSame(1, $transitions['success_to_failure'], 'Ana: 4 → 2');
        $this->assertSame(1, $transitions['failure_to_success'], 'Bruno: 2 → 4');
        $this->assertSame(3, $transitions['success_to_success'], 'Carolina, Diogo e Eva');
        $this->assertSame(0, $transitions['failure_to_failure']);

        // Filipe was graded only in the second period, so there is no official
        // crossing to report for him — and he is not in the denominator.
        $this->assertSame(1, $transitions['no_assigned_classification']);
        $this->assertSame(5, $transitions['comparable']);
        $this->assertSame('no_assigned_classification', $this->student('Filipe')['transition']);
    }

    #[Test]
    public function a_student_who_crosses_up_is_counted_as_having_reached_a_positive_result(): void
    {
        $this->assign('Eva', 1, '2');
        $this->assign('Eva', 2, '4');

        $this->assertSame(1, $this->transitions()['failure_to_success']);
        $this->assertSame('failure_to_success', $this->student('Eva')['transition']);
    }

    #[Test]
    public function a_student_who_crosses_down_is_counted_as_having_fallen_to_a_negative_result(): void
    {
        $this->assign('Ana', 1, '4');
        $this->assign('Ana', 2, '2');

        $this->assertSame(1, $this->transitions()['success_to_failure']);
        $this->assertSame('success_to_failure', $this->student('Ana')['transition']);
    }

    #[Test]
    public function staying_on_the_positive_side_is_its_own_count(): void
    {
        $this->assign('Ana', 1, '3');
        $this->assign('Ana', 2, '4');

        $this->assertSame(1, $this->transitions()['success_to_success']);
        $this->assertSame('success_to_success', $this->student('Ana')['transition']);
    }

    #[Test]
    public function staying_on_the_negative_side_is_its_own_count(): void
    {
        $this->assign('Ana', 1, '1');
        $this->assign('Ana', 2, '2');

        $this->assertSame(1, $this->transitions()['failure_to_failure']);
        $this->assertSame('failure_to_failure', $this->student('Ana')['transition']);
    }

    #[Test]
    public function progressing_is_not_the_same_as_crossing(): void
    {
        // Eva rose fifteen points and her teacher kept her on «3» both times.
        $this->assign('Eva', 1, '3');
        $this->assign('Eva', 2, '3');

        $statistics = $this->statistics(2);

        $this->assertSame('up', $this->student('Eva')['evolution']['direction']);
        $this->assertSame('success_to_success', $this->student('Eva')['transition']);
        $this->assertSame(2, $statistics['evolution']['progressed']);
        $this->assertSame(0, $statistics['evolution']['transitions']['failure_to_success']);
    }

    #[Test]
    public function regressing_is_not_the_same_as_crossing_either(): void
    {
        $this->assign('Ana', 1, '4');
        $this->assign('Ana', 2, '4');

        $statistics = $this->statistics(2);

        $this->assertSame('down', $this->student('Ana')['evolution']['direction']);
        $this->assertSame('success_to_success', $this->student('Ana')['transition']);
        $this->assertSame(2, $statistics['evolution']['regressed']);
        $this->assertSame(0, $statistics['evolution']['transitions']['success_to_failure']);
    }

    #[Test]
    public function grading_a_class_does_not_touch_the_quantitative_movement(): void
    {
        $before = $this->statistics(2)['evolution'];

        $this->assignAll(1, ['Ana' => '4', 'Bruno' => '2']);
        $this->assignAll(2, ['Ana' => '2', 'Bruno' => '4']);

        $after = $this->statistics(2)['evolution'];

        // Progression is arithmetic over evidence and knows nothing about
        // grades. Only the crossings moved (§19.10).
        $this->assertSame($before['progressed'], $after['progressed']);
        $this->assertSame($before['stable'], $after['stable']);
        $this->assertSame($before['regressed'], $after['regressed']);
        $this->assertSame($before['average_change'], $after['average_change']);
        $this->assertNotSame($before['transitions'], $after['transitions']);
    }

    #[Test]
    public function a_student_graded_in_only_one_of_the_two_periods_is_left_out(): void
    {
        $this->assign('Ana', 2, '4');

        $this->assertSame('no_assigned_classification', $this->student('Ana')['transition']);
        $this->assertSame(0, $this->transitions()['comparable']);
    }

    #[Test]
    public function a_proposal_at_one_end_does_not_make_a_crossing(): void
    {
        // Proposals everywhere, a decision only in the second period. There is
        // no earlier grade, so there is no earlier side.
        $this->proposeOnly(1);
        $this->assign('Ana', 2, '2');

        $this->assertSame('no_assigned_classification', $this->student('Ana')['transition']);
        $this->assertSame(0, $this->transitions()['success_to_failure']);
    }

    #[Test]
    public function a_numeric_decision_the_scale_cannot_read_is_unclassified_and_never_a_crossing(): void
    {
        // «Escala 0 a 20» defines no qualitative levels, so LÁPIS has no
        // statement about which side of it a «7» or a «14» sits on — and does
        // not invent one at 9,5 (§6). The decisions are real and the scale is
        // silent, which is a different answer from having no decision at all.
        $this->useTheNumericScale();

        $this->assignValue('Ana', 1, '7');
        $this->assignValue('Ana', 2, '14');

        $transitions = $this->transitions();

        $this->assertSame(1, $transitions['unclassified']);
        $this->assertSame(0, $transitions['failure_to_success'], 'subir de 7 para 14 não é uma travessia que a escala afirme');
        $this->assertSame(0, $transitions['comparable']);
        $this->assertNull($transitions['percentages']['failure_to_success']);
    }

    #[Test]
    public function a_numeric_decision_is_read_when_the_scale_does_carry_bands(): void
    {
        // The same numeric scale, given the bands a school would configure on
        // it. Now it HAS a statement, and «7 → 14» crosses the line it draws —
        // read through the scale's own interval, never through a «>= 10».
        $this->useTheNumericScale(withBands: true);

        $this->assignValue('Ana', 1, '7');
        $this->assignValue('Ana', 2, '14');

        $this->assertSame(1, $this->transitions()['failure_to_success']);
    }

    #[Test]
    public function a_scale_of_the_schools_own_decides_this_just_the_same(): void
    {
        // Two bands and a line at 60, on a scale the application has never
        // heard of. Its own `is_negative` is all that is read.
        $this->useATwoBandScale();

        $this->assign('Ana', 1, 'NA');
        $this->assign('Ana', 2, 'A');
        $this->assign('Bruno', 1, 'A');
        $this->assign('Bruno', 2, 'NA');

        $transitions = $this->transitions();

        $this->assertSame(1, $transitions['failure_to_success']);
        $this->assertSame(1, $transitions['success_to_failure']);
        $this->assertSame(2, $transitions['comparable']);
    }

    #[Test]
    public function moving_the_scales_line_moves_who_crossed_without_touching_a_grade(): void
    {
        $this->assign('Ana', 1, '3');
        $this->assign('Ana', 2, '4');

        $before = $this->transitions();

        // «Suficiente» becomes negative. The same two grades now describe a
        // student who climbed out of insucesso. Nothing was re-graded, and no
        // «>= 3» exists anywhere for this to have gone around.
        $this->markBandAsNegative('3');

        $after = $this->transitions();

        $this->assertSame(1, $before['success_to_success']);
        $this->assertSame(1, $after['failure_to_success']);
        $this->assertSame(0, $after['success_to_success']);
    }

    #[Test]
    public function the_denominator_is_the_four_transitions_and_nothing_else(): void
    {
        $this->assign('Ana', 1, '4');
        $this->assign('Ana', 2, '2');

        $transitions = $this->transitions();

        $this->assertSame(
            $transitions['failure_to_success'] + $transitions['success_to_failure']
                + $transitions['success_to_success'] + $transitions['failure_to_failure'],
            $transitions['comparable'],
        );

        // And the two that sit outside it get their share of the CLASS, in
        // their own key, so nobody reads them against the wrong base.
        $this->assertArrayHasKey('unclassified', $transitions['share_of_class']);
        $this->assertArrayHasKey('no_assigned_classification', $transitions['share_of_class']);
        $this->assertArrayNotHasKey('unclassified', $transitions['percentages']);
    }

    #[Test]
    public function the_six_groups_account_for_every_student_in_the_class(): void
    {
        $this->assign('Ana', 1, '4');
        $this->assign('Ana', 2, '2');

        $statistics = $this->statistics(2);
        $transitions = $statistics['evolution']['transitions'];

        $this->assertSame(
            $statistics['summary']['students_total'],
            $transitions['comparable'] + $transitions['unclassified'] + $transitions['no_assigned_classification'],
        );
    }

    #[Test]
    public function with_nobody_comparable_the_shares_are_absent_rather_than_zero(): void
    {
        // The first period has nothing before it.
        $transitions = $this->transitions(1);

        $this->assertSame(0, $transitions['comparable']);
        $this->assertSame(6, $transitions['no_assigned_classification']);
        $this->assertNull($transitions['percentages']['success_to_success']);
    }

    #[Test]
    public function each_students_own_transition_agrees_with_the_class_counts(): void
    {
        $this->assignAll(1, ['Ana' => '4', 'Bruno' => '2', 'Carolina' => '3']);
        $this->assignAll(2, ['Ana' => '2', 'Bruno' => '4', 'Carolina' => '3']);

        $statistics = $this->statistics(2);
        $counted = [];

        foreach ($statistics['students'] as $student) {
            $counted[$student['transition']] = ($counted[$student['transition']] ?? 0) + 1;
        }

        foreach (['failure_to_success', 'success_to_failure', 'success_to_success', 'failure_to_failure', 'unclassified', 'no_assigned_classification'] as $key) {
            $this->assertSame(
                $counted[$key] ?? 0,
                $statistics['evolution']['transitions'][$key],
                "«{$key}» diverge entre o aluno e a turma",
            );
        }
    }

    #[Test]
    public function a_grade_written_after_the_cutoff_had_not_been_written_yet(): void
    {
        // Graded at the end of the year, both periods.
        $this->travelTo('2027-06-30');
        $this->assign('Ana', 1, '4');
        $this->assign('Ana', 2, '2');

        $this->assertSame('success_to_failure', $this->student('Ana')['transition']);

        $class = $this->asTenant(fn (): SchoolClass => $this->schoolClass());

        // Read as of February, when the evidence was all in and neither grade
        // had been given. A photograph must not put a grade in the teacher's
        // mouth, dated to a day they had not said it.
        $transitions = $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for(
            $class,
            $this->period(2),
            AssessmentCutoff::on(Carbon::parse('2027-02-15')),
        )['evolution']['transitions']);

        $this->assertSame(0, $transitions['comparable']);
        $this->assertSame(6, $transitions['no_assigned_classification']);
        $this->assertSame(0, $transitions['unclassified'], 'sem decisão não é «a escala não sabe»');
    }

    #[Test]
    public function the_page_carries_the_transitions(): void
    {
        $class = $this->asTenant(fn (): SchoolClass => $this->schoolClass());

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('statistics.evolution.transitions.failure_to_success')
                ->has('statistics.evolution.transitions.success_to_failure')
                ->has('statistics.evolution.transitions.comparable')
                ->has('statistics.evolution.transitions.share_of_class')
                ->has('statistics.students.0.transition'),
            );
    }

    // ------------------------- 13. a distribuição das classificações

    /**
     * @return array<string, mixed>
     */
    private function assignedDistribution(?int $sequence = 2): array
    {
        return $this->statistics($sequence)['assigned_distribution'];
    }

    /**
     * @return array<string, int>
     */
    private function countsByCode(?int $sequence = 2): array
    {
        $counts = [];

        foreach ($this->assignedDistribution($sequence)['bands'] as $band) {
            $counts[$band['code']] = $band['count'];
        }

        return $counts;
    }

    /**
     * The scenario the bug was reported from.
     *
     * A «2» was on the pauta and the distribution showed «Insuficiente: 0»,
     * because it was counting where the averages landed rather than what had
     * been written.
     */
    #[Test]
    public function a_level_the_teacher_assigned_appears_in_its_own_band(): void
    {
        $this->assignAll(2, [
            'Ana' => '2', 'Bruno' => '4', 'Carolina' => '4',
            'Diogo' => '4', 'Eva' => '3', 'Filipe' => '4',
        ]);

        $this->assertSame(
            ['1' => 0, '2' => 1, '3' => 1, '4' => 4, '5' => 0],
            $this->countsByCode(),
        );
        $this->assertSame(6, $this->assignedDistribution()['classified']);
    }

    #[Test]
    public function a_positive_average_with_a_negative_level_counts_in_the_negative_band(): void
    {
        // Ana's accumulated figure lands in «Bom». Her teacher wrote «2».
        $this->assign('Ana', 2, '2');

        $statistics = $this->statistics(2);

        $this->assertSame('4', $this->student('Ana')['band']['code'], 'a média continua em Bom');
        $this->assertSame('2', $this->student('Ana')['assigned']['code']);
        $this->assertSame(1, collect($statistics['assigned_distribution']['bands'])->firstWhere('code', '2')['count']);
        $this->assertSame(0, collect($statistics['assigned_distribution']['bands'])->firstWhere('code', '4')['count']);
    }

    #[Test]
    public function a_negative_average_with_a_positive_level_counts_in_the_positive_band(): void
    {
        // The scale calls «Suficiente» negative, which is where Eva's own
        // figure lands. Her teacher decided «4».
        $this->markBandAsNegative('3');
        $this->assign('Eva', 2, '4');

        $this->assertSame('3', $this->student('Eva')['band']['code']);
        $this->assertSame(['1' => 0, '2' => 0, '3' => 0, '4' => 1, '5' => 0], $this->countsByCode());
    }

    #[Test]
    public function a_proposal_without_a_decision_is_counted_nowhere(): void
    {
        // Every student has a proposal on screen and nobody has been graded.
        $this->proposeOnly(2);

        $distribution = $this->assignedDistribution();

        $this->assertSame(0, $distribution['classified']);
        $this->assertSame(6, $distribution['without_classification']);
        $this->assertSame(['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0], $this->countsByCode());
    }

    #[Test]
    public function students_without_a_classification_are_shown_apart_and_never_as_zero(): void
    {
        $this->assign('Ana', 2, '4');

        $distribution = $this->assignedDistribution();

        $this->assertSame(1, $distribution['classified']);
        $this->assertSame(5, $distribution['without_classification']);
        // And they are in no band at all — not folded into the lowest one.
        $this->assertSame(1, array_sum(array_column($distribution['bands'], 'count')));
    }

    #[Test]
    public function every_band_of_the_scale_keeps_its_row_including_the_empty_ones(): void
    {
        $this->assign('Ana', 2, '4');

        $bands = $this->assignedDistribution()['bands'];

        // Five levels, five rows. «Nível 2: 0» is an answer; a missing row is
        // not, and would redraw the grid from class to class.
        $this->assertCount(5, $bands);
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($bands, 'code'));
    }

    #[Test]
    public function the_bands_come_out_in_the_scales_own_sequence(): void
    {
        $sequences = array_column($this->assignedDistribution()['bands'], 'sequence');

        $sorted = $sequences;
        sort($sorted);

        $this->assertSame($sorted, $sequences, 'a ordem é a da escala, nunca por contagem');
    }

    #[Test]
    public function a_scale_of_the_schools_own_is_shown_in_its_own_words(): void
    {
        $this->useATwoBandScale();

        $this->assign('Ana', 2, 'A');
        $this->assign('Bruno', 2, 'NA');

        $bands = $this->assignedDistribution()['bands'];

        // Nothing here knows what a «3» is. A school using «Não atingiu /
        // Atingiu» gets exactly those, in its own order (§10).
        $this->assertSame(['Não atingiu', 'Atingiu'], array_column($bands, 'label'));
        $this->assertSame([1, 1], array_column($bands, 'count'));
    }

    #[Test]
    public function a_numeric_decision_is_grouped_by_the_scales_bands_when_it_has_them(): void
    {
        $this->useTheNumericScale(withBands: true);

        $this->assignValue('Ana', 2, '14');
        $this->assignValue('Bruno', 2, '7');

        $counts = [];

        foreach ($this->assignedDistribution()['bands'] as $band) {
            $counts[$band['code']] = $band['count'];
        }

        $this->assertSame(['N' => 1, 'S' => 1], $counts);
    }

    #[Test]
    public function a_numeric_decision_on_a_scale_with_no_bands_is_counted_apart(): void
    {
        $this->useTheNumericScale();

        $this->assignValue('Ana', 2, '14');

        $distribution = $this->assignedDistribution();

        // The grade exists and the scale cannot name it. Neither a band nor
        // «sem classificação» — its own count, and no invented interval (§11).
        $this->assertSame(1, $distribution['unplaced']);
        $this->assertSame(0, $distribution['classified']);
        $this->assertSame(5, $distribution['without_classification']);
        $this->assertSame([], $distribution['bands']);
    }

    #[Test]
    public function a_grade_written_after_the_cutoff_is_not_in_the_distribution(): void
    {
        $this->travelTo('2027-06-30');
        $this->assign('Ana', 2, '2');

        $this->assertSame(1, $this->assignedDistribution()['classified']);

        $class = $this->asTenant(fn (): SchoolClass => $this->schoolClass());

        $distribution = $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for(
            $class,
            $this->period(2),
            AssessmentCutoff::on(Carbon::parse('2027-02-15')),
        )['assigned_distribution']);

        $this->assertSame(0, $distribution['classified']);
        $this->assertSame(6, $distribution['without_classification']);
    }

    #[Test]
    public function the_distribution_and_the_success_rate_count_the_same_students(): void
    {
        $this->assignAll(2, ['Ana' => '2', 'Bruno' => '4', 'Carolina' => '1']);

        $statistics = $this->statistics(2);
        $distribution = $statistics['assigned_distribution'];
        $success = $statistics['summary']['success'];

        // Two readings of one set of decisions. If they could disagree, one of
        // them would be lying (§8).
        $this->assertSame($success['placed'], $distribution['classified']);
        $this->assertSame($success['without_classification'], $distribution['without_classification']);
        $this->assertSame($success['unplaced'], $distribution['unplaced']);

        $negatives = 0;

        foreach ($distribution['bands'] as $band) {
            if ($band['is_negative']) {
                $negatives += $band['count'];
            }
        }

        $this->assertSame($success['failed'], $negatives);
    }

    #[Test]
    public function the_calculated_distribution_stays_beside_it_and_is_not_touched(): void
    {
        $before = $this->statistics(2)['distribution'];

        $this->assignAll(2, ['Ana' => '2', 'Bruno' => '1']);

        $statistics = $this->statistics(2);

        // Grading a class does not move where the averages fall — the two
        // readings are separate on purpose and can disagree (§1, §12).
        $this->assertSame($before, $statistics['distribution']);
        $this->assertNotSame(
            array_column($statistics['distribution'], 'count'),
            array_column($statistics['assigned_distribution']['bands'], 'count'),
        );
    }

    #[Test]
    public function a_band_the_scale_no_longer_has_keeps_its_row(): void
    {
        $this->assign('Ana', 2, '5');

        // The school drops «Muito Bom» from the scale afterwards. Ana was still
        // given it, and her history is true even if the scale has moved on.
        $this->asTenant(fn () => Scale::withoutGlobalScope('scaleVisibility')
            ->where('name', 'Escala 1 a 5')->firstOrFail()
            ->levels()->where('code', '5')
            ->update(['band_min_normalized' => null, 'band_max_normalized' => null, 'sequence' => 9]));

        $bands = collect($this->assignedDistribution()['bands']);

        $this->assertSame(1, $bands->firstWhere('code', '5')['count']);
        $this->assertSame(1, $this->assignedDistribution()['classified']);
    }

    #[Test]
    public function the_page_carries_the_assigned_distribution(): void
    {
        $this->assign('Ana', 2, '2');
        $class = $this->asTenant(fn (): SchoolClass => $this->schoolClass());

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('statistics.assigned_distribution.bands')
                ->has('statistics.assigned_distribution.classified')
                ->has('statistics.assigned_distribution.without_classification')
                ->has('statistics.students.0.assigned')
                // The calculated one is still there, beside it.
                ->has('statistics.distribution'),
            );
    }
}
