<?php

namespace Tests\Feature\Dashboard;

use App\Models\Lesson;
use App\Models\RecurringLessonSlot;
use App\Services\Dashboard\ItemState;
use App\Services\Dashboard\TeacherDayAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dashboard\Concerns\BuildsDashboardFixtures;
use Tests\TestCase;

class TeacherDayAgendaTest extends TestCase
{
    use BuildsDashboardFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboardFixtures();
    }

    #[Test]
    public function the_day_lists_occurrences_lessons_and_sessions_in_order_with_their_state_and_the_weeks_bounds(): void
    {
        $ninth = $this->anotherClass('9.º C');
        $this->slotFor($this->schoolClass, 1, '08:30', '10:00');   // segunda, já materializada, com sumário
        $this->slotFor($ninth, 1, '10:20', '11:50');               // segunda, ainda por abrir — terminou às 11:50
        $this->slotFor($this->schoolClass, 3, '14:00', '15:00');   // quarta: só conta para os limites
        $taught = $this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90);
        $this->summarise($taught);
        $manual = $this->lessonFor($this->schoolClass, '2026-10-26 17:00:00');   // inserida à mão, ainda por começar
        $this->activity('Reunião de departamento', 1, '14:30', '15:30');

        $lessonsBefore = Lesson::withoutGlobalScopes()->count();
        $day = $this->within(fn () => app(TeacherDayAgenda::class)->for($this->context()));

        $this->assertTrue($day['available']);
        $this->assertSame('2026-10-26', $day['date']);
        $this->assertSame(['start' => '08:00', 'end' => '18:00'], $day['bounds']);

        $this->assertSame(
            [['08:30', 'lesson', ItemState::DONE], ['10:20', 'lesson', ItemState::UNOPENED], ['14:30', 'activity', ItemState::PENDING], ['17:00', 'lesson', ItemState::UPCOMING]],
            array_map(fn (array $item): array => [$item['start_time'], $item['kind'], $item['state']], $day['items']),
        );

        $this->assertSame($taught->ulid, $day['items'][0]['lesson']['ulid']);
        $this->assertTrue($day['items'][0]['lesson']['has_summary']);
        $this->assertNull($day['items'][1]['lesson'], 'uma ocorrência por abrir não tem aula, logo não é um sumário em falta');
        $this->assertSame('9.º C', $day['items'][1]['title']);
        $this->assertSame($manual->ulid, $day['items'][3]['lesson']['ulid']);
        $this->assertSame('Reunião de departamento', $day['items'][2]['title']);

        $this->assertSame(['lessons' => 3, 'summaries_registered' => 1, 'sessions' => 1, 'sessions_done' => 0, 'absences_recorded' => 0], $day['totals']);
        $this->assertSame($lessonsBefore, Lesson::withoutGlobalScopes()->count(), 'o painel nunca materializa aulas');
    }

    #[Test]
    public function a_base_account_has_no_day_agenda_and_no_lesson_is_created_for_it(): void
    {
        $this->slotFor($this->schoolClass, 1, '08:30', '10:00');
        $this->downgradeToBase();

        $day = $this->within(fn () => app(TeacherDayAgenda::class)->for($this->context()));

        $this->assertFalse($day['available']);
        $this->assertSame([], $day['items']);
        $this->assertNull($day['bounds']);
        $this->assertSame(0, Lesson::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_day_follows_the_lisbon_clock_at_midnight(): void
    {
        $this->lessonFor($this->schoolClass, '2026-10-26 23:30:00', 40);

        $this->freeze('2026-10-26 23:45:00');
        $late = $this->within(fn () => app(TeacherDayAgenda::class)->for($this->context()));
        $this->assertCount(1, $late['items']);
        $this->assertSame(ItemState::CURRENT, $late['items'][0]['state']);

        $this->freeze('2026-10-27 00:01:00');
        $nextDay = $this->within(fn () => app(TeacherDayAgenda::class)->for($this->context()));
        $this->assertSame('2026-10-27', $nextDay['date']);
        $this->assertSame([], $nextDay['items']);
    }

    #[Test]
    public function an_absence_of_the_teacher_or_an_external_activity_closes_the_occurrence_without_a_summary(): void
    {
        $this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90, [
            'outcome' => 'teacher_absent', 'outcome_reason' => 'other', 'outcome_recorded_at' => '2026-10-26 09:00:00', 'outcome_recorded_by' => $this->teacher->id,
        ]);
        $this->lessonFor($this->schoolClass, '2026-10-26 10:20:00', 90, [
            'outcome' => 'class_external_activity', 'outcome_note' => 'Visita de estudo', 'outcome_recorded_at' => '2026-10-26 10:00:00', 'outcome_recorded_by' => $this->teacher->id,
        ]);
        $this->lessonFor($this->schoolClass, '2026-10-26 12:00:00', 50, ['outcome' => 'taught', 'outcome_recorded_at' => '2026-10-26 12:50:00', 'outcome_recorded_by' => $this->teacher->id]);

        $day = $this->within(fn () => app(TeacherDayAgenda::class)->for($this->context()));

        $this->assertSame(
            [['teacher_absent', ItemState::DONE], ['class_external_activity', ItemState::DONE], ['taught', ItemState::PENDING]],
            array_map(fn (array $item): array => [$item['lesson']['outcome'], $item['state']], $day['items']),
        );
        $this->assertFalse($day['items'][1]['lesson']['has_summary']);
    }

    #[Test]
    public function two_slots_of_the_same_class_at_the_same_time_each_keep_their_own_lesson(): void
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $slotOne = $this->inTenant($this->organization, fn () => RecurringLessonSlot::create(['class_id' => $this->schoolClass->id, 'class_group_id' => $t1->id, 'day_of_week' => 1, 'starts_at' => '08:30', 'ends_at' => '10:00']));
        $slotTwo = $this->inTenant($this->organization, fn () => RecurringLessonSlot::create(['class_id' => $this->schoolClass->id, 'class_group_id' => $t2->id, 'day_of_week' => 1, 'starts_at' => '08:30', 'ends_at' => '10:00']));
        $this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90, ['recurring_lesson_slot_id' => $slotOne->id, 'class_group_id' => $t1->id]);
        $second = $this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90, ['recurring_lesson_slot_id' => $slotTwo->id, 'class_group_id' => $t2->id]);
        $this->summarise($second);

        $day = $this->within(fn () => app(TeacherDayAgenda::class)->for($this->context()));

        $this->assertCount(2, $day['items']);
        $this->assertSame(['7.º A · T1', '7.º A · T2'], array_column($day['items'], 'title'));
        $this->assertSame([ItemState::PENDING, ItemState::DONE], array_column($day['items'], 'state'));
        $this->assertSame(2, $day['totals']['lessons']);
    }
}
