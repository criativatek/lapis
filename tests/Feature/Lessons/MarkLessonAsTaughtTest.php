<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarkLessonAsTaughtTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);
    }

    #[Test]
    public function it_transitions_preparation_and_prepared_lessons_to_taught_with_the_existing_audit_shape(): void
    {
        foreach ([LessonStatus::Preparation, LessonStatus::Prepared] as $status) {
            $lesson = $this->lessonFor($this->teacher, attributes: ['status' => $status]);

            $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

            $this->inTenant($this->organization, function () use ($lesson, $status): void {
                $this->assertSame(LessonStatus::Taught, $lesson->refresh()->status);
                $event = AuditEvent::query()
                    ->where('event', 'lesson.taught')
                    ->where('subject_id', $lesson->id)
                    ->sole();
                $this->assertSame($this->teacher->id, $event->causer_id);
                $this->assertSame([
                    'from_status' => $status->value,
                    'to_status' => LessonStatus::Taught->value,
                ], $event->properties);
            });
        }
    }

    #[Test]
    public function marking_an_already_taught_lesson_is_a_no_op_without_a_duplicate_audit_event(): void
    {
        $lesson = $this->lessonFor($this->teacher, attributes: ['status' => LessonStatus::Taught]);

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

        $this->assertDatabaseMissing('audit_events', [
            'event' => 'lesson.taught',
            'subject_id' => $lesson->id,
        ]);
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden(): void
    {
        $lesson = $this->lessonFor($this->teacher);
        $unassignedTeacher = User::factory()->create();
        $this->organization->members()->attach($unassignedTeacher, ['joined_at' => now()]);

        $this->actingAs($unassignedTeacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post("/lessons/{$lesson->ulid}/mark-taught")
            ->assertForbidden();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            LessonStatus::Preparation,
            $lesson->refresh()->status,
        ));
    }

    #[Test]
    public function a_lesson_from_another_organization_is_not_resolved(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $otherLesson = $this->lessonFor($otherTeacher, $otherOrganization);

        $this->asTeacher()->post("/lessons/{$otherLesson->ulid}/mark-taught")->assertNotFound();
    }

    #[Test]
    public function impersonation_blocks_marking_a_lesson_as_taught(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->actingAs($this->teacher)
            ->withSession([
                'organization_id' => $this->organization->id,
                'impersonator_id' => 999,
            ])
            ->post("/lessons/{$lesson->ulid}/mark-taught")
            ->assertForbidden();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            LessonStatus::Preparation,
            $lesson->refresh()->status,
        ));
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lessonFor(
        User $teacher,
        ?Organization $organization = null,
        array $attributes = [],
    ): Lesson {
        $organization ??= $this->organization;

        return $this->inTenant($organization, function () use ($attributes, $organization, $teacher): Lesson {
            $schoolClass = SchoolClass::factory()
                ->recycle($organization)
                ->create([
                    'academic_year_id' => AcademicYear::factory()
                        ->recycle($organization)
                        ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])
                        ->id,
                ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return Lesson::create(array_merge([
                'class_id' => $schoolClass->id,
                'starts_at' => '2026-10-08 09:30:00',
                'ends_at' => '2026-10-08 10:20:00',
                'status' => LessonStatus::Preparation,
                'created_by' => $teacher->id,
            ], $attributes));
        });
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
