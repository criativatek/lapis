<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MaterializeLessonsForWeek;
use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Lessons\WeeklyLessonsQuery;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WeeklyLessonsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function weekly_query_is_bounded_to_the_week_year_and_assigned_teacher(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $otherTeacher = User::factory()->create();
        $organization->members()->attach($otherTeacher, ['joined_at' => now()]);
        $otherClass = $this->schoolClass($organization, $year, $otherTeacher, 'Outra turma');
        $otherYear = $this->tenant($organization, fn () => AcademicYear::factory()->recycle($organization)->create([
            'starts_on' => '2025-09-01', 'ends_on' => '2026-09-30',
        ]));
        $otherYearClass = $this->schoolClass($organization, $otherYear, $teacher, 'Turma de outro ano');
        $foreignTeacher = User::factory()->create();
        $foreignOrganization = $foreignTeacher->personalOrganization();
        $foreignYear = $this->tenant($foreignOrganization, fn () => AcademicYear::factory()->recycle($foreignOrganization)->create([
            'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
        ]));
        $foreignClass = $this->schoolClass($foreignOrganization, $foreignYear, $foreignTeacher, 'Outra organizaÃ§Ã£o');

        $this->tenant($organization, function () use ($teacher, $schoolClass, $otherClass, $otherYearClass): void {
            $this->lesson($schoolClass, $teacher, '2026-09-07 09:00:00');
            $this->lesson($schoolClass, $teacher, '2026-09-13 09:00:00');
            $this->lesson($schoolClass, $teacher, '2026-09-14 09:00:00');
            $this->lesson($otherClass, $teacher, '2026-09-08 09:00:00');
            $this->lesson($otherYearClass, $teacher, '2026-09-09 09:00:00');
        });
        $this->tenant($foreignOrganization, fn () => $this->lesson($foreignClass, $foreignTeacher, '2026-09-10 09:00:00'));

        $rows = $this->tenant($organization, fn (): array => app(WeeklyLessonsQuery::class)->for(
            $teacher, $year, CarbonImmutable::parse('2026-09-07'),
        ));

        $this->assertCount(2, $rows);
        $this->assertSame(['2026-09-07', '2026-09-13'], array_map(
            fn (array $row): string => substr($row['starts_at'], 0, 10), $rows,
        ));
    }

    #[Test]
    public function weekly_query_has_a_bounded_query_count(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $secondClass = $this->schoolClass($organization, $year, $teacher, '7.Âº B');
        $this->tenant($organization, function () use ($schoolClass, $secondClass, $teacher): void {
            foreach (range(8, 13) as $hour) {
                $class = $hour % 2 === 0 ? $schoolClass : $secondClass;
                $lesson = $this->lesson($class, $teacher, sprintf('2026-09-07 %02d:00:00', $hour));
                LessonSummary::create(['lesson_id' => $lesson->id, 'content' => str_repeat('SumÃ¡rio ', 40)]);
            }
        });

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $this->tenant($organization, fn (): array => app(WeeklyLessonsQuery::class)->for(
            $teacher, $year, CarbonImmutable::parse('2026-09-07'),
        ));

        $this->assertLessThanOrEqual(5, $count);
    }

    #[Test]
    public function weekly_query_excludes_another_academic_year_and_organization(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $otherYear = $this->tenant($organization, fn () => AcademicYear::factory()->recycle($organization)->create([
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]));
        $otherYearClass = $this->schoolClass($organization, $otherYear, $teacher, 'Outro ano');

        $this->tenant($organization, function () use ($schoolClass, $otherYearClass, $teacher): void {
            $this->lesson($schoolClass, $teacher, '2026-09-09 09:00:00');
            $this->lesson($otherYearClass, $teacher, '2026-09-09 10:00:00');
        });

        [$foreignTeacher, $foreignOrganization, , $foreignClass] = $this->context();
        $this->tenant($foreignOrganization, fn () => $this->lesson($foreignClass, $foreignTeacher, '2026-09-09 11:00:00'));

        $rows = $this->tenant($organization, fn (): array => app(WeeklyLessonsQuery::class)->for(
            $teacher,
            $year,
            CarbonImmutable::parse('2026-09-09'),
        ));

        $this->assertCount(1, $rows);
        $this->assertSame($schoolClass->ulid, $rows[0]['school_class']['ulid']);
    }

    #[Test]
    public function lessons_index_is_the_real_weekly_page_and_excludes_another_teachers_lessons(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $lesson = $this->tenant($organization, fn (): Lesson => $this->lesson($schoolClass, $teacher, '2026-09-07 09:00:00'));
        $otherTeacher = User::factory()->create();
        $organization->members()->attach($otherTeacher, ['joined_at' => now()]);
        $otherClass = $this->schoolClass($organization, $year, $otherTeacher, 'Outra turma');
        $this->tenant($organization, fn () => $this->lesson($otherClass, $otherTeacher, '2026-09-08 09:00:00'));

        $this->actingAs($teacher)->withSession([
            'organization_id' => $organization->id,
            'academic_year_id' => $year->id,
        ])->get('/lessons?week=2026-09-09')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('lessons/Index')
                ->where('week.start', '2026-09-07')
                ->has('lessons', 1)
                ->where('lessons.0.ulid', $lesson->ulid),
        );
    }

    #[Test]
    public function opening_the_week_shows_the_scheduled_lessons_without_a_manual_step(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $this->tenant($organization, fn () => RecurringLessonSlot::create($this->slot($schoolClass, 1)));

        $this->actingAs($teacher)->withSession([
            'organization_id' => $organization->id,
            'academic_year_id' => $year->id,
        ])->get('/lessons?week=2026-09-09')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('lessons/Index')
                ->where('week.start', '2026-09-07')
                ->has('lessons', 1)
                ->where('lessons.0.starts_at', fn (string $startsAt): bool => str_starts_with($startsAt, '2026-09-07')),
        );

        $this->assertDatabaseCount('lessons', 1);
    }

    #[Test]
    public function a_read_only_lessons_module_shows_existing_lessons_but_materializes_nothing_new(): void
    {
        // §Lote 2: a suspended subscription puts `lessons` in ReadOnly, and
        // `RequireModule` now lets a GET through for that state. Without the
        // guard in LessonWeekController::index(), that GET would still create
        // NEW Lesson rows — a real correctness bug ReadOnly would otherwise
        // introduce. A lesson from BEFORE the suspension must still show.
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $existing = $this->tenant($organization, fn (): Lesson => $this->lesson($schoolClass, $teacher, '2026-09-07 09:00:00'));
        $this->tenant($organization, fn () => RecurringLessonSlot::create($this->slot($schoolClass, 3)));

        $this->suspendPro($organization);

        $this->actingAs($teacher)->withSession([
            'organization_id' => $organization->id,
            'academic_year_id' => $year->id,
        ])->get('/lessons?week=2026-09-09')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('lessons/Index')
                ->has('lessons', 1)
                ->where('lessons.0.ulid', $existing->ulid),
        );

        // The recurring slot falls on Wednesday (day 3) of this same week —
        // Allowed would have materialized it (see the tests above); ReadOnly
        // must not.
        $this->assertDatabaseCount('lessons', 1);
    }

    #[Test]
    public function opening_an_adjacent_week_materializes_only_that_week(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $this->tenant($organization, fn () => RecurringLessonSlot::create($this->slot($schoolClass, 1)));
        $session = ['organization_id' => $organization->id, 'academic_year_id' => $year->id];

        $this->actingAs($teacher)->withSession($session)->get('/lessons?week=2026-09-07')->assertOk();
        $this->assertDatabaseCount('lessons', 1);

        $this->actingAs($teacher)->withSession($session)->get('/lessons?week=2026-09-14')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('lessons', 1));

        // A semana seguinte acrescenta exatamente uma aula — a sua — sem
        // tocar na anterior nem materializar o ano letivo inteiro.
        $this->assertDatabaseCount('lessons', 2);
        $this->assertDatabaseHas('lessons', ['starts_at' => '2026-09-07 09:00:00']);
        $this->assertDatabaseHas('lessons', ['starts_at' => '2026-09-14 09:00:00']);
        $this->assertDatabaseMissing('lessons', ['starts_at' => '2026-09-21 09:00:00']);
    }

    #[Test]
    public function materializing_a_week_uses_every_scheduled_class_and_is_idempotent(): void
    {
        [$teacher, $organization, $year, $firstClass] = $this->context();
        $secondClass = $this->schoolClass($organization, $year, $teacher, 'Segunda turma');
        $withoutSchedule = $this->schoolClass($organization, $year, $teacher, 'Sem horÃ¡rio');
        $otherYear = $this->tenant($organization, fn () => AcademicYear::factory()->recycle($organization)->create([
            'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        ]));
        $otherYearClass = $this->schoolClass($organization, $otherYear, $teacher, 'Outro ano letivo');

        $this->tenant($organization, function () use ($firstClass, $secondClass, $otherYearClass): void {
            RecurringLessonSlot::create($this->slot($firstClass, 1));
            RecurringLessonSlot::create($this->slot($secondClass, 2));
            RecurringLessonSlot::create($this->slot($otherYearClass, 3));
        });

        $action = app(MaterializeLessonsForWeek::class);
        $this->tenant($organization, fn () => $action->execute($teacher, $year, CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-13')));
        $this->tenant($organization, fn () => $action->execute($teacher, $year, CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-13')));

        $this->assertDatabaseCount('lessons', 2);
        $this->assertDatabaseMissing('lessons', ['class_id' => $withoutSchedule->id]);
    }

    #[Test]
    public function impersonation_blocks_week_materialization(): void
    {
        [$teacher, $organization, $year] = $this->context();

        $this->actingAs($teacher)->withSession([
            'organization_id' => $organization->id,
            'academic_year_id' => $year->id,
            'impersonator_id' => 999,
        ])->post('/lessons/materialize-week', ['from' => '2026-09-07', 'to' => '2026-09-13'])
            ->assertForbidden();
    }

    #[Test]
    public function materializing_a_week_does_not_touch_scheduled_classes_from_another_year(): void
    {
        [$teacher, $organization, $year] = $this->context();
        $otherYear = $this->tenant($organization, fn () => AcademicYear::factory()->recycle($organization)->create([
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]));
        $otherYearClass = $this->schoolClass($organization, $otherYear, $teacher, 'Outro ano');
        $this->tenant($organization, fn () => RecurringLessonSlot::create([
            ...$this->slot($otherYearClass, 3),
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]));

        $this->tenant($organization, fn () => app(MaterializeLessonsForWeek::class)->execute(
            $teacher,
            $year,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-13'),
        ));

        $this->assertDatabaseMissing('lessons', ['class_id' => $otherYearClass->id]);
    }

    #[Test]
    public function a_base_organization_can_open_a_class_without_receiving_lesson_slots(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $year = $this->tenant($organization, fn () => AcademicYear::factory()->recycle($organization)->create());
        $schoolClass = $this->schoolClass($organization, $year, $teacher, '7.º Base');
        $this->tenant($organization, fn () => RecurringLessonSlot::create($this->slot($schoolClass, 1)));

        $this->actingAs($teacher)->withSession(['organization_id' => $organization->id])
            ->get("/classes/{$schoolClass->ulid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('classes/Show')
                ->where('recurringLessonSlots', null));
    }

    private function context(): array
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $this->subscribeToPro($organization);
        $year = $this->tenant($organization, fn () => AcademicYear::factory()->recycle($organization)->active()->create([
            'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
        ]));
        $class = $this->schoolClass($organization, $year, $teacher, '7.Âº A');

        return [$teacher, $organization, $year, $class];
    }

    private function schoolClass(Organization $organization, AcademicYear $year, User $teacher, string $label): SchoolClass
    {
        return $this->tenant($organization, function () use ($organization, $year, $teacher, $label): SchoolClass {
            $class = SchoolClass::factory()->recycle($organization)->create(['academic_year_id' => $year->id, 'label' => $label]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return $class;
        });
    }

    private function lesson(SchoolClass $class, User $teacher, string $startsAt): Lesson
    {
        return Lesson::create(['class_id' => $class->id, 'starts_at' => $startsAt, 'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes(50), 'status' => LessonStatus::Preparation, 'created_by' => $teacher->id]);
    }

    private function slot(SchoolClass $class, int $day): array
    {
        return ['class_id' => $class->id, 'day_of_week' => $day, 'starts_at' => '09:00', 'ends_at' => '09:50', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'];
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $organization->id)->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create(['organization_id' => $organization->id, 'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id, 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::now()->subDay()]);
        app(Entitlements::class)->flush();
    }

    /**
     * The exact shape `ChangeOrganizationPlan::suspend()` leaves behind:
     * status flipped to Suspended, `ends_at` left untouched (open) — the
     * "most recent subscription overall is Suspended" case that resolves to
     * `ReadOnly` rather than `Locked`.
     */
    private function suspendPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->update(['status' => SubscriptionStatus::Suspended]);
        app(Entitlements::class)->flush();
    }

    private function tenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
