<?php

namespace Tests\Feature\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Domain;
use App\Models\InstrumentType;
use App\Models\InterimAssessment;
use App\Models\ProfileVersionPeriod;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Services\Assessment\CompareInterimToPeriodFinal;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\OpenClassification;
use App\Services\Assessment\RecordScores;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The photograph against where the period ended up.
 *
 * TWO SOURCES, AND THE ASYMMETRY IS THE POINT. The interim is frozen and read
 * out of its document; the final is the live canonical read model. So a
 * comparison run tomorrow may differ — because the period moved, never because
 * the photograph did, and that is exactly what these assert.
 */
class InterimComparisonTest extends TestCase
{
    use RefreshDatabase;

    /** The demo class in roll order, so a fixture can name a student. */
    private const ROLL = ['Ana' => 1, 'Bruno' => 2, 'Carolina' => 3, 'Diogo' => 4, 'Eva' => 5, 'Filipe' => 6];

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));
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

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function capture(string $date, string $name = 'Intercalar de novembro'): InterimAssessment
    {
        return $this->asTenant(fn (): InterimAssessment => app(CaptureInterimAssessment::class)->capture(
            $this->schoolClass(),
            $this->period(1),
            Carbon::parse($date),
            $this->teacher,
            ['name' => $name],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(InterimAssessment $interim): array
    {
        return $this->asTenant(fn (): array => app(CompareInterimToPeriodFinal::class)
            ->compare($this->schoolClass(), $interim));
    }

    /**
     * Adds a second instrument to the first period, after the photograph, so
     * the two ends genuinely differ.
     */
    private function addLaterInstrument(int $points = 18): void
    {
        $this->asTenant(function () use ($points): void {
            $class = $this->schoolClass();
            $leitura = Domain::where('code', 'LEITURA')->firstOrFail();

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $this->period(1)->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Ficha de dezembro',
                'applied_on' => '2026-12-10',
                'status' => 'in_correction',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 20,
            ], [
                ['code' => 'D1', 'label' => 'Leitura', 'points_possible' => 20, 'domains' => [
                    ['domain_id' => $leitura->id, 'allocation_percent' => 100],
                ]],
            ]);

            $item = $instrument->items->firstOrFail();
            $cells = [];

            foreach ($class->enrollments()->orderBy('class_number')->get() as $enrollment) {
                $cells[] = [
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $item->id,
                    'result_state' => ResultState::Assessed->value,
                    'points_earned' => $points,
                ];
            }

            app(RecordScores::class)->save($instrument, $cells, $this->teacher);
        });
    }

    // ------------------------------------------------------- 1. a turma

    #[Test]
    public function it_reads_the_photograph_and_the_live_period_without_recomputing_either(): void
    {
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument();

        $comparison = $this->compare($interim);

        // The left side is exactly what was stored.
        $this->assertSame(
            $interim->snapshot['summary']['class_average'],
            $comparison['summary']['primary']['interim_value'],
        );

        // And the photograph did not move on the way.
        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));
        $this->assertSame($interim->snapshot, $again->snapshot);
        $this->assertTrue($again->isIntact());
    }

    #[Test]
    public function it_uses_the_name_the_teacher_chose(): void
    {
        $interim = $this->capture('2026-11-15', 'Avaliação intercalar de novembro');

        $comparison = $this->compare($interim);

        // Never a label rebuilt from the date (§9 of the naming decision).
        $this->assertSame('Avaliação intercalar de novembro', $comparison['interim']['name']);
        $this->assertSame('15/11/2026', $comparison['interim']['reference_date_label']);
        $this->assertSame('1.º Semestre', $comparison['period']['label']);
    }

    #[Test]
    public function the_difference_is_in_percentage_points(): void
    {
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument(20);

        $comparison = $this->compare($interim);
        $summary = $comparison['summary'];

        $this->assertNotNull($summary['primary']['interim_value']);
        $this->assertNotNull($summary['primary']['final_value']);
        $this->assertSame(
            round((float) $summary['primary']['final_value'] - (float) $summary['primary']['interim_value'], 1),
            (float) $summary['primary']['change'],
        );
    }

    #[Test]
    public function nothing_to_compare_gives_nothing_rather_than_zero(): void
    {
        // Before any instrument of the period: the photograph has no averages.
        $interim = $this->capture('2026-10-01');

        $comparison = $this->compare($interim);

        $this->assertNull($comparison['summary']['primary']['interim_value']);
        // A missing end means no difference — never «0,0 p.p.», which would
        // read as «não mudou nada» (§37).
        $this->assertNull($comparison['summary']['primary']['change']);
        $this->assertNull($comparison['movement']['average_change']);
        $this->assertSame(0, $comparison['movement']['comparable']);
    }

    // --------------------------------------------------- 2. o movimento

    #[Test]
    public function students_are_counted_by_the_direction_they_moved(): void
    {
        $interim = $this->capture('2026-11-15');
        // A high mark for everyone afterwards: those who had a result go up.
        $this->addLaterInstrument(20);

        $movement = $this->compare($interim)['movement'];
        $total = $movement['progressed'] + $movement['stable'] + $movement['regressed'] + $movement['no_comparison'];

        $this->assertSame(count($this->compare($interim)['students']), $total);
        $this->assertGreaterThan(0, $movement['progressed']);
    }

    #[Test]
    public function a_fall_is_counted_as_a_fall(): void
    {
        $interim = $this->capture('2026-11-15');
        // A poor mark for everyone afterwards.
        $this->addLaterInstrument(2);

        $movement = $this->compare($interim)['movement'];

        $this->assertGreaterThan(0, $movement['regressed']);
        $this->assertLessThan(0, (float) $movement['average_change']);
    }

    #[Test]
    public function standing_still_is_counted_as_standing_still(): void
    {
        // Nothing happens between the photograph and now.
        $interim = $this->capture('2026-11-15');

        $movement = $this->compare($interim)['movement'];

        $this->assertGreaterThan(0, $movement['stable']);
        $this->assertSame(0, $movement['progressed']);
        $this->assertSame(0, $movement['regressed']);
        $this->assertSame('0.0', $movement['average_change']);
    }

    #[Test]
    public function a_student_missing_from_one_end_is_not_counted_as_standing_still(): void
    {
        $interim = $this->capture('2026-11-15');

        $movement = $this->compare($interim)['movement'];

        // Diogo was absent and Filipe enrolled late: neither has a first-period
        // result at either end. They have nothing to stand against (§37).
        $this->assertGreaterThanOrEqual(2, $movement['no_comparison']);

        $withoutComparison = array_values(array_filter(
            $this->compare($interim)['students'],
            fn (array $student): bool => $student['direction'] === null,
        ));

        $this->assertSame($movement['no_comparison'], count($withoutComparison));
    }

    // --------------------------------------------------- 3. os domínios

    #[Test]
    public function each_domain_is_matched_by_its_canonical_id(): void
    {
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument();

        $domains = $this->compare($interim)['domains'];
        $ids = array_column($domains, 'domain_id');

        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertNotEmpty($domains);

        foreach ($domains as $domain) {
            $this->assertArrayHasKey('primary', $domain);
            $this->assertArrayHasKey('supplementary', $domain);
            $this->assertArrayHasKey('interim_mention', $domain);
            $this->assertArrayHasKey('final_mention', $domain);
            $this->assertArrayHasKey('interim_partial_coverage', $domain);
            $this->assertArrayHasKey('final_partial_coverage', $domain);
        }
    }

    #[Test]
    public function a_domain_renamed_after_the_photograph_keeps_its_historical_name(): void
    {
        $interim = $this->capture('2026-11-15');

        $this->asTenant(fn () => Domain::where('code', 'LEITURA')->firstOrFail()
            ->update(['name' => 'Compreensão Leitora']));

        $labels = array_column($this->compare($interim)['domains'], 'label');

        // The left-hand side is a reading of the past, and reads in the words
        // of the past (§4).
        $this->assertContains('Leitura', $labels);
        $this->assertNotContains('Compreensão Leitora', $labels);
    }

    // ----------------------------------------------------- 4. os alunos

    #[Test]
    public function each_student_carries_both_ends_and_what_lies_between(): void
    {
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument();

        $students = $this->compare($interim)['students'];

        $this->assertNotEmpty($students);

        foreach ($students as $student) {
            $this->assertArrayHasKey('name', $student);
            $this->assertArrayHasKey('primary', $student);
            $this->assertArrayHasKey('supplementary', $student);
            $this->assertArrayHasKey('change', $student);
            $this->assertArrayHasKey('interim_band', $student);
            $this->assertArrayHasKey('final_band', $student);
            $this->assertArrayHasKey('interim_classification', $student);
            $this->assertArrayHasKey('final_classification', $student);
            $this->assertArrayHasKey('interim_self_assessment', $student);
            $this->assertArrayHasKey('domains', $student);
            // Coverage on both sides, each true of its own moment (§10).
            $this->assertArrayHasKey('interim_coverage_warning', $student);
            $this->assertArrayHasKey('final_coverage_warning', $student);
        }
    }

    #[Test]
    public function the_student_names_are_the_ones_the_photograph_holds(): void
    {
        $interim = $this->capture('2026-11-15');

        $names = array_column($this->compare($interim)['students'], 'name');
        $stored = array_column($interim->snapshot['students'], 'name_snapshot');

        $this->assertSame($stored, $names);
    }

    // --------------------------------------------- 5. correr não altera

    #[Test]
    public function comparing_writes_nothing_at_all(): void
    {
        $interim = $this->capture('2026-11-15');

        $before = $this->asTenant(fn (): array => [
            'interims' => InterimAssessment::query()->count(),
            'scores' => StudentItemScore::query()->count(),
        ]);

        $this->compare($interim);
        $this->compare($interim);

        $after = $this->asTenant(fn (): array => [
            'interims' => InterimAssessment::query()->count(),
            'scores' => StudentItemScore::query()->count(),
        ]);

        $this->assertSame($before, $after);
    }

    // ------------------------------------------------------ 6. a página

    #[Test]
    public function the_comparison_page_renders(): void
    {
        $interim = $this->capture('2026-11-15', 'Avaliação intercalar de novembro');
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}/comparar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('results/InterimComparison')
                ->where('comparison.interim.name', 'Avaliação intercalar de novembro')
                ->has('comparison.summary')
                ->has('comparison.movement')
                ->has('comparison.domains')
                ->has('comparison.students'));
    }

    #[Test]
    public function another_organization_cannot_compare_this_moment(): void
    {
        $interim = $this->capture('2026-11-15');
        $class = $this->schoolClass();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}/comparar")
            ->assertNotFound();
    }

    // ------------------- a classificação atribuída, nos dois momentos

    /**
     * A decision taken the way the decision screen takes it.
     *
     * The photograph then stores whatever the read model saw, so what these
     * exercise is the real rule about when a grade exists — not a row written
     * by hand into the snapshot.
     */
    private function assign(string $firstName, int $sequence, string $code, ?string $at = null): void
    {
        // WHEN a grade was given matters here as much as what it was: a
        // photograph shows the decisions that had been taken by its date, so a
        // fixture meant to be inside one has to be dated inside it too.
        $now = Carbon::now();

        if ($at !== null) {
            $this->travelTo(Carbon::parse($at));
        }

        $this->asTenant(function () use ($firstName, $sequence, $code): void {
            $class = $this->schoolClass();
            $enrollment = $class->enrollments()
                ->where('class_number', self::ROLL[$firstName])->firstOrFail();

            $classification = app(OpenClassification::class)->forDecision(
                $class,
                $this->period($sequence),
                $enrollment,
                ClassificationScope::Period,
            );

            $level = $class->profileVersion->scale->levels->firstWhere('code', $code);

            // Changing one's mind before publication is an ordinary act on the
            // same row, and the application has its own verb for it.
            $classification->status === ClassificationStatus::Confirmed
                ? app(ConfirmClassification::class)->redecide($classification, $this->teacher, $level->id)
                : app(ConfirmClassification::class)->confirm($classification, $this->teacher, $level->id);
        });

        $this->travelTo($now);
    }

    /** Flips the scale's own statement about a band, live. */
    private function markBandAsNegative(string $code, bool $negative = true): void
    {
        $this->asTenant(fn () => Scale::withoutGlobalScope('scaleVisibility')
            ->where('name', 'Escala 1 a 5')->firstOrFail()
            ->levels()->where('code', $code)
            ->update(['is_negative' => $negative]));
    }

    #[Test]
    public function the_photograph_records_the_success_rate_of_the_moment(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $interim = $this->capture('2026-11-15');

        $success = $interim->snapshot['summary']['success'];

        $this->assertSame(1, $success['succeeded'], 'a fotografia conta as classificações atribuídas');
        $this->assertSame(
            $success['succeeded'] + $success['failed'],
            $success['placed'],
            'a fotografia guarda os tres grupos e um so denominador',
        );
        $this->assertArrayHasKey('without_classification', $success);
    }

    #[Test]
    public function the_comparison_states_the_rate_at_both_ends(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument();

        $comparison = $this->compare($interim)['success'];

        $this->assertTrue($comparison['interim_is_available']);
        $this->assertNotNull($comparison['interim']);
        $this->assertNotNull($comparison['final']);
        $this->assertSame($comparison['interim']['succeeded'], $comparison['interim_succeeded']);
        $this->assertSame($comparison['final']['succeeded'], $comparison['final_succeeded']);
    }

    #[Test]
    public function the_change_between_the_two_rates_is_in_percentage_points(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $this->assign('Bruno', 1, '2', '2026-11-01');
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument();

        $comparison = $this->compare($interim)['success'];

        $expected = Bc::round(
            Bc::sub(Bc::of($comparison['final']['rate']), Bc::of($comparison['interim']['rate'])),
            CompareInterimToPeriodFinal::PRECISION,
            'half_up',
        );

        $this->assertSame($expected, $comparison['change']);
    }

    #[Test]
    public function an_older_photograph_that_never_recorded_a_rate_says_so_rather_than_zero(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $interim = $this->capture('2026-11-15');

        // A document written before the block existed. It is never rebuilt to
        // fill the gap — a photograph did not observe what it did not record.
        $this->asTenant(function () use ($interim): void {
            $snapshot = $interim->snapshot;
            unset($snapshot['summary']['success']);

            DB::table('interim_assessments')->where('id', $interim->id)->update([
                'snapshot_version' => 1,
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'snapshot_hash' => InterimAssessment::hashFor($snapshot),
            ]);
        });

        $comparison = $this->compare($interim->fresh())['success'];

        $this->assertFalse($comparison['interim_is_available']);
        $this->assertNull($comparison['interim']);
        $this->assertNull($comparison['interim_succeeded'], 'nao sabemos nao se escreve 0');
        $this->assertNull($comparison['change'], 'sem um dos lados nao ha diferenca');
        // The live side is still readable — only the past is silent.
        $this->assertNotNull($comparison['final']);
    }

    #[Test]
    public function a_rate_recorded_before_it_meant_assigned_grades_is_not_placed_beside_one(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $interim = $this->capture('2026-11-15');

        // A v2 photograph HAS a `success` block, but it counted the mentions the
        // averages landed on. Same key, different question — so it is declared
        // unavailable rather than quietly compared, and never rewritten (§16).
        $this->asTenant(function () use ($interim): void {
            DB::table('interim_assessments')->where('id', $interim->id)->update(['snapshot_version' => 2]);
        });

        $comparison = $this->compare($interim->fresh())['success'];

        $this->assertFalse($comparison['interim_is_available']);
        $this->assertNull($comparison['change']);
        $this->assertNotNull($comparison['final']);
        // And the document itself is untouched.
        $this->assertNotNull($interim->fresh()->snapshot['summary']['success']);
    }

    #[Test]
    public function a_photograph_taken_now_carries_the_current_snapshot_version(): void
    {
        $this->assertSame(
            InterimAssessment::CURRENT_VERSION,
            $this->capture('2026-11-15')->snapshot_version,
        );
    }

    // ---------------------------------- quem mudou de patamar entre os dois

    #[Test]
    public function the_comparison_counts_who_changed_side_of_the_scale(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $this->assign('Bruno', 1, '2', '2026-11-01');

        $interim = $this->capture('2026-11-15');

        // The teacher then grades the end of the period the other way round.
        $this->assign('Ana', 1, '2');
        $this->assign('Bruno', 1, '4');

        $transitions = $this->compare($interim)['transitions'];

        $this->assertSame(1, $transitions['success_to_failure'], 'Ana: 4 → 2');
        $this->assertSame(1, $transitions['failure_to_success'], 'Bruno: 2 → 4');
        $this->assertSame(2, $transitions['comparable']);
        $this->assertSame(
            $transitions['failure_to_success'] + $transitions['success_to_failure']
                + $transitions['success_to_success'] + $transitions['failure_to_failure'],
            $transitions['comparable'],
        );
    }

    #[Test]
    public function a_class_nobody_graded_reports_no_crossings_rather_than_inventing_them(): void
    {
        // Averages everywhere and not one decision. There is no official
        // crossing to report, and the calculated mention does not stand in.
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument();

        $transitions = $this->compare($interim)['transitions'];

        $this->assertSame(0, $transitions['comparable']);
        $this->assertSame(6, $transitions['no_assigned_classification']);
    }

    #[Test]
    public function the_interim_side_is_the_grade_the_photograph_stored_and_not_todays(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $interim = $this->capture('2026-11-15');

        // The teacher changes their mind afterwards. November did not.
        $this->assign('Ana', 1, '2');

        $comparison = $this->compare($interim);

        $this->assertSame(1, $comparison['transitions']['success_to_failure']);

        $ana = collect($comparison['students'])->firstWhere('transition', 'success_to_failure');

        $this->assertSame('4', $ana['interim_classification']['final']['code'], 'a fotografia guarda o que viu');
        $this->assertSame('2', $ana['final_classification']['final']['code']);
    }

    #[Test]
    public function reconfiguring_the_scale_afterwards_does_not_rewrite_the_photograph(): void
    {
        $this->assign('Ana', 1, '3', '2026-11-01');
        $interim = $this->capture('2026-11-15');

        // «Suficiente» becomes negative AFTER the photograph. What the snapshot
        // stored keeps the scale's opinion of the day it was taken.
        $this->markBandAsNegative('3');

        $comparison = $this->compare($interim);
        $ana = collect($comparison['students'])->first(fn (array $row): bool => $row['transition'] !== 'no_assigned_classification');

        $this->assertFalse(
            $ana['interim_classification']['final']['is_negative'],
            'a fotografia guarda a leitura de então e não se reescreve',
        );
        $this->assertSame('success_to_failure', $ana['transition']);
    }

    #[Test]
    public function a_photograph_that_never_recorded_a_side_is_left_unclassified(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $interim = $this->capture('2026-11-15');

        // A document written before bands carried this flag. Guessing which
        // side it was would be inventing history (§24).
        $this->asTenant(function () use ($interim): void {
            $snapshot = $interim->snapshot;

            foreach ($snapshot['students'] as $index => $student) {
                if (($student['classification']['final'] ?? null) !== null) {
                    unset($snapshot['students'][$index]['classification']['final']['is_negative']);
                }
            }

            DB::table('interim_assessments')->where('id', $interim->id)->update([
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'snapshot_hash' => InterimAssessment::hashFor($snapshot),
            ]);
        });

        $transitions = $this->compare($interim->fresh())['transitions'];

        $this->assertSame(1, $transitions['unclassified']);
        $this->assertSame(0, $transitions['comparable']);
        $this->assertSame(0, $transitions['success_to_success'], 'não sabemos não é «manteve-se»');
    }

    #[Test]
    public function a_student_graded_at_only_one_end_has_no_transition_to_report(): void
    {
        // Graded now, and not then.
        $interim = $this->capture('2026-11-15');
        $this->assign('Ana', 1, '4');

        $comparison = $this->compare($interim);
        $ana = collect($comparison['students'])->firstWhere('final_classification.final.code', '4');

        $this->assertSame('no_assigned_classification', $ana['transition']);
        $this->assertSame(0, $comparison['transitions']['comparable']);
    }

    #[Test]
    public function each_students_transition_agrees_with_the_comparisons_counts(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $this->assign('Bruno', 1, '2', '2026-11-01');
        $interim = $this->capture('2026-11-15');
        $this->assign('Ana', 1, '2');

        $comparison = $this->compare($interim);
        $counted = [];

        foreach ($comparison['students'] as $student) {
            $counted[$student['transition']] = ($counted[$student['transition']] ?? 0) + 1;
        }

        foreach (['failure_to_success', 'success_to_failure', 'success_to_success', 'failure_to_failure', 'unclassified', 'no_assigned_classification'] as $key) {
            $this->assertSame($counted[$key] ?? 0, $comparison['transitions'][$key], "«{$key}» diverge");
        }
    }

    #[Test]
    public function the_comparison_page_carries_the_transitions(): void
    {
        $interim = $this->capture('2026-11-15');
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}/comparar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('comparison.transitions.failure_to_success')
                ->has('comparison.transitions.success_to_failure')
                ->has('comparison.transitions.comparable')
                ->has('comparison.students.0.transition'));
    }

    // ----------------- a distribuição das classificações, nos dois momentos

    #[Test]
    public function the_photograph_records_how_many_students_held_each_level(): void
    {
        $this->assign('Ana', 1, '2', '2026-11-01');
        $this->assign('Bruno', 1, '4', '2026-11-01');

        $interim = $this->capture('2026-11-15');

        $counts = [];

        foreach ($interim->snapshot['assigned_distribution']['bands'] as $band) {
            $counts[$band['code']] = $band['count'];
        }

        $this->assertSame(['1' => 0, '2' => 1, '3' => 0, '4' => 1, '5' => 0], $counts);
        $this->assertSame(2, $interim->snapshot['assigned_distribution']['classified']);
        $this->assertSame(4, $interim->snapshot['assigned_distribution']['without_classification']);
    }

    #[Test]
    public function the_photograph_holds_the_grades_of_its_day_and_not_todays(): void
    {
        $this->assign('Ana', 1, '2', '2026-11-01');
        $interim = $this->capture('2026-11-15');

        // The teacher changes their mind in June. November does not.
        $this->assign('Ana', 1, '5');

        $stored = collect($interim->fresh()->snapshot['assigned_distribution']['bands']);

        $this->assertSame(1, $stored->firstWhere('code', '2')['count']);
        $this->assertSame(0, $stored->firstWhere('code', '5')['count']);
    }

    #[Test]
    public function the_comparison_puts_the_two_distributions_side_by_side(): void
    {
        $this->assign('Ana', 1, '2', '2026-11-01');
        $this->assign('Bruno', 1, '3', '2026-11-01');

        $interim = $this->capture('2026-11-15');

        // By the end of the period Ana climbed and Bruno was joined by Carolina.
        $this->assign('Ana', 1, '4');
        $this->assign('Carolina', 1, '3');

        $distribution = $this->compare($interim)['assigned_distribution'];
        $bands = collect($distribution['bands'])->keyBy('code');

        $this->assertTrue($distribution['interim_is_available']);
        $this->assertSame([1, 0, -1], [
            $bands['2']['interim_count'], $bands['2']['final_count'], $bands['2']['change'],
        ]);
        $this->assertSame([1, 2, 1], [
            $bands['3']['interim_count'], $bands['3']['final_count'], $bands['3']['change'],
        ]);
        $this->assertSame([0, 1, 1], [
            $bands['4']['interim_count'], $bands['4']['final_count'], $bands['4']['change'],
        ]);
    }

    #[Test]
    public function the_bands_of_the_comparison_stay_in_the_scales_own_order(): void
    {
        $this->assign('Ana', 1, '2', '2026-11-01');
        $interim = $this->capture('2026-11-15');

        $sequences = array_column($this->compare($interim)['assigned_distribution']['bands'], 'sequence');
        $sorted = $sequences;
        sort($sorted);

        $this->assertSame($sorted, $sequences, 'sem ordenação por contagem');
    }

    #[Test]
    public function students_without_a_grade_are_reported_at_both_ends(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $interim = $this->capture('2026-11-15');
        $this->assign('Bruno', 1, '3');

        $distribution = $this->compare($interim)['assigned_distribution'];

        $this->assertSame(5, $distribution['interim_without_classification']);
        $this->assertSame(4, $distribution['final_without_classification']);
    }

    #[Test]
    public function a_photograph_taken_before_this_block_says_so_rather_than_showing_zeros(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $interim = $this->capture('2026-11-15');

        // A v3 document never recorded the grades per level and is not given
        // them from today's — that would be rebuilding history (§12).
        $this->asTenant(function () use ($interim): void {
            $snapshot = $interim->snapshot;
            unset($snapshot['assigned_distribution']);

            DB::table('interim_assessments')->where('id', $interim->id)->update([
                'snapshot_version' => 3,
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'snapshot_hash' => InterimAssessment::hashFor($snapshot),
            ]);
        });

        $distribution = $this->compare($interim->fresh())['assigned_distribution'];

        $this->assertFalse($distribution['interim_is_available']);
        $this->assertSame([], $distribution['bands']);
        $this->assertNull($distribution['interim_classified']);
        // The live side is still readable — only the past is silent.
        $this->assertNotNull($distribution['final_classified']);
    }

    #[Test]
    public function the_comparison_page_carries_the_two_distributions(): void
    {
        $this->assign('Ana', 1, '4', '2026-11-01');
        $interim = $this->capture('2026-11-15');
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}/comparar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('comparison.assigned_distribution.bands')
                ->where('comparison.assigned_distribution.interim_is_available', true));
    }

    // ------------------- qual das duas leituras é a comparação principal

    /** Says whether a period feeds the continuous line, as the profile does. */
    private function periodContributes(int $sequence, bool $contributes): void
    {
        $this->asTenant(function () use ($sequence, $contributes): void {
            $class = $this->schoolClass();

            ProfileVersionPeriod::updateOrCreate(
                [
                    'assessment_profile_version_id' => $class->profileVersion->id,
                    'academic_period_id' => $this->period($sequence)->id,
                ],
                ['contributes_to_accumulated' => $contributes, 'is_cumulative' => $contributes],
            );
        });
    }

    /** A photograph of the SECOND period, which is where the two readings differ. */
    private function captureSecondPeriod(string $date): InterimAssessment
    {
        return $this->asTenant(fn (): InterimAssessment => app(CaptureInterimAssessment::class)->capture(
            $this->schoolClass(),
            $this->period(2),
            Carbon::parse($date),
            $this->teacher,
            ['name' => 'Intercalar de março'],
        ));
    }

    #[Test]
    public function a_photograph_of_the_first_moment_compares_the_periods_own_result(): void
    {
        $interim = $this->capture('2026-11-15');

        $summary = $this->compare($interim)['summary'];

        // Nothing is behind the first period to accumulate, so its own figure
        // IS the result — and there is no second reading to offer (§16).
        $this->assertSame('period', $summary['primary']['kind']);
        $this->assertNull($summary['supplementary']);
    }

    #[Test]
    public function a_photograph_of_a_later_moment_compares_the_accumulated_result(): void
    {
        $interim = $this->captureSecondPeriod('2027-03-15');

        $summary = $this->compare($interim)['summary'];

        $this->assertSame('accumulated', $summary['primary']['kind']);
        $this->assertSame('Avaliação contínua', $summary['primary']['label']);
        $this->assertNotNull($summary['supplementary']);
        $this->assertSame('period', $summary['supplementary']['kind']);
    }

    #[Test]
    public function the_two_comparisons_read_different_numbers(): void
    {
        $interim = $this->captureSecondPeriod('2027-03-15');

        $summary = $this->compare($interim)['summary'];
        $snapshot = $interim->snapshot['summary'];

        // The primary side comes from what the photograph stored under the
        // accumulated name, and the supplementary from what it stored under
        // the period's own. Neither is recomputed.
        $this->assertSame($snapshot['accumulated_average'], $summary['primary']['interim_value']);
        $this->assertSame($snapshot['class_average'], $summary['supplementary']['interim_value']);
        $this->assertNotSame($summary['primary']['interim_value'], $summary['supplementary']['interim_value']);
    }

    #[Test]
    public function the_main_difference_is_accumulated_against_accumulated(): void
    {
        $interim = $this->captureSecondPeriod('2027-03-15');
        $this->addLaterInstrument();

        $summary = $this->compare($interim)['summary'];
        $final = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(2))['summary']);

        $this->assertSame($final['accumulated_average'], $summary['primary']['final_value']);
        $this->assertSame(
            round((float) $summary['primary']['final_value'] - (float) $summary['primary']['interim_value'], 1),
            (float) $summary['primary']['change'],
        );
    }

    #[Test]
    public function the_supplementary_difference_is_standalone_against_standalone(): void
    {
        $interim = $this->captureSecondPeriod('2027-03-15');
        $this->addLaterInstrument();

        $summary = $this->compare($interim)['summary'];
        $final = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(2))['summary']);

        $this->assertSame($final['class_average'], $summary['supplementary']['final_value']);
        $this->assertNotSame($summary['primary']['change'], $summary['supplementary']['change']);
    }

    #[Test]
    public function the_rule_reads_the_profile_and_never_the_word_semestre(): void
    {
        // A profile whose second period stands outside the continuous line.
        $this->periodContributes(2, false);

        $summary = $this->compare($this->captureSecondPeriod('2027-03-15'))['summary'];

        $this->assertSame('period', $summary['primary']['kind']);
        $this->assertNull($summary['supplementary'], 'sem continuidade não há segunda leitura');
    }

    #[Test]
    public function each_domain_follows_the_same_rule(): void
    {
        $interim = $this->captureSecondPeriod('2027-03-15');

        $comparison = $this->compare($interim);

        foreach ($comparison['domains'] as $domain) {
            $this->assertSame('accumulated', $domain['primary']['kind']);
            $this->assertNotNull($domain['supplementary']);
            $this->assertSame('period', $domain['supplementary']['kind']);
        }

        // And the primary side really is the accumulated figure the photograph
        // stored for that domain, not its period one.
        $stored = collect($interim->snapshot['domain_statistics'])->keyBy('domain_id');
        $first = $comparison['domains'][0];

        $this->assertSame($stored[$first['domain_id']]['accumulated_average'], $first['primary']['interim_value']);
        $this->assertSame($stored[$first['domain_id']]['period_average'], $first['supplementary']['interim_value']);
    }

    #[Test]
    public function each_student_carries_both_readings(): void
    {
        $interim = $this->captureSecondPeriod('2027-03-15');

        $comparison = $this->compare($interim);
        $stored = collect($interim->snapshot['students'])->keyBy('enrollment_id');

        foreach ($comparison['students'] as $student) {
            $source = $stored[$student['enrollment_id']];

            $this->assertSame('accumulated', $student['primary']['kind']);
            $this->assertSame($source['accumulated_average'], $student['primary']['interim_value']);
            $this->assertSame($source['weighted_average'], $student['supplementary']['interim_value']);
            // The movement counted for the class is the PRIMARY one.
            $this->assertSame($student['primary']['change'], $student['change']);
            $this->assertSame($student['primary']['direction'], $student['direction']);
        }
    }

    #[Test]
    public function a_photograph_that_never_stored_the_figure_says_so_rather_than_borrowing_todays(): void
    {
        $interim = $this->captureSecondPeriod('2027-03-15');

        // A document from before the accumulated figure was recorded at all.
        $this->asTenant(function () use ($interim): void {
            $snapshot = $interim->snapshot;
            unset($snapshot['summary']['accumulated_average']);

            DB::table('interim_assessments')->where('id', $interim->id)->update([
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'snapshot_hash' => InterimAssessment::hashFor($snapshot),
            ]);
        });

        $summary = $this->compare($interim->fresh())['summary'];

        $this->assertFalse($summary['primary']['is_available']);
        $this->assertNull($summary['primary']['interim_value']);
        $this->assertNull($summary['primary']['change'], 'sem um dos lados não há diferença');
        // The live side is still readable — only the past is silent.
        $this->assertNotNull($summary['primary']['final_value']);
    }

    #[Test]
    public function a_stored_null_is_a_real_answer_and_not_an_unavailable_one(): void
    {
        // Before any instrument of the year: the photograph HAS the key and it
        // is empty, which is «ninguém tinha resultado» — a different sentence
        // from «esta fotografia não guardou isto».
        $summary = $this->compare($this->capture('2026-10-01'))['summary'];

        $this->assertTrue($summary['primary']['is_available']);
        $this->assertNull($summary['primary']['interim_value']);
        $this->assertNull($summary['primary']['change']);
    }

    #[Test]
    public function the_grades_and_everything_counted_from_them_are_untouched(): void
    {
        $this->assign('Ana', 2, '2', '2027-03-01');
        $interim = $this->captureSecondPeriod('2027-03-15');

        $comparison = $this->compare($interim);

        // Success, crossings and the assigned distribution answer questions
        // about decisions, and no choice of average may edit them (§12–§14).
        $this->assertSame(1, $comparison['success']['interim']['failed']);
        $this->assertSame(1, $comparison['transitions']['success_to_success']
            + $comparison['transitions']['failure_to_failure']
            + $comparison['transitions']['failure_to_success']
            + $comparison['transitions']['success_to_failure']);
        $this->assertSame(1, $comparison['assigned_distribution']['interim_classified']);
    }

    #[Test]
    public function the_comparison_page_carries_both_readings(): void
    {
        $interim = $this->captureSecondPeriod('2027-03-15');
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}/comparar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('comparison.summary.primary.kind', 'accumulated')
                ->has('comparison.summary.primary.change')
                ->where('comparison.summary.supplementary.kind', 'period')
                ->has('comparison.students.0.primary')
                ->has('comparison.domains.0.primary'));
    }
}
