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
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LessonSummaryTest extends TestCase
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
    public function creating_a_summary_does_not_mark_the_lesson_as_taught(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'content' => 'Introdução aos números racionais.',
        ])->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
            $this->assertSame('Introdução aos números racionais.', $lesson->summary?->content);
            $this->assertNull($lesson->summary->reviewed_at);
            $this->assertNull($lesson->summary->reviewed_by);
        });
    }

    #[Test]
    public function editing_and_reopening_a_lesson_preserves_the_saved_summary(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'content' => 'Primeira versão.',
        ])->assertRedirect();
        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'content' => 'Versão final do sumário.',
        ])->assertRedirect();

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('lessons/Show')
                ->where('lesson.ulid', $lesson->ulid)
                ->where('lesson.summary.content', 'Versão final do sumário.'));

        $this->assertDatabaseCount('lesson_summaries', 1);
    }

    #[Test]
    public function an_empty_summary_is_rejected(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'content' => '   ',
        ])->assertSessionHasErrors('content');

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function a_summary_larger_than_the_safe_text_column_limit_is_rejected(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'content' => str_repeat('á', 16001),
        ])->assertSessionHasErrors('content');

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden(): void
    {
        $lesson = $this->lessonFor($this->teacher);
        $unassignedTeacher = User::factory()->create();
        $this->organization->members()->attach($unassignedTeacher, ['joined_at' => now()]);

        $this->actingAs($unassignedTeacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/lessons/{$lesson->ulid}/summary", ['content' => 'Não autorizado.'])
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function a_lesson_from_another_organization_is_not_resolved(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $otherLesson = $this->lessonFor($otherTeacher, $otherOrganization);

        $this->asTeacher()
            ->put("/lessons/{$otherLesson->ulid}/summary", ['content' => 'Tenant errado.'])
            ->assertNotFound();

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function impersonation_blocks_saving_a_summary(): void
    {
        $lesson = $this->lessonFor($this->teacher);

        $this->actingAs($this->teacher)
            ->withSession([
                'organization_id' => $this->organization->id,
                'impersonator_id' => 999,
            ])
            ->put("/lessons/{$lesson->ulid}/summary", ['content' => 'Bloqueado.'])
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function editing_a_taught_lesson_marks_the_summary_reviewed_and_audits_without_its_text(): void
    {
        $lesson = $this->lessonFor($this->teacher, attributes: ['status' => LessonStatus::Taught]);
        $this->inTenant($this->organization, fn (): LessonSummary => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => 'Texto anterior confidencial.',
        ]));
        Carbon::setTestNow('2026-10-08 12:30:00');

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'content' => 'Texto revisto que não pode constar na auditoria.',
        ])->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $auditEvent = AuditEvent::query()->where('event', 'lesson.summary_reviewed')->sole();

            $this->assertSame('2026-10-08 12:30:00', $summary->reviewed_at?->format('Y-m-d H:i:s'));
            $this->assertSame($this->teacher->id, $summary->reviewed_by);
            $this->assertSame($lesson->id, $auditEvent->subject_id);
            $this->assertSame($this->teacher->id, $auditEvent->causer_id);
            $this->assertSame([
                'lesson_status' => LessonStatus::Taught->value,
                'summary_id' => $summary->id,
            ], $auditEvent->properties);

            $serializedAudit = json_encode([
                'summary' => $auditEvent->summary,
                'properties' => $auditEvent->properties,
            ], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('Texto anterior confidencial.', $serializedAudit);
            $this->assertStringNotContainsString('Texto revisto que não pode constar na auditoria.', $serializedAudit);
        });
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
