<?php

namespace Tests\Feature\Dashboard;

use App\Models\InstrumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dashboard\Concerns\BuildsDashboardFixtures;
use Tests\TestCase;

/**
 * A página entrega ao ecrã o que o DashboardComposer sabe — e só isso: nada
 * calculado no controlador, nada inventado, e a diferença Base/Pro decidida
 * pelos módulos.
 */
class DashboardPageTest extends TestCase
{
    use BuildsDashboardFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboardFixtures();
    }

    #[Test]
    public function a_pro_teacher_receives_the_day_the_week_the_queue_and_the_classes(): void
    {
        $this->slotFor($this->schoolClass, 1, '08:30', '10:00');
        $this->summarise($this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90));
        $this->lessonFor($this->schoolClass, '2026-10-23 10:00:00');
        $this->enroll('Ana Costa');
        $this->instrumentFor($this->schoolClass, 'Ficha de leitura', '2026-10-20', InstrumentStatus::InCorrection);

        $this->asTeacher()->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('teacherName', $this->teacher->name)
            ->where('readiness.is_ready', fn ($ready) => is_bool($ready))
            ->where('dashboard.context.date', '2026-10-26')
            ->where('dashboard.context.modules.lessons', true)
            ->where('dashboard.context.class_count', 1)
            ->where('dashboard.day.available', true)
            ->where('dashboard.day.totals.lessons', 1)
            ->where('dashboard.day.totals.summaries_registered', 1)
            ->where('dashboard.week.has_timetable', true)
            ->where('dashboard.pending.counts.summaries_missing', 1)
            ->where('dashboard.pending.counts.corrections', 1)
            ->where('dashboard.classes.0.label', '7.º A')
            ->where('dashboard.classes.0.students', 1)
            ->where('dashboard.classes.0.pending.summaries_missing', 1)
            ->where('dashboard.period.period.label', '1.º Período')
            ->missing('classes')
            ->missing('totals')
            ->missing('recordAlerts'));
    }

    #[Test]
    public function a_base_teacher_receives_no_day_agenda_but_everything_else(): void
    {
        $this->slotFor($this->schoolClass, 1, '08:30', '10:00');
        $this->enroll('Ana Costa');
        $this->instrumentFor($this->schoolClass, 'Ficha de leitura', '2026-10-20', InstrumentStatus::InCorrection);
        $this->calendarEvent('Reunião de departamento', '2026-10-26', null, 'meeting', '14:30');
        $this->downgradeToBase();

        $this->asTeacher()->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('dashboard.context.modules.lessons', false)
            ->where('dashboard.day.available', false)
            ->where('dashboard.day.items', [])
            ->where('dashboard.week.has_timetable', false)
            ->where('dashboard.week.days.0.events.0.title', 'Reunião de departamento')
            ->where('dashboard.pending.summaries.available', false)
            ->where('dashboard.pending.counts.corrections', 1)
            ->where('dashboard.classes.0.next_lesson', null)
            ->where('dashboard.classes.0.absences_this_week', null)
            ->where('dashboard.period.period.day', 42));
    }
}
