<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Http\Controllers\LessonScheduleController;
use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LessonScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'organization'])->group(function (): void {
            Route::get('/_test/lessons/schedule', fn () => response()->noContent());
            Route::post('/_test/lesson-slots', [LessonScheduleController::class, 'store']);
            Route::put('/_test/lesson-slots/{recurringLessonSlot}', [LessonScheduleController::class, 'update']);
            Route::delete('/_test/lesson-slots/{recurringLessonSlot}', [LessonScheduleController::class, 'destroy']);
            Route::post('/_test/classes/{class}/lessons/materialize', [LessonScheduleController::class, 'materialize']);
        });

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);
    }

    #[Test]
    public function an_assigned_teacher_can_create_and_update_a_slot_for_their_class(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post('/_test/lesson-slots', $this->slotPayload($schoolClass))
            ->assertRedirect();

        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::query()->sole());

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '10:00',
                'ends_at' => '10:50',
            ]))
            ->assertRedirect();

        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());

        $this->assertSame($schoolClass->id, $slot->class_id);
        $this->assertSame(3, $slot->day_of_week);
        $this->assertStringStartsWith('10:00', $slot->starts_at);
        $this->assertStringStartsWith('10:50', $slot->ends_at);
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $unassignedTeacher = User::factory()->create();
        $this->organization->members()->attach($unassignedTeacher, ['joined_at' => now()]);

        $this->actingAs($unassignedTeacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post('/_test/lesson-slots', $this->slotPayload($schoolClass))
            ->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function a_class_from_another_organization_is_forbidden(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $otherClass = $this->schoolClassFor($otherTeacher, $otherOrganization);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post('/_test/lesson-slots', $this->slotPayload($otherClass))
            ->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function invalid_weekdays_and_non_increasing_times_are_rejected(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);

        foreach ([0, 8] as $invalidWeekday) {
            $this->actingAs($this->teacher)
                ->withSession(['organization_id' => $this->organization->id])
                ->post('/_test/lesson-slots', $this->slotPayload($schoolClass, ['day_of_week' => $invalidWeekday]))
                ->assertSessionHasErrors('day_of_week');
        }

        foreach (['09:30', '09:00'] as $invalidEndTime) {
            $this->actingAs($this->teacher)
                ->withSession(['organization_id' => $this->organization->id])
                ->post('/_test/lesson-slots', $this->slotPayload($schoolClass, ['ends_at' => $invalidEndTime]))
                ->assertSessionHasErrors('ends_at');
        }

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post('/_test/lesson-slots', $this->slotPayload($schoolClass, [
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-09-30',
            ]))
            ->assertSessionHasErrors('ends_on');

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function materialization_refuses_a_range_outside_the_class_academic_year(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);

        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse('2026-08-31'),
            CarbonImmutable::parse('2026-09-07'),
            $this->teacher,
        ));
    }

    #[Test]
    public function materialization_is_idempotent_for_multiple_slots(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);

        $this->inTenant($this->organization, function () use ($schoolClass): void {
            RecurringLessonSlot::create($this->slotAttributes($schoolClass, ['day_of_week' => 1]));
            RecurringLessonSlot::create($this->slotAttributes($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '14:00',
                'ends_at' => '14:50',
            ]));

            $action = app(MaterializeLessonsForRange::class);
            $from = CarbonImmutable::parse('2026-09-07');
            $to = CarbonImmutable::parse('2026-09-13');

            $this->assertCount(2, $action->execute($schoolClass, $from, $to, $this->teacher));
            $this->assertCount(2, $action->execute($schoolClass, $from, $to, $this->teacher));

            $this->assertSame(2, Lesson::query()->count());
            $this->assertSame(2, Lesson::query()->where('status', LessonStatus::Preparation)->count());
            $this->assertSame(
                ['2026-09-07 09:30:00', '2026-09-09 14:00:00'],
                Lesson::query()->orderBy('starts_at')->get()->map(
                    fn (Lesson $lesson): string => $lesson->starts_at->format('Y-m-d H:i:s'),
                )->all(),
            );
        });
    }

    #[Test]
    public function reading_the_schedule_does_not_materialize_lessons(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create($this->slotAttributes($schoolClass)));

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->get('/_test/lessons/schedule')
            ->assertNoContent();

        $this->assertDatabaseCount('lessons', 0);
    }

    #[Test]
    public function impersonation_blocks_every_schedule_mutation(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant(
            $this->organization,
            fn (): RecurringLessonSlot => RecurringLessonSlot::create($this->slotAttributes($schoolClass)),
        );
        $session = ['organization_id' => $this->organization->id, 'impersonator_id' => 999];

        $this->actingAs($this->teacher)->withSession($session)
            ->post('/_test/lesson-slots', $this->slotPayload($schoolClass))
            ->assertForbidden();
        $this->actingAs($this->teacher)->withSession($session)
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, ['day_of_week' => 2]))
            ->assertForbidden();
        $this->actingAs($this->teacher)->withSession($session)
            ->delete("/_test/lesson-slots/{$slot->ulid}")
            ->assertForbidden();
        $this->actingAs($this->teacher)->withSession($session)
            ->post("/_test/classes/{$schoolClass->ulid}/lessons/materialize", [
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertDatabaseCount('lessons', 0);
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function slotPayload(SchoolClass $schoolClass, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $schoolClass->id,
            'day_of_week' => 1,
            'starts_at' => '09:30',
            'ends_at' => '10:20',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function slotAttributes(SchoolClass $schoolClass, array $overrides = []): array
    {
        return array_merge($this->slotPayload($schoolClass), $overrides);
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
