<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Basear no sumário anterior" — a read-only convenience endpoint. Nothing
 * in this file should ever assert a row was written; every test that
 * exercises the endpoint also asserts the summary table was left alone.
 */
class PreviousLessonSummaryTest extends TestCase
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
    public function it_returns_the_most_recent_earlier_lessons_summary_for_the_same_class(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->summarizedLesson($schoolClass, '2026-10-01 09:00:00', 'Sumário antigo.');
        $recent = $this->summarizedLesson($schoolClass, '2026-10-08 09:00:00', 'Sumário mais recente.', [
            'private_notes' => 'Nota.',
            'resources' => 'Recurso.',
            'homework' => 'TPC.',
        ]);
        $current = $this->lessonFor($schoolClass, '2026-10-15 09:00:00');

        $this->assertDatabaseCount('lesson_summaries', 2);

        $this->asTeacher()->get("/lessons/{$current->ulid}/previous-summary")
            ->assertOk()
            ->assertExactJson([
                'content' => 'Sumário mais recente.',
                'private_notes' => 'Nota.',
                'resources' => 'Recurso.',
                'homework' => 'TPC.',
            ]);

        // Read-only: nothing was created or changed by asking.
        $this->assertDatabaseCount('lesson_summaries', 2);
        $this->inTenant($this->organization, fn () => $this->assertSame('Sumário mais recente.', $recent->summary()->sole()->content));
    }

    #[Test]
    public function it_skips_earlier_lessons_that_have_no_summary_of_their_own(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->summarizedLesson($schoolClass, '2026-10-01 09:00:00', 'Único sumário existente.');
        $this->lessonFor($schoolClass, '2026-10-08 09:00:00'); // No summary.
        $current = $this->lessonFor($schoolClass, '2026-10-15 09:00:00');

        $this->asTeacher()->get("/lessons/{$current->ulid}/previous-summary")
            ->assertOk()
            ->assertJson(['content' => 'Único sumário existente.']);
    }

    #[Test]
    public function it_returns_no_content_when_no_earlier_summary_exists(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-15 09:00:00');

        $this->asTeacher()->get("/lessons/{$lesson->ulid}/previous-summary")
            ->assertStatus(204);

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function a_lesson_from_another_organization_is_not_resolved(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $otherClass = $this->schoolClassFor($otherTeacher, $otherOrganization);
        $otherLesson = $this->lessonFor($otherClass, '2026-10-15 09:00:00', organization: $otherOrganization);

        $this->asTeacher()->get("/lessons/{$otherLesson->ulid}/previous-summary")
            ->assertNotFound();
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-15 09:00:00');
        $unassignedTeacher = User::factory()->create();
        $this->organization->members()->attach($unassignedTeacher, ['joined_at' => now()]);

        $this->actingAs($unassignedTeacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->get("/lessons/{$lesson->ulid}/previous-summary")
            ->assertForbidden();
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id]);
    }

    private function schoolClassFor(User $teacher, ?Organization $organization = null): SchoolClass
    {
        $organization ??= $this->organization;

        return $this->inTenant($organization, function () use ($organization, $teacher): SchoolClass {
            $schoolClass = SchoolClass::factory()
                ->recycle($organization)
                ->create([
                    'academic_year_id' => AcademicYear::factory()
                        ->recycle($organization)
                        ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])
                        ->id,
                ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    private function lessonFor(
        SchoolClass $schoolClass,
        string $startsAt,
        LessonStatus $status = LessonStatus::Preparation,
        ?Organization $organization = null,
    ): Lesson {
        $organization ??= $this->organization;

        return $this->inTenant($organization, fn (): Lesson => Lesson::create([
            'class_id' => $schoolClass->id,
            'starts_at' => $startsAt,
            'ends_at' => null,
            'status' => $status,
            'created_by' => $this->teacher->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function summarizedLesson(SchoolClass $schoolClass, string $startsAt, string $content, array $extra = []): Lesson
    {
        $lesson = $this->lessonFor($schoolClass, $startsAt);

        $this->inTenant($this->organization, fn () => LessonSummary::create(array_merge([
            'lesson_id' => $lesson->id,
            'content' => $content,
        ], $extra)));

        return $lesson;
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
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
