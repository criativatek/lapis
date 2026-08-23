<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LessonModelTest extends TestCase
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
    public function lesson_models_use_ulids_cast_values_and_expose_the_required_relationships(): void
    {
        $this->inTenant($this->organization, function (): void {
            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create();
            $slot = $this->createSlot($schoolClass);
            $lesson = $this->createLesson($schoolClass, $slot, [
                'ends_at' => '2026-09-07 10:20:00',
                'status' => LessonStatus::Prepared,
            ]);
            $summary = LessonSummary::create([
                'lesson_id' => $lesson->id,
                'content' => 'Números racionais.',
                'reviewed_at' => '2026-09-07 11:00:00',
                'reviewed_by' => $this->author->id,
            ]);

            $this->assertTrue(Str::isUlid($slot->ulid));
            $this->assertTrue(Str::isUlid($lesson->ulid));
            $this->assertTrue(Str::isUlid($summary->ulid));
            $this->assertSame('ulid', $slot->getRouteKeyName());
            $this->assertSame('ulid', $lesson->getRouteKeyName());
            $this->assertSame('ulid', $summary->getRouteKeyName());

            $this->assertSame(LessonStatus::Prepared, $lesson->status);
            $this->assertInstanceOf(CarbonImmutable::class, $lesson->starts_at);
            $this->assertInstanceOf(CarbonImmutable::class, $lesson->ends_at);
            $this->assertInstanceOf(CarbonImmutable::class, $slot->starts_on);
            $this->assertInstanceOf(CarbonImmutable::class, $slot->ends_on);
            $this->assertInstanceOf(CarbonImmutable::class, $summary->reviewed_at);

            $this->assertTrue($schoolClass->recurringLessonSlots->contains($slot));
            $this->assertTrue($schoolClass->lessons->contains($lesson));
            $this->assertTrue($lesson->summary->is($summary));
            $this->assertTrue($slot->schoolClass->is($schoolClass));
            $this->assertTrue($lesson->schoolClass->is($schoolClass));
            $this->assertTrue($lesson->recurringLessonSlot->is($slot));
        });
    }

    #[Test]
    public function tenant_owned_lesson_models_are_stamped_and_scoped_to_the_current_organization(): void
    {
        $otherAuthor = User::factory()->create();
        $otherOrganization = $otherAuthor->personalOrganization();

        $ownLesson = $this->inTenant($this->organization, function (): Lesson {
            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create();
            $slot = $this->createSlot($schoolClass);
            $lesson = $this->createLesson($schoolClass, $slot);
            $summary = LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'Sumário próprio.']);

            $this->assertSame($this->organization->id, $slot->organization_id);
            $this->assertSame($this->organization->id, $lesson->organization_id);
            $this->assertSame($this->organization->id, $summary->organization_id);

            return $lesson;
        });

        [$otherSlot, $otherLesson, $otherSummary] = $this->inTenant($otherOrganization, function () use ($otherAuthor, $otherOrganization): array {
            $schoolClass = SchoolClass::factory()->recycle($otherOrganization)->create();
            $slot = $this->createSlot($schoolClass);

            $lesson = $this->createLesson($schoolClass, $slot, ['created_by' => $otherAuthor->id]);
            $summary = LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'Sumário alheio.']);

            return [$slot, $lesson, $summary];
        });

        $this->inTenant($this->organization, function () use ($otherLesson, $otherSlot, $otherSummary, $ownLesson): void {
            $this->assertTrue(Lesson::query()->whereKey($ownLesson->id)->exists());
            $this->assertFalse(Lesson::query()->whereKey($otherLesson->id)->exists());
            $this->assertFalse(RecurringLessonSlot::query()->whereKey($otherSlot->id)->exists());
            $this->assertFalse(LessonSummary::query()->whereKey($otherSummary->id)->exists());
            $this->assertSame(1, Lesson::query()->count());
        });
    }

    #[Test]
    public function deleting_a_recurring_slot_preserves_the_lesson_and_nulls_its_slot_reference(): void
    {
        $this->inTenant($this->organization, function (): void {
            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create();
            $slot = $this->createSlot($schoolClass);
            $lesson = $this->createLesson($schoolClass, $slot);

            $slot->delete();

            $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'recurring_lesson_slot_id' => null]);
        });
    }

    #[Test]
    public function ordinary_occurrences_are_unique_for_class_slot_and_start_time(): void
    {
        $this->inTenant($this->organization, function (): void {
            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create();
            $slot = $this->createSlot($schoolClass);
            $this->createLesson($schoolClass, $slot);

            $this->expectException(QueryException::class);
            $this->createLesson($schoolClass, $slot);
        });
    }

    #[Test]
    public function extraordinary_lessons_may_share_a_start_time_without_a_recurring_slot(): void
    {
        $this->inTenant($this->organization, function (): void {
            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create();

            $first = $this->createLesson($schoolClass);
            $second = $this->createLesson($schoolClass);

            $this->assertNull($first->recurring_lesson_slot_id);
            $this->assertNull($second->recurring_lesson_slot_id);
            $this->assertSame(LessonStatus::Preparation, $first->status);
            $this->assertNotSame($first->id, $second->id);
        });
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    private function createSlot(SchoolClass $schoolClass): RecurringLessonSlot
    {
        return RecurringLessonSlot::create([
            'class_id' => $schoolClass->id,
            'day_of_week' => 1,
            'starts_at' => '09:30:00',
            'ends_at' => '10:20:00',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createLesson(SchoolClass $schoolClass, ?RecurringLessonSlot $slot = null, array $attributes = []): Lesson
    {
        return Lesson::create(array_merge([
            'class_id' => $schoolClass->id,
            'recurring_lesson_slot_id' => $slot?->id,
            'starts_at' => '2026-09-07 09:30:00',
            'status' => LessonStatus::Preparation,
            'created_by' => $this->author->id,
        ], $attributes));
    }
}
