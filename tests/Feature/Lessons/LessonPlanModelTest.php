<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LessonPlanModelTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create();
        $this->organization = $this->author->personalOrganization();
    }

    #[Test]
    public function a_plan_belongs_to_a_lesson_and_uses_an_ulid_route_key(): void
    {
        $this->inTenant($this->organization, function (): void {
            $lesson = $this->createLesson();
            $plan = $this->createPlan($lesson);

            $this->assertTrue(Str::isUlid($plan->ulid));
            $this->assertSame('ulid', $plan->getRouteKeyName());
            $this->assertTrue($lesson->plan->is($plan));
            $this->assertTrue($plan->lesson->is($lesson));
            $this->assertTrue($plan->author->is($this->author));
        });
    }

    #[Test]
    public function a_lesson_without_a_plan_remains_valid(): void
    {
        $this->inTenant($this->organization, function (): void {
            $lesson = $this->createLesson();

            $this->assertNull($lesson->plan);
            $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
        });
    }

    #[Test]
    public function plans_are_stamped_and_scoped_to_the_current_organization(): void
    {
        $ownPlan = $this->inTenant($this->organization, function (): LessonPlan {
            $plan = $this->createPlan($this->createLesson());

            $this->assertSame($this->organization->id, $plan->organization_id);

            return $plan;
        });

        $otherAuthor = User::factory()->create();
        $otherOrganization = $otherAuthor->personalOrganization();
        $otherPlan = $this->inTenant($otherOrganization, function () use ($otherAuthor): LessonPlan {
            $lesson = $this->createLesson($otherAuthor);

            return $this->createPlan($lesson, $otherAuthor);
        });

        $this->inTenant($this->organization, function () use ($otherPlan, $ownPlan): void {
            $this->assertTrue(LessonPlan::query()->whereKey($ownPlan->id)->exists());
            $this->assertFalse(LessonPlan::query()->whereKey($otherPlan->id)->exists());
            $this->assertSame(1, LessonPlan::query()->count());
        });
    }

    #[Test]
    public function a_lesson_can_have_only_one_plan(): void
    {
        $this->inTenant($this->organization, function (): void {
            $lesson = $this->createLesson();
            $this->createPlan($lesson);

            $this->expectException(QueryException::class);
            $this->createPlan($lesson);
        });
    }

    #[Test]
    public function deleting_a_plan_does_not_delete_its_lesson(): void
    {
        $this->inTenant($this->organization, function (): void {
            $lesson = $this->createLesson();
            $plan = $this->createPlan($lesson);

            $plan->delete();

            $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
            $this->assertDatabaseMissing('lesson_plans', ['id' => $plan->id]);
        });
    }

    #[Test]
    public function deleting_a_lesson_with_a_plan_is_restricted(): void
    {
        $this->inTenant($this->organization, function (): void {
            $lesson = $this->createLesson();
            $this->createPlan($lesson);

            $this->expectException(QueryException::class);

            $lesson->delete();
        });
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    private function createLesson(?User $author = null): Lesson
    {
        $author ??= $this->author;
        $organization = $author->personalOrganization();
        $schoolClass = SchoolClass::factory()->recycle($organization)->create();

        return Lesson::create([
            'class_id' => $schoolClass->id,
            'starts_at' => '2026-09-07 09:30:00',
            'ends_at' => '2026-09-07 10:20:00',
            'status' => LessonStatus::Preparation,
            'created_by' => $author->id,
        ]);
    }

    private function createPlan(Lesson $lesson, ?User $author = null): LessonPlan
    {
        return LessonPlan::create([
            'lesson_id' => $lesson->id,
            'planned_summary' => 'Números racionais e operações.',
            'created_by' => ($author ?? $this->author)->id,
        ]);
    }
}
