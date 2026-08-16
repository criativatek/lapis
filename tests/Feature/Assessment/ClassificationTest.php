<?php

namespace Tests\Feature\Assessment;

use App\Models\CalculationSnapshot;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SnapshotTrigger;
use App\Models\Student;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The decision boundary (§7): the engine proposes, the teacher confirms or
 * overrides, and a snapshot freezes how the value was reached. These cover the
 * invariants that cannot regress — a proposal is never a zero, an override needs
 * a reason, a confirmed grade is frozen, and its snapshot is immutable.
 */
class ClassificationTest extends TestCase
{
    use RefreshDatabase;

    private function inDemoClass(callable $callback): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($callback, $teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $callback($class, $period, $teacher);
        });
    }

    #[Test]
    public function proposing_creates_rows_only_for_computable_results(): void
    {
        $this->inDemoClass(function ($class, $period): void {
            $counts = app(ProposeClassifications::class)->forPeriod($class, $period);

            // Carolina and Eva have values; Diogo (absent) and Filipe (late entry)
            // have no computable result → no proposal row, and never a zero.
            $this->assertGreaterThan(0, $counts['created']);
            $this->assertGreaterThan(0, $counts['no_value']);

            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            $this->assertSame(ClassificationStatus::Proposed, $carolina->status);
            $this->assertSame('91.000', $carolina->proposed_value);
            $this->assertNull($carolina->final_value);
            $this->assertNull($carolina->calculation_snapshot_id);

            $this->assertNull($this->classificationFor($period, 'Diogo Ferreira'));
            $this->assertNull($this->classificationFor($period, 'Filipe Andrade'));
        });
    }

    #[Test]
    public function accepting_a_proposal_confirms_it_and_freezes_a_snapshot(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');

            app(ConfirmClassification::class)->confirm($carolina, $teacher);
            $carolina->refresh();

            $this->assertSame(ClassificationStatus::Confirmed, $carolina->status);
            // Accepting records the PROPOSAL as the decision, on the scale: the
            // proposed level, and that level's own number. Never the 91% that
            // produced it, which is a different fact about a different thing.
            $this->assertSame($carolina->proposed_scale_level_id, $carolina->final_scale_level_id);
            $this->assertSame('5.000', $carolina->final_value);
            $this->assertNull($carolina->override_reason);
            $this->assertNull($carolina->overridden_at);
            $this->assertSame($teacher->id, $carolina->confirmed_by);
            $this->assertNotNull($carolina->confirmed_at);

            $snapshot = $carolina->snapshot;
            $this->assertNotNull($snapshot);
            $this->assertSame(SnapshotTrigger::ProposalConfirmed, $snapshot->trigger);
            $this->assertSame('91.000', $snapshot->result_value);
            // The hash must still verify after a round-trip through storage — the
            // canonical form survives a JSON column reordering its keys.
            $reloaded = CalculationSnapshot::findOrFail($snapshot->id);
            $this->assertSame($snapshot->payload_hash, CalculationSnapshot::hashPayload($reloaded->payload));
            // The frozen document carries the engine's structured explanation (§13.5).
            $this->assertArrayHasKey('explanation', $snapshot->payload);
        });
    }

    #[Test]
    public function a_confirmed_snapshot_is_immutable(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher);

            $this->expectException(LogicException::class);
            $carolina->refresh()->snapshot->update(['result_value' => '10.000']);
        });
    }

    #[Test]
    public function a_proposal_goes_stale_when_a_score_changes_after_it_was_generated(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');

            // A grade correction after the proposal was generated: the stored
            // proposal no longer matches the grid, so confirming is refused.
            StudentItemScore::query()
                ->where('enrollment_id', $carolina->enrollment_id)
                ->where('result_state', 'assessed')
                ->update(['points_earned' => 1]);

            $this->expectException(ClassificationDecisionException::class);
            app(ConfirmClassification::class)->confirm($carolina, $teacher);
        });
    }

    #[Test]
    public function deciding_a_different_level_records_the_decision_and_its_authorship(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            $chosen = $this->levelWithCode($class, '3');

            app(ConfirmClassification::class)->confirm(
                $carolina,
                $teacher,
                $chosen->id,
                null,
                'Participação sustentada não refletida nos instrumentos.',
            );
            $carolina->refresh();

            // The proposal is never overwritten…
            $this->assertSame('91.000', $carolina->proposed_value);
            // …and the decision is a LEVEL of the scale, with that level's own
            // number beside it so the row can never say two different things.
            $this->assertSame($chosen->id, $carolina->final_scale_level_id);
            $this->assertSame('3.000', $carolina->final_value);
            $this->assertSame('Participação sustentada não refletida nos instrumentos.', $carolina->override_reason);
            $this->assertSame($teacher->id, $carolina->overridden_by);
            $this->assertNotNull($carolina->overridden_at);
            $this->assertTrue($carolina->wasOverridden());
        });
    }

    #[Test]
    public function deciding_a_different_level_needs_no_justification(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            $chosen = $this->levelWithCode($class, '3');

            // The teacher decides (§3.3). Assigning a level other than the
            // proposed one is the job, not an exception to be justified before
            // it is allowed.
            app(ConfirmClassification::class)->confirm($carolina, $teacher, $chosen->id);
            $carolina->refresh();

            $this->assertSame(ClassificationStatus::Confirmed, $carolina->status);
            $this->assertSame($chosen->id, $carolina->final_scale_level_id);
            $this->assertNull($carolina->override_reason);
            // …and it is still recorded AS a change, which is what the audit
            // trail is for.
            $this->assertNotNull($carolina->overridden_at);
            $this->assertSame($teacher->id, $carolina->overridden_by);
        });
    }

    #[Test]
    public function a_level_that_is_not_on_this_scale_is_refused(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');

            $elsewhere = Scale::create(['name' => 'Escala de outra turma', 'kind' => 'level', 'min_value' => '1', 'max_value' => '3']);
            $foreign = $elsewhere->levels()->create(['code' => 'X', 'label' => 'De outra escala', 'sequence' => 1]);

            $this->expectException(ClassificationDecisionException::class);
            app(ConfirmClassification::class)->confirm($carolina, $teacher, $foreign->id);
        });
    }

    #[Test]
    public function a_percentage_cannot_be_assigned_as_a_level(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');

            // «66» is a result, not a level of a 1–5 scale. There is no id 66 on
            // it, and there is no free field to type it into either.
            $this->expectException(ClassificationDecisionException::class);
            app(ConfirmClassification::class)->confirm($carolina, $teacher, 66);
        });
    }

    #[Test]
    public function re_proposing_leaves_confirmed_classifications_frozen(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            $chosen = $this->levelWithCode($class, '3');
            app(ConfirmClassification::class)->confirm($carolina, $teacher, $chosen->id, null, 'Motivo válido.');

            $counts = app(ProposeClassifications::class)->forPeriod($class, $period);

            $this->assertGreaterThanOrEqual(1, $counts['skipped_frozen']);
            $carolina->refresh();
            $this->assertSame(ClassificationStatus::Confirmed, $carolina->status);
            $this->assertSame($chosen->id, $carolina->final_scale_level_id);
            $this->assertSame('3.000', $carolina->final_value);
        });
    }

    #[Test]
    public function a_confirmed_proposal_cannot_be_confirmed_again(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher);

            $this->expectException(ClassificationDecisionException::class);
            app(ConfirmClassification::class)->confirm($carolina->refresh(), $teacher);
        });
    }

    #[Test]
    public function publishing_communicates_confirmed_classifications_without_recalculating(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher);

            $counts = app(PublishClassifications::class)->forPeriod($class, $period, ClassificationScope::Period);

            $this->assertSame(1, $counts['published']);
            $carolina->refresh();
            $this->assertSame(ClassificationStatus::Published, $carolina->status);
            $this->assertNotNull($carolina->published_at);
            // Publishing recalculates nothing — the confirmed decision is untouched.
            $this->assertSame('5.000', $carolina->final_value);

            // A still-proposed classification is not published — only confirmed ones.
            $this->assertSame(
                ClassificationStatus::Proposed,
                $this->classificationFor($period, 'Bruno Teixeira')->status,
            );
        });
    }

    #[Test]
    public function a_classification_with_an_element_under_review_is_held_back_from_publication(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher);

            // A complaint is opened after confirmation: one element goes under
            // review, which blocks publication of that period's grade (§5).
            StudentItemScore::query()
                ->where('enrollment_id', $carolina->enrollment_id)
                ->where('result_state', ResultState::Assessed->value)
                ->first()
                ->update(['result_state' => ResultState::UnderReview->value, 'points_earned' => null]);

            $counts = app(PublishClassifications::class)->forPeriod($class, $period, ClassificationScope::Period);

            $this->assertSame(0, $counts['published']);
            $this->assertSame(1, $counts['blocked_under_review']);
            $this->assertSame(ClassificationStatus::Confirmed, $carolina->refresh()->status);
            $this->assertNull($carolina->published_at);
        });
    }

    #[Test]
    public function publishing_one_class_never_touches_another_class_in_the_same_period(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher): void {
            $classA = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $classA->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($classA, $period);
            app(ConfirmClassification::class)->confirm($this->classificationFor($period, 'Carolina Nunes'), $teacher);

            // A second class in the SAME organization and academic period (periods
            // belong to the year, not the class). Its confirmed grade must not be
            // published by publishing class A (cross-class authorization hole).
            $classB = SchoolClass::factory()->create([
                'organization_id' => $classA->organization_id,
                'academic_year_id' => $classA->academic_year_id,
                'subject_id' => $classA->subject_id,
                'assessment_profile_version_id' => $classA->assessment_profile_version_id,
                'label' => '7.º B',
            ]);
            $student = Student::factory()->create(['organization_id' => $classA->organization_id]);
            $enrollmentB = Enrollment::factory()->create([
                'organization_id' => $classA->organization_id,
                'class_id' => $classB->id,
                'student_id' => $student->id,
                'enrolled_on' => '2026-09-14',
            ]);
            $classificationB = Classification::create([
                'enrollment_id' => $enrollmentB->id,
                'academic_period_id' => $period->id,
                'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $classA->assessment_profile_version_id,
                'status' => ClassificationStatus::Confirmed,
                'proposed_value' => '80.000',
                'final_value' => '80.000',
                'confirmed_by' => $teacher->id,
                'confirmed_at' => now(),
            ]);

            $counts = app(PublishClassifications::class)->forPeriod($classA, $period, ClassificationScope::Period);

            $this->assertSame(1, $counts['published']); // only Carolina, in class A
            $this->assertSame(ClassificationStatus::Confirmed, $classificationB->refresh()->status);
            $this->assertNull($classificationB->published_at);
        });
    }

    #[Test]
    public function period_and_accumulated_are_separate_decisions_for_the_same_period(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $p2 = $class->academicYear->periods()->where('sequence', 2)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($class, $p2, ClassificationScope::Period);
            app(ProposeClassifications::class)->forPeriod($class, $p2, ClassificationScope::Accumulated);

            $carolina = Classification::query()
                ->where('academic_period_id', $p2->id)
                ->get()
                ->filter(fn (Classification $c) => $c->enrollment->student->identity->display_name === 'Carolina Nunes');

            // Two live rows for the same (enrollment, period): one per scope — the
            // isolated period result and the year-to-date one both stand (§6.3).
            $this->assertCount(2, $carolina);
            $this->assertEqualsCanonicalizing(
                ['period', 'accumulated'],
                $carolina->pluck('scope')->map->value->all(),
            );

            // Confirming the accumulated one leaves the period one untouched.
            $accumulated = $carolina->firstWhere('scope', ClassificationScope::Accumulated);
            app(ConfirmClassification::class)->confirm($accumulated, $teacher);

            $this->assertSame(ClassificationStatus::Confirmed, $accumulated->refresh()->status);
            $this->assertSame(
                ClassificationStatus::Proposed,
                $carolina->firstWhere('scope', ClassificationScope::Period)->refresh()->status,
            );
        });
    }

    #[Test]
    public function confirm_rejects_a_malformed_final_value_over_http(): void
    {
        $ulid = $this->seedAndProposeReturningUlid();

        $this->actingAs(User::where('email', 'ana.martins@lapis.test')->firstOrFail())
            ->post($this->decideUrl($ulid), ['final_value' => '1e2']) // scientific notation
            ->assertSessionHasErrors('final_value');
    }

    #[Test]
    public function a_teacher_from_another_organization_cannot_confirm_this_classification(): void
    {
        $ulid = $this->seedAndProposeReturningUlid();

        // A teacher in a different organization: the tenant scope hides the row,
        // so binding it 404s — the classification's existence is not even revealed.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->post($this->decideUrl($ulid), [])
            ->assertNotFound();
    }

    private function seedAndProposeReturningUlid(): string
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            return Classification::whereNotNull('proposed_value')->firstOrFail()->ulid;
        });
    }

    private function levelWithCode(SchoolClass $class, string $code): ScaleLevel
    {
        return $class->profileVersion->scale->levels()->where('code', $code)->firstOrFail();
    }

    private function classificationFor($period, string $studentName): ?Classification
    {
        return Classification::query()
            ->where('academic_period_id', $period->id)
            ->where('scope', ClassificationScope::Period)
            ->get()
            ->first(fn (Classification $classification) => $classification->enrollment->student->identity->display_name === $studentName);
    }

    /**
     * The one endpoint a decision is written through, addressed by whose
     * decision it is: this student, this period. Resolved here from a stored
     * classification only because these tests already have one — the route
     * itself needs none, which is the point of it.
     */
    private function decideUrl(string $classificationUlid): string
    {
        $owner = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        return app(CurrentOrganization::class)->runFor($owner->personalOrganization(), function () use ($classificationUlid): string {
            $classification = Classification::where('ulid', $classificationUlid)->firstOrFail();
            $class = $classification->enrollment->schoolClass;

            return "/classes/{$class->ulid}/classifications/{$classification->academicPeriod->ulid}/{$classification->enrollment->ulid}/decide";
        });
    }
}
