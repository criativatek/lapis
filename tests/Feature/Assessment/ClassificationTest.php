<?php

namespace Tests\Feature\Assessment;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\SchoolClass;
use App\Models\SnapshotTrigger;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            // Accepting sets final = proposed with no reason — the A10 CHECK holds.
            $this->assertSame('91.000', $carolina->final_value);
            $this->assertNull($carolina->override_reason);
            $this->assertSame($teacher->id, $carolina->confirmed_by);
            $this->assertNotNull($carolina->confirmed_at);

            $snapshot = $carolina->snapshot;
            $this->assertNotNull($snapshot);
            $this->assertSame(SnapshotTrigger::ProposalConfirmed, $snapshot->trigger);
            $this->assertSame('91.000', $snapshot->result_value);
            $this->assertSame(hash('sha256', (string) json_encode($snapshot->payload)), $snapshot->payload_hash);
            // The frozen document carries the engine's structured explanation (§13.5).
            $this->assertArrayHasKey('explanation', $snapshot->payload);
        });
    }

    #[Test]
    public function overriding_records_the_teachers_value_reason_and_authorship(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');

            app(ConfirmClassification::class)->confirm($carolina, $teacher, '95', 'Participação sustentada não refletida nos instrumentos.');
            $carolina->refresh();

            // A10's four elements all preserved: original proposal, final value,
            // reason, author (+date).
            $this->assertSame('91.000', $carolina->proposed_value);
            $this->assertSame('95.000', $carolina->final_value);
            $this->assertSame('Participação sustentada não refletida nos instrumentos.', $carolina->override_reason);
            $this->assertSame($teacher->id, $carolina->overridden_by);
            $this->assertNotNull($carolina->overridden_at);
        });
    }

    #[Test]
    public function overriding_without_a_reason_is_refused(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');

            $this->expectException(ClassificationDecisionException::class);
            app(ConfirmClassification::class)->confirm($carolina, $teacher, '95', '   ');
        });
    }

    #[Test]
    public function re_proposing_leaves_confirmed_classifications_frozen(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher, '95', 'Motivo válido.');

            $counts = app(ProposeClassifications::class)->forPeriod($class, $period);

            $this->assertGreaterThanOrEqual(1, $counts['skipped_frozen']);
            $carolina->refresh();
            $this->assertSame(ClassificationStatus::Confirmed, $carolina->status);
            $this->assertSame('95.000', $carolina->final_value);
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

    private function classificationFor($period, string $studentName): ?Classification
    {
        return Classification::query()
            ->where('academic_period_id', $period->id)
            ->where('scope', ClassificationScope::Period)
            ->get()
            ->first(fn (Classification $classification) => $classification->enrollment->student->identity->display_name === $studentName);
    }
}
