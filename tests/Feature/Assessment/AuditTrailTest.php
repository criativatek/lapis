<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The audit trail (§22.4): the events that touch a student's record each leave an
 * immutable line, with the causer and the meaningful context.
 */
class AuditTrailTest extends TestCase
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

    private function classificationFor($period, string $name): Classification
    {
        return Classification::query()
            ->where('academic_period_id', $period->id)
            ->where('scope', ClassificationScope::Period)
            ->get()
            ->first(fn (Classification $classification) => $classification->enrollment->student->identity->display_name === $name);
    }

    #[Test]
    public function confirming_a_classification_records_an_audit_event(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            app(ConfirmClassification::class)->confirm($this->classificationFor($period, 'Carolina Nunes'), $teacher);

            $event = AuditEvent::where('event', 'classification.confirmed')->latest('id')->firstOrFail();
            $this->assertSame($teacher->id, $event->causer_id);
            $this->assertSame('Classification', $event->subject_type);
            $this->assertSame('91.000', $event->properties['final_value']);
        });
    }

    #[Test]
    public function an_override_records_the_reason_in_the_trail(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            app(ConfirmClassification::class)->confirm($this->classificationFor($period, 'Carolina Nunes'), $teacher, '95', 'Participação sustentada.');

            $event = AuditEvent::where('event', 'classification.overridden')->firstOrFail();
            $this->assertSame('Participação sustentada.', $event->properties['override_reason']);
            $this->assertSame('91.000', $event->properties['proposed_value']);
            $this->assertSame('95.000', $event->properties['final_value']);
        });
    }

    #[Test]
    public function publishing_records_a_single_batch_event(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            app(ConfirmClassification::class)->confirm($this->classificationFor($period, 'Carolina Nunes'), $teacher);
            app(PublishClassifications::class)->forPeriod($class, $period, ClassificationScope::Period);

            $events = AuditEvent::where('event', 'classification.published')->get();
            $this->assertCount(1, $events);
            $this->assertSame(1, $events->first()->properties['published']);
        });
    }

    #[Test]
    public function activating_a_profile_version_is_audited(): void
    {
        $this->inDemoClass(function (): void {
            // The demo seeder activates the profile version through the service.
            $this->assertDatabaseHas('audit_events', ['event' => 'profile_version.activated']);
        });
    }

    #[Test]
    public function an_audit_event_is_immutable(): void
    {
        $this->inDemoClass(function ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            app(ConfirmClassification::class)->confirm($this->classificationFor($period, 'Carolina Nunes'), $teacher);

            $event = AuditEvent::where('event', 'classification.confirmed')->firstOrFail();
            $this->expectException(LogicException::class);
            $event->update(['summary' => 'adulterado']);
        });
    }

    #[Test]
    public function the_activity_page_lists_the_organizations_events(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);
            app(ConfirmClassification::class)->confirm($this->classificationFor($period, 'Carolina Nunes'), $teacher);
        });

        $this->actingAs($teacher)->get('/activity')->assertInertia(
            fn ($page) => $page->component('Activity')->where('events', fn ($events) => count($events) > 0),
        );
    }
}
