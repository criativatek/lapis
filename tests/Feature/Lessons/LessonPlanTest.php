<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
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
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LessonPlanTest extends TestCase
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
    public function saving_a_plan_without_changing_status_preserves_it_when_reopening_the_lesson(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/plan", [
            'planned_summary' => 'Explorar frações equivalentes.',
            'target_status' => LessonStatus::Preparation->value,
        ])->assertRedirect();

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('lessons/Show')
                ->where('lesson.status', LessonStatus::Preparation->value)
                ->where('lesson.plan.planned_summary', 'Explorar frações equivalentes.'));

        $this->assertDatabaseCount('lesson_plans', 1);
    }

    #[Test]
    public function a_lesson_with_a_non_empty_plan_can_be_marked_prepared(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->savePlan($lesson, 'Números racionais e operações.', LessonStatus::Prepared)
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonStatus::Prepared, $lesson->refresh()->status);
            $this->assertSame('Números racionais e operações.', $lesson->plan?->planned_summary);
        });
    }

    #[Test]
    public function a_lesson_with_an_official_summary_can_be_marked_prepared_without_a_plan_text(): void
    {
        $lesson = $this->lessonFor($this->teacher);
        $this->inTenant($this->organization, fn (): LessonSummary => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => 'Sumário oficial já preenchido.',
        ]));

        $this->savePlan($lesson, '', LessonStatus::Prepared)->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonStatus::Prepared, $lesson->refresh()->status);
            $this->assertSame('', $lesson->plan?->planned_summary);
        });
    }

    #[Test]
    public function a_lesson_without_plan_or_summary_cannot_be_marked_prepared(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->savePlan($lesson, '   ', LessonStatus::Prepared)
            ->assertSessionHasErrors('planned_summary');

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
            $this->assertNull($lesson->plan);
        });
    }

    #[Test]
    public function a_lesson_can_be_marked_taught_from_preparation_without_a_plan(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->savePlan($lesson, '', LessonStatus::Taught)->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonStatus::Taught, $lesson->refresh()->status);
            $this->assertSame('', $lesson->plan?->planned_summary);
        });
    }

    #[Test]
    public function a_prepared_lesson_can_return_to_preparation(): void
    {
        $lesson = $this->lessonFor($this->teacher, attributes: ['status' => LessonStatus::Prepared]);

        $this->savePlan($lesson, 'Plano reaberto.', LessonStatus::Preparation)->assertRedirect();

        $this->inTenant(
            $this->organization,
            fn () => $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status),
        );
    }

    #[Test]
    public function a_taught_lesson_cannot_return_to_an_earlier_status(): void
    {
        $lesson = $this->lessonFor($this->teacher, attributes: ['status' => LessonStatus::Taught]);

        $this->savePlan($lesson, 'Tentativa de reabertura.', LessonStatus::Prepared)
            ->assertSessionHasErrors('target_status');

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonStatus::Taught, $lesson->refresh()->status);
            $this->assertNull($lesson->plan);
        });
    }

    #[Test]
    public function editing_the_plan_of_a_taught_lesson_is_audited_without_its_text(): void
    {
        $lesson = $this->lessonFor($this->teacher, attributes: ['status' => LessonStatus::Taught]);
        $confidentialText = 'Texto planeado que não pode constar na auditoria.';

        $this->savePlan($lesson, $confidentialText, LessonStatus::Taught)->assertRedirect();

        $this->inTenant($this->organization, function () use ($confidentialText, $lesson): void {
            $plan = $lesson->plan()->sole();
            $auditEvent = AuditEvent::query()->where('event', 'lesson.plan_revised')->sole();

            $this->assertSame($lesson->id, $auditEvent->subject_id);
            $this->assertSame($this->teacher->id, $auditEvent->causer_id);
            $this->assertSame([
                'lesson_status' => LessonStatus::Taught->value,
                'plan_id' => $plan->id,
            ], $auditEvent->properties);

            $serializedAudit = json_encode([
                'summary' => $auditEvent->summary,
                'properties' => $auditEvent->properties,
            ], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($confidentialText, $serializedAudit);
        });
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden(): void
    {
        $lesson = $this->lessonFor($this->teacher);
        $unassignedTeacher = User::factory()->create();
        $this->organization->members()->attach($unassignedTeacher, ['joined_at' => now()]);

        $this->actingAs($unassignedTeacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/lessons/{$lesson->ulid}/plan", [
                'planned_summary' => 'Não autorizado.',
                'target_status' => LessonStatus::Preparation->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_plans', 0);
    }

    #[Test]
    public function a_lesson_from_another_organization_is_not_resolved(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $otherLesson = $this->lessonFor($otherTeacher, $otherOrganization);

        $this->asTeacher()->put("/lessons/{$otherLesson->ulid}/plan", [
            'planned_summary' => 'Tenant errado.',
            'target_status' => LessonStatus::Preparation->value,
        ])->assertNotFound();

        $this->assertDatabaseCount('lesson_plans', 0);
    }

    #[Test]
    public function impersonation_blocks_saving_a_plan(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->actingAs($this->teacher)
            ->withSession([
                'organization_id' => $this->organization->id,
                'impersonator_id' => 999,
            ])
            ->put("/lessons/{$lesson->ulid}/plan", [
                'planned_summary' => 'Bloqueado.',
                'target_status' => LessonStatus::Preparation->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_plans', 0);
    }

    private function savePlan(Lesson $lesson, string $plannedSummary, LessonStatus $targetStatus): TestResponse
    {
        return $this->asTeacher()->put("/lessons/{$lesson->ulid}/plan", [
            'planned_summary' => $plannedSummary,
            'target_status' => $targetStatus->value,
        ]);
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
