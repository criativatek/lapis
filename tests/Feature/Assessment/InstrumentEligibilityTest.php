<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\CalculationSnapshot;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\InstrumentType;
use App\Models\InterimAssessment;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\CompleteCorrection;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\EvaluationSheetReadiness;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Services\Assessment\RecordScores;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The eligibility rule (R1-R3, fix/instrument-classification-eligibility): only
 * a CONCLUDED, non-diagnostic, counting instrument feeds averages,
 * classifications, publication's under-review guard and readiness. R1 (the
 * status gate) is behind a switch, off by default; R2 (diagnostic never
 * counts) and R3 (the combination) are always on.
 *
 * Built on the demonstration scenario (7.º A) rather than a bespoke profile,
 * so every test gets a working AssessmentProfileVersion/scale for free.
 */
class InstrumentEligibilityTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * @return array{SchoolClass, AcademicPeriod}
     */
    private function demoClass(): array
    {
        $class = SchoolClass::where('label', '7.º A')->firstOrFail();
        $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

        return [$class, $period];
    }

    private function enrollmentNamed(SchoolClass $class, string $name)
    {
        return $class->enrollments->first(
            fn ($enrollment) => $enrollment->student->identity->display_name === $name,
        );
    }

    private function makeInstrument(SchoolClass $class, AcademicPeriod $period, array $overrides = []): Instrument
    {
        // Allocated to a real domain so the instrument can actually feed a
        // weighted classification average (an item with no allocation only
        // counts toward the instrument's own total, never toward a domain —
        // §4.3), which scenario 15 (protected history) needs to be real.
        $domainId = Domain::query()->value('id');

        return app(InstrumentBuilder::class)->create($class, array_merge([
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Instrumento de teste',
            'applied_on' => $period->starts_on->addDays(5)->toDateString(),
            'status' => InstrumentStatus::Prepared->value,
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], $overrides), [
            [
                'code' => 'Q1',
                'points_possible' => 100,
                'domains' => $domainId === null ? [] : [['domain_id' => $domainId, 'allocation_percent' => 100]],
            ],
        ]);
    }

    private function markAllStudents(Instrument $instrument, User $teacher, float $points = 100): void
    {
        $item = $instrument->items()->firstOrFail();
        $cells = $instrument->schoolClass->enrollments->map(fn ($enrollment) => [
            'enrollment_id' => $enrollment->id,
            'instrument_item_id' => $item->id,
            'result_state' => ResultState::Assessed->value,
            'lock_version' => 0,
            'points_earned' => $points,
        ])->all();

        app(RecordScores::class)->save($instrument, $cells, $teacher);
    }

    // --------------------------------------------------- 1/2. legacy behaviour (switch OFF)

    #[Test]
    public function switch_off_a_completed_counting_instrument_enters_the_average(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();
            $instrument = $this->makeInstrument($class, $period, ['status' => InstrumentStatus::Prepared->value]);
            $this->markAllStudents($instrument, $teacher);
            app(CompleteCorrection::class)->complete($instrument, $teacher);

            $this->assertTrue($instrument->fresh()->entersCalculation());
        });
    }

    #[Test]
    public function switch_off_a_not_counting_instrument_never_enters(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();
            $instrument = $this->makeInstrument($class, $period, ['counts_toward_classification' => false]);
            $this->markAllStudents($instrument, $teacher);
            app(CompleteCorrection::class)->complete($instrument, $teacher);

            $this->assertFalse($instrument->fresh()->entersCalculation());
        });
    }

    // --------------------------------------------------- 3/4/5. switch ON, status gate

    #[Test]
    public function switch_on_an_in_correction_instrument_is_out_even_fully_marked(): void
    {
        config(['lapis.assessment.averages_require_concluded_instruments' => true]);
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();
            $instrument = $this->makeInstrument($class, $period);
            $this->markAllStudents($instrument, $teacher);

            $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
            $this->assertFalse($instrument->fresh()->entersCalculation());
        });
    }

    #[Test]
    public function switch_on_completing_the_correction_makes_it_eligible(): void
    {
        config(['lapis.assessment.averages_require_concluded_instruments' => true]);
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();
            $instrument = $this->makeInstrument($class, $period);
            $this->markAllStudents($instrument, $teacher);
            app(CompleteCorrection::class)->complete($instrument, $teacher);

            $this->assertTrue($instrument->fresh()->entersCalculation());
        });
    }

    #[Test]
    public function switch_on_reopening_removes_it_from_new_calculations(): void
    {
        config(['lapis.assessment.averages_require_concluded_instruments' => true]);
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();
            $instrument = $this->makeInstrument($class, $period);
            $this->markAllStudents($instrument, $teacher);
            app(CompleteCorrection::class)->complete($instrument, $teacher);
            $this->assertTrue($instrument->fresh()->entersCalculation());

            app(CompleteCorrection::class)->reopen($instrument->fresh(), $teacher);

            $this->assertFalse($instrument->fresh()->entersCalculation());
        });
    }

    // --------------------------------------------------- 6/7. diagnostic never counts (R2)

    #[Test]
    public function a_completed_diagnostic_instrument_never_enters_the_average(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();
            $instrument = $this->makeInstrument($class, $period, ['purpose' => 'diagnostic', 'counts_toward_classification' => true]);
            $this->markAllStudents($instrument, $teacher);
            app(CompleteCorrection::class)->complete($instrument, $teacher);

            $this->assertFalse($instrument->fresh()->counts_toward_classification);
            $this->assertFalse($instrument->fresh()->entersCalculation());
        });
    }

    #[Test]
    public function a_diagnostic_with_counts_true_written_directly_in_the_db_is_still_excluded(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();
            $instrument = $this->makeInstrument($class, $period, ['purpose' => 'formative']);
            $this->markAllStudents($instrument, $teacher);
            app(CompleteCorrection::class)->complete($instrument, $teacher);

            // Bypass the model's saving hook entirely, the way a stray manual
            // UPDATE or a pre-hook legacy row could.
            DB::table('instruments')->where('id', $instrument->id)->update([
                'purpose' => 'diagnostic',
                'counts_toward_classification' => true,
            ]);

            $this->assertTrue((bool) DB::table('instruments')->where('id', $instrument->id)->value('counts_toward_classification'));

            $calculator = app(ClassResultsCalculator::class);
            $ids = $calculator->instrumentsInScope($class, $period, ClassificationScope::Period)->pluck('id');
            $this->assertNotContains($instrument->id, $ids);

            $contributing = $calculator->contributingInstrumentIds($class, $period, ClassificationScope::Period);
            $this->assertNotContains($instrument->id, $contributing);
        });
    }

    // --------------------------------------------------- 13. readiness + publication share the same eligibility

    #[Test]
    public function an_under_review_score_blocks_neither_publication_nor_readiness_when_the_instrument_is_ineligible(): void
    {
        config(['lapis.assessment.averages_require_concluded_instruments' => true]);
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();

            // In correction with the switch ON: ineligible.
            $ineligible = $this->makeInstrument($class, $period);
            $this->markAllStudents($ineligible, $teacher);

            $enrollment = $class->enrollments()->firstOrFail();
            StudentItemScore::query()
                ->where('instrument_id', $ineligible->id)
                ->where('enrollment_id', $enrollment->id)
                ->update(['result_state' => ResultState::UnderReview->value, 'points_earned' => null]);

            $blockedIds = app(EvaluationSheetReadiness::class);
            $reflection = new ReflectionMethod($blockedIds, 'enrollmentsWithElementsUnderReview');
            $reflection->setAccessible(true);
            $flagged = $reflection->invoke($blockedIds, $class, $period, ClassificationScope::Period, [$enrollment->id]);

            $this->assertSame([], $flagged, 'An ineligible instrument must not block readiness.');

            $countingIds = app(ClassResultsCalculator::class)->contributingInstrumentIds($class, $period, ClassificationScope::Period);
            $this->assertNotContains($ineligible->id, $countingIds);
        });
    }

    #[Test]
    public function an_under_review_score_on_an_eligible_instrument_blocks_both(): void
    {
        config(['lapis.assessment.averages_require_concluded_instruments' => true]);
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();

            $eligible = $this->makeInstrument($class, $period);
            $this->markAllStudents($eligible, $teacher);
            app(CompleteCorrection::class)->complete($eligible, $teacher);

            $enrollment = $class->enrollments()->firstOrFail();
            StudentItemScore::query()
                ->where('instrument_id', $eligible->id)
                ->where('enrollment_id', $enrollment->id)
                ->update(['result_state' => ResultState::UnderReview->value, 'points_earned' => null]);

            $readiness = app(EvaluationSheetReadiness::class);
            $reflection = new ReflectionMethod($readiness, 'enrollmentsWithElementsUnderReview');
            $reflection->setAccessible(true);
            $flagged = $reflection->invoke($readiness, $class, $period, ClassificationScope::Period, [$enrollment->id]);

            $this->assertArrayHasKey($enrollment->id, $flagged, 'An eligible instrument under review must block readiness.');
        });
    }

    // --------------------------------------------------- switch off preserves legacy behaviour

    #[Test]
    public function switch_off_in_correction_and_archived_still_count_exactly_as_before(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();

            $inCorrection = $this->makeInstrument($class, $period, ['title' => 'Em correção']);
            $this->markAllStudents($inCorrection, $teacher);
            $this->assertTrue($inCorrection->fresh()->entersCalculation());

            $completed = $this->makeInstrument($class, $period, ['title' => 'Arquivada depois de concluída']);
            $this->markAllStudents($completed, $teacher);
            app(CompleteCorrection::class)->complete($completed, $teacher);
            $completed->fresh()->forceFill(['status' => InstrumentStatus::Archived])->save();

            $this->assertTrue($completed->fresh()->entersCalculation());
        });
    }

    // --------------------------------------------------- status boundary cases (switch ON)

    #[Test]
    public function switch_on_archived_is_not_eligible_but_published_is(): void
    {
        config(['lapis.assessment.averages_require_concluded_instruments' => true]);
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();

            $archived = $this->makeInstrument($class, $period, ['title' => 'Arquivada']);
            $this->markAllStudents($archived, $teacher);
            app(CompleteCorrection::class)->complete($archived, $teacher);
            $archived->fresh()->forceFill(['status' => InstrumentStatus::Archived])->save();
            $this->assertFalse($archived->fresh()->entersCalculation());

            $published = $this->makeInstrument($class, $period, ['title' => 'Publicada']);
            $this->markAllStudents($published, $teacher);
            app(CompleteCorrection::class)->complete($published, $teacher);
            $published->fresh()->forceFill(['status' => InstrumentStatus::Published])->save();
            $this->assertTrue($published->fresh()->entersCalculation());
        });
    }

    #[Test]
    public function a_completed_diagnostics_own_outcome_still_appears_in_for_instruments(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();
            $instrument = $this->makeInstrument($class, $period, ['purpose' => 'diagnostic', 'counts_toward_classification' => false]);
            $this->markAllStudents($instrument, $teacher);
            app(CompleteCorrection::class)->complete($instrument, $teacher);

            $enrollment = $class->enrollments()->firstOrFail();
            $calculator = app(ClassResultsCalculator::class);
            $outcomes = $calculator->forInstruments($class, $enrollment, collect([$instrument->fresh()]));

            $this->assertNotEmpty($outcomes, 'A diagnostic instrument still reports its own per-instrument outcome.');
        });
    }

    // --------------------------------------------------- 16. a stale Proposed classification cannot be confirmed once the switch flips

    #[Test]
    public function confirming_a_stale_proposal_after_the_switch_turns_on_is_refused_and_the_row_is_unchanged(): void
    {
        $teacher = $this->seedDemo();

        [$class, $period, $classificationId, $originalValue] = $this->asTenant($teacher, function () use ($teacher) {
            [$class, $period] = $this->demoClass();

            // Switch OFF while this in-correction instrument still counts.
            $instrument = $this->makeInstrument($class, $period);
            $this->markAllStudents($instrument, $teacher);

            app(ProposeClassifications::class)->forPeriod($class, $period);
            $classification = Classification::query()
                ->where('academic_period_id', $period->id)
                ->where('scope', ClassificationScope::Period)
                ->where('status', ClassificationStatus::Proposed)
                ->firstOrFail();

            return [$class, $period, $classification->id, $classification->proposed_value];
        });

        config(['lapis.assessment.averages_require_concluded_instruments' => true]);

        $this->asTenant($teacher, function () use ($teacher, $classificationId, $originalValue): void {
            $classification = Classification::findOrFail($classificationId);

            $this->expectException(ClassificationDecisionException::class);

            try {
                app(ConfirmClassification::class)->confirm($classification, $teacher, null, '3');
            } finally {
                $fresh = Classification::findOrFail($classificationId);
                $this->assertSame(ClassificationStatus::Proposed, $fresh->status);
                $this->assertSame($originalValue, $fresh->proposed_value);
            }
        });
    }

    // --------------------------------------------------- 13 (extended). the REAL PublishClassifications

    #[Test]
    public function real_publication_is_not_blocked_by_an_ineligible_instrument_but_is_blocked_by_an_eligible_one(): void
    {
        config(['lapis.assessment.averages_require_concluded_instruments' => true]);
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();

            $carolina = $this->enrollmentNamed($class, 'Carolina Nunes');
            $diogo = $this->enrollmentNamed($class, 'Diogo Ferreira');

            // An eligible instrument (completed, counts) with Carolina's cell
            // under review — must block her publication.
            $eligible = $this->makeInstrument($class, $period, ['title' => 'Elegível']);
            $this->markAllStudents($eligible, $teacher);
            app(CompleteCorrection::class)->complete($eligible, $teacher);
            StudentItemScore::query()
                ->where('instrument_id', $eligible->id)
                ->where('enrollment_id', $carolina->id)
                ->update(['result_state' => ResultState::UnderReview->value, 'points_earned' => null]);

            // An ineligible instrument (diagnostic, forced counts=true directly
            // in the DB — R2 must still exclude it) with Diogo's cell under
            // review — must NOT block his publication.
            $ineligible = $this->makeInstrument($class, $period, ['title' => 'Inelegível', 'purpose' => 'diagnostic']);
            $this->markAllStudents($ineligible, $teacher);
            app(CompleteCorrection::class)->complete($ineligible, $teacher);
            DB::table('instruments')->where('id', $ineligible->id)->update(['counts_toward_classification' => true]);
            StudentItemScore::query()
                ->where('instrument_id', $ineligible->id)
                ->where('enrollment_id', $diogo->id)
                ->update(['result_state' => ResultState::UnderReview->value, 'points_earned' => null]);

            // PublishClassifications::forPeriod() recalculates nothing — it only
            // reads Confirmed classifications and scans the counting
            // instruments' scores (see its docblock). Writing the Confirmed
            // rows directly keeps this test about publication's own guard,
            // not about the demo class's domain coverage under Propose/Confirm.
            $carolinaClassification = Classification::create([
                'enrollment_id' => $carolina->id,
                'academic_period_id' => $period->id,
                'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'status' => ClassificationStatus::Confirmed,
                'final_value' => '3',
                'confirmed_by' => $teacher->id,
                'confirmed_at' => now(),
            ]);
            $diogoClassification = Classification::create([
                'enrollment_id' => $diogo->id,
                'academic_period_id' => $period->id,
                'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'status' => ClassificationStatus::Confirmed,
                'final_value' => '3',
                'confirmed_by' => $teacher->id,
                'confirmed_at' => now(),
            ]);

            // Every OTHER confirmed classification in this class/period would
            // also be touched by a real forPeriod() call; none exists yet
            // (nothing else was confirmed), so the counts below are exact.
            $result = app(PublishClassifications::class)->forPeriod($class, $period, ClassificationScope::Period);

            $this->assertSame(1, $result['published']);
            $this->assertSame(1, $result['blocked_under_review']);

            $this->assertSame(ClassificationStatus::Published, $diogoClassification->fresh()->status, 'The ineligible instrument must not block Diogo.');
            $this->assertSame(ClassificationStatus::Confirmed, $carolinaClassification->fresh()->status, 'The eligible instrument under review must block Carolina.');

            // Readiness must agree with the same eligibility.
            $readiness = app(EvaluationSheetReadiness::class);
            $reflection = new ReflectionMethod($readiness, 'enrollmentsWithElementsUnderReview');
            $reflection->setAccessible(true);
            $flagged = $reflection->invoke($readiness, $class, $period, ClassificationScope::Period, [$carolina->id, $diogo->id]);

            $this->assertArrayHasKey($carolina->id, $flagged);
            $this->assertArrayNotHasKey($diogo->id, $flagged);
        });
    }

    // --------------------------------------------------- 14. the grid keeps working with the switch ON

    #[Test]
    public function switch_on_the_grid_still_shows_and_saves_scores_for_an_in_correction_instrument(): void
    {
        config(['lapis.assessment.averages_require_concluded_instruments' => true]);
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            [$class, $period] = $this->demoClass();
            $instrument = $this->makeInstrument($class, $period);
            $enrollment = $class->enrollments()->firstOrFail();
            $item = $instrument->items()->firstOrFail();

            $this->actingAs($teacher)
                ->get("/instruments/{$instrument->ulid}")
                ->assertOk();

            $this->actingAs($teacher)
                ->post("/instruments/{$instrument->ulid}/scores", [
                    'cells' => [[
                        'enrollment_id' => $enrollment->id,
                        'instrument_item_id' => $item->id,
                        'result_state' => 'assessed',
                        'lock_version' => 0,
                        'points_earned' => 100,
                    ]],
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame(
                1,
                StudentItemScore::where('instrument_id', $instrument->id)->where('enrollment_id', $enrollment->id)->count(),
                'The grid keeps saving scores for an in_correction instrument regardless of the eligibility switch.',
            );
            $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
            $this->assertFalse($instrument->fresh()->entersCalculation(), 'Still not eligible for averages, but the grid is unaffected.');
        });
    }

    // --------------------------------------------------- 15. protected history is frozen

    #[Test]
    public function confirmed_published_and_interim_records_are_byte_identical_after_an_instrument_becomes_ineligible(): void
    {
        $teacher = $this->seedDemo();

        [$class, $period, $enrollmentId, $before] = $this->asTenant($teacher, function () use ($teacher) {
            [$class, $period] = $this->demoClass();
            $enrollment = $this->enrollmentNamed($class, 'Carolina Nunes');

            // An instrument that will later be flipped to diagnostic via the
            // builder (still eligible right now).
            $flippable = $this->makeInstrument($class, $period, ['title' => 'A tornar diagnóstica']);
            $this->markAllStudents($flippable, $teacher);
            app(CompleteCorrection::class)->complete($flippable, $teacher);

            // Stays eligible even after the switch turns on and everything
            // above becomes ineligible — otherwise Carolina's outcome would
            // simply stop having a value, and the frozen-row guard being
            // tested here would never be exercised at all.
            $persistent = $this->makeInstrument($class, $period, ['title' => 'Fica sempre elegível']);
            $this->markAllStudents($persistent, $teacher);
            app(CompleteCorrection::class)->complete($persistent, $teacher);

            app(ProposeClassifications::class)->forPeriod($class, $period);
            $classification = Classification::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('academic_period_id', $period->id)
                ->where('scope', ClassificationScope::Period)
                ->firstOrFail();

            app(ConfirmClassification::class)->confirm($classification, $teacher, $classification->proposed_scale_level_id, $classification->proposed_value);
            $confirmed = $classification->fresh();
            app(PublishClassifications::class)->forPeriod($class, $period, ClassificationScope::Period);
            $published = $confirmed->fresh();

            $snapshot = CalculationSnapshot::where('enrollment_id', $enrollment->id)
                ->where('academic_period_id', $period->id)
                ->latest('id')
                ->first();

            $interim = app(CaptureInterimAssessment::class)->capture(
                $class,
                $period,
                Carbon::now(),
                $teacher,
            )->refresh();
            // Relido da base: o MySQL normaliza a ordem das chaves de uma
            // coluna JSON, e o «depois» também vem da base.

            $before = [
                'final_value' => $published->final_value,
                'final_scale_level_id' => $published->final_scale_level_id,
                'status' => $published->status,
                'snapshot_id' => $snapshot?->id,
                'snapshot_payload' => $snapshot?->payload,
                'snapshot_payload_hash' => $snapshot?->payload_hash,
                'interim_id' => $interim->id,
                'interim_snapshot' => $interim->snapshot,
                'interim_snapshot_hash' => $interim->snapshot_hash,
            ];

            return [$class, $period, $enrollment->id, $before];
        });

        // Make two instruments ineligible: one via the switch (in_correction),
        // one via the diagnostic override (through the builder, on update).
        $this->asTenant($teacher, function () use ($teacher, $class, $period): void {
            config(['lapis.assessment.averages_require_concluded_instruments' => true]);

            $stillInCorrection = $this->makeInstrument($class, $period, ['title' => 'Fica em correção']);
            $this->markAllStudents($stillInCorrection, $teacher);

            $flippable = Instrument::where('class_id', $class->id)->where('title', 'A tornar diagnóstica')->firstOrFail();
            app(InstrumentBuilder::class)->update(
                $flippable,
                [
                    'academic_period_id' => $flippable->academic_period_id,
                    'instrument_type_id' => $flippable->instrument_type_id,
                    'title' => $flippable->title,
                    'applied_on' => $flippable->applied_on->toDateString(),
                    'status' => $flippable->status->value,
                    'counts_toward_classification' => true,
                    'purpose' => 'diagnostic',
                    'total_points' => $flippable->total_points,
                ],
                $flippable->items->map(fn ($item) => ['ulid' => $item->ulid, 'code' => $item->code, 'points_possible' => (float) $item->points_possible])->all(),
            );
        });

        $this->asTenant($teacher, function () use ($class, $period, $enrollmentId, $before): void {
            $countBefore = Classification::where('academic_period_id', $period->id)->where('scope', ClassificationScope::Period)->count();

            $result = app(ProposeClassifications::class)->forPeriod($class, $period);

            // The frozen (Confirmed/Published) row was skipped, not overwritten.
            $this->assertArrayHasKey('skipped_frozen', $result);
            $this->assertGreaterThanOrEqual(1, $result['skipped_frozen']);
            $this->assertSame($countBefore, Classification::where('academic_period_id', $period->id)->where('scope', ClassificationScope::Period)->count());

            $classification = Classification::where('enrollment_id', $enrollmentId)
                ->where('academic_period_id', $period->id)
                ->where('scope', ClassificationScope::Period)
                ->firstOrFail();

            $this->assertSame($before['final_value'], $classification->final_value);
            $this->assertSame($before['final_scale_level_id'], $classification->final_scale_level_id);
            $this->assertSame($before['status'], $classification->status);

            $snapshot = CalculationSnapshot::where('enrollment_id', $enrollmentId)
                ->where('academic_period_id', $period->id)
                ->latest('id')
                ->first();
            $this->assertSame($before['snapshot_id'], $snapshot?->id);
            $this->assertSame($before['snapshot_payload'], $snapshot?->payload);
            $this->assertSame($before['snapshot_payload_hash'], $snapshot?->payload_hash);

            $interim = InterimAssessment::where('id', $before['interim_id'])->firstOrFail();
            $this->assertSame($before['interim_snapshot'], $interim->snapshot);
            $this->assertSame($before['interim_snapshot_hash'], $interim->snapshot_hash);
        });
    }
}
