<?php

namespace Tests\Feature\Dashboard;

use App\Models\AcademicCalendarException;
use App\Models\InstrumentStatus;
use App\Services\Dashboard\ItemState;
use App\Services\Dashboard\TeacherWeekAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dashboard\Concerns\BuildsDashboardFixtures;
use Tests\TestCase;

class TeacherWeekAgendaTest extends TestCase
{
    use BuildsDashboardFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboardFixtures();
    }

    #[Test]
    public function the_week_shows_five_days_with_lessons_events_tests_and_non_teaching_days(): void
    {
        $ninth = $this->anotherClass('9.º C');
        $this->slotFor($this->schoolClass, 1, '08:30', '10:00');
        $this->slotFor($ninth, 3, '12:00', '12:50');
        $this->slotFor($ninth, 5, '09:00', '10:00');   // sexta é não letiva: a ocorrência não existe
        $this->summarise($this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90));
        $this->calendarEvent('Conselho de turma', '2026-10-29', '2026-10-30', 'meeting', '18:00');
        $this->instrumentFor($ninth, 'Teste de leitura', '2026-10-29', InstrumentStatus::Prepared);
        $this->inTenant($this->organization, fn () => AcademicCalendarException::create([
            'academic_year_id' => $this->schoolClass->academic_year_id, 'type' => 'non_teaching_day', 'title' => 'Ponte',
            'starts_on' => '2026-10-30', 'ends_on' => '2026-10-30', 'source' => 'manual',
        ]));

        $week = $this->within(fn () => app(TeacherWeekAgenda::class)->for($this->context()));

        $this->assertSame(['2026-10-26', '2026-10-30'], [$week['week']['start'], $week['week']['end']]);
        $this->assertTrue($week['has_timetable']);
        $this->assertSame(['Seg', 'Ter', 'Qua', 'Qui', 'Sex'], array_column($week['days'], 'weekday'));
        $this->assertSame([true, false, false, false, false], array_column($week['days'], 'is_today'));

        [$monday, , $wednesday, $thursday, $friday] = $week['days'];
        $this->assertSame([['08:30', '7.º A', ItemState::DONE]], array_map(fn (array $lesson): array => [$lesson['start_time'], $lesson['class']['label'], $lesson['state']], $monday['lessons']));
        $this->assertSame([['12:00', '9.º C', ItemState::UPCOMING]], array_map(fn (array $lesson): array => [$lesson['start_time'], $lesson['class']['label'], $lesson['state']], $wednesday['lessons']));
        $this->assertSame([], $week['days'][1]['lessons'], 'terça não tem tempos');
        $this->assertSame(['instrument', 'calendar'], array_column($thursday['events'], 'kind'), 'o que não tem hora vem primeiro');
        $this->assertSame('Ponte', $friday['non_teaching']);
        $this->assertSame([], $friday['lessons'], 'a sexta não letiva não tem ocorrências');
        $this->assertSame(['calendar'], array_column($friday['events'], 'kind'), 'o acontecimento de dois dias aparece nos dois');
    }

    #[Test]
    public function without_the_lessons_module_the_week_keeps_its_events_and_assessment_dates(): void
    {
        $this->slotFor($this->schoolClass, 1, '08:30', '10:00');
        $this->calendarEvent('Reunião de departamento', '2026-10-27');
        $this->instrumentFor($this->schoolClass, 'Ficha de leitura', '2026-10-28', InstrumentStatus::InCorrection);
        $this->downgradeToBase();

        $week = $this->within(fn () => app(TeacherWeekAgenda::class)->for($this->context()));

        $this->assertFalse($week['has_timetable']);
        $this->assertSame([], $week['days'][0]['lessons']);
        $this->assertSame('Reunião de departamento', $week['days'][1]['events'][0]['title']);
        $this->assertSame('instrument', $week['days'][2]['events'][0]['kind']);
    }
}
