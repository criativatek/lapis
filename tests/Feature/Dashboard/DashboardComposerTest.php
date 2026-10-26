<?php

namespace Tests\Feature\Dashboard;

use App\Models\AcademicYear;
use App\Models\InstrumentStatus;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dashboard\Concerns\BuildsDashboardFixtures;
use Tests\TestCase;

class DashboardComposerTest extends TestCase
{
    use BuildsDashboardFixtures, RefreshDatabase;

    /**
     * O orçamento de consultas do painel: as leituras fixas (ano, turmas,
     * tons, períodos, aulas e sessões da semana, os carregamentos da linha de
     * aula e uma consulta agrupada por facto em cada bloco) mais a projeção
     * do horário, que custa três consultas por turma dentro de
     * ScheduleOccurrences (o ano, as exceções do calendário e os tempos).
     * Medido com esta fixture — cada turma com tempo no horário, aluno, aula
     * de grupo sumariada hoje e elemento em correção: 57 consultas para 3
     * turmas e 76 para 8. O teste imprime o número real; uma subida numa PR
     * futura é uma regressão a explicar, e baixar o custo por turma passa por
     * deixar ScheduleOccurrences receber tempos e exceções já carregados.
     */
    private const QUERY_BUDGET_FIXED = 48;

    private const QUERY_BUDGET_PER_CLASS = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboardFixtures();
    }

    #[Test]
    public function the_composer_describes_the_context_and_hands_every_block_over(): void
    {
        $this->slotFor($this->schoolClass, 1, '08:30', '10:00');
        $this->lessonFor($this->schoolClass, '2026-10-23 10:00:00');

        $dashboard = $this->compose();

        $this->assertSame(['context', 'day', 'week', 'upcoming', 'pending', 'done', 'classes', 'period'], array_keys($dashboard));
        $this->assertSame('2026-10-26', $dashboard['context']['date']);
        $this->assertSame('segunda-feira', $dashboard['context']['weekday']);
        $this->assertSame('1.º Período', $dashboard['context']['period']['label']);
        $this->assertSame(9, $dashboard['context']['week_number']);
        $this->assertTrue($dashboard['context']['modules']['lessons']);
        $this->assertTrue($dashboard['day']['available']);
        $this->assertSame(1, $dashboard['pending']['counts']['summaries_missing']);
        $this->assertSame(1, $dashboard['classes'][0]['pending']['summaries_missing'], 'os cartões recebem as pendências por turma');
    }

    #[Test]
    public function an_archived_class_and_another_organization_never_reach_the_dashboard(): void
    {
        $archived = $this->anotherClass('8.º D');
        $this->lessonFor($archived, '2026-10-23 11:00:00');
        $this->instrumentFor($archived, 'Ficha', '2026-10-20', InstrumentStatus::InCorrection);
        $this->inTenant($this->organization, fn () => $archived->update(['archived_at' => now()]));

        // A mesma professora, noutra organização, com dados iguais aos dela.
        $stranger = User::factory()->create();
        $foreign = $stranger->personalOrganization();
        $this->subscribeToPro($foreign);
        app(CurrentOrganization::class)->runFor($foreign, function () use ($stranger, $foreign): void {
            $year = AcademicYear::factory()->recycle($foreign)->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
            $class = SchoolClass::factory()->recycle($foreign)->create(['academic_year_id' => $year->id, 'label' => '7.º A']);
            $class->teachers()->attach($stranger, ['role' => 'owner']);
            Lesson::create(['class_id' => $class->id, 'starts_at' => '2026-10-23 10:00:00', 'ends_at' => '2026-10-23 10:50:00', 'status' => 'preparation', 'created_by' => $stranger->id]);
        });

        $dashboard = $this->compose();

        $this->assertSame(1, $dashboard['context']['class_count']);
        $this->assertSame(['7.º A'], array_column($dashboard['classes'], 'label'));
        $this->assertSame(0, $dashboard['pending']['counts']['summaries_missing']);
        $this->assertSame(0, $dashboard['pending']['counts']['corrections']);
    }

    #[Test]
    public function a_colleagues_class_in_the_same_organization_is_not_the_teachers_work(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $theirs = $this->anotherClass('11.º F', $colleague);
        $this->slotFor($theirs, 1, '08:30', '10:00');
        $this->lessonFor($theirs, '2026-10-23 10:00:00');
        $this->enroll('Aluno deles', [], $theirs);
        $this->instrumentFor($theirs, 'Ficha deles', '2026-10-20', InstrumentStatus::InCorrection);
        $this->instrumentFor($theirs, 'Teste deles', '2026-10-29', InstrumentStatus::Prepared);

        $dashboard = $this->compose();

        $this->assertSame(['7.º A'], array_column($dashboard['classes'], 'label'));
        $this->assertSame([], $dashboard['day']['items']);
        $this->assertSame(0, $dashboard['pending']['counts']['summaries_missing']);
        $this->assertSame(0, $dashboard['pending']['counts']['corrections']);
        $this->assertSame(['period_end'], array_column($dashboard['upcoming'], 'kind'));
        $this->assertSame([], $dashboard['period']['marks']);
    }

    #[Test]
    public function composing_the_dashboard_never_materializes_a_lesson(): void
    {
        $this->slotFor($this->schoolClass, 1, '08:30', '10:00');
        $this->slotFor($this->anotherClass('9.º C'), 2, '10:20', '11:50');

        $this->compose();

        $this->assertSame(0, Lesson::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_dashboard_stays_inside_its_query_budget_with_eight_classes(): void
    {
        $classes = [$this->schoolClass];

        foreach (['7.º B', '8.º A', '8.º D', '9.º C', '9.º E', '10.º A', '12.º B'] as $label) {
            $classes[] = $this->anotherClass($label);
        }

        foreach ($classes as $index => $class) {
            $this->slotFor($class, 1 + $index % 5, '08:30', '10:00');
            $this->enroll('Aluno '.$index, [], $class);
            $this->lessonFor($class, '2026-10-23 10:00:00');
            $this->instrumentFor($class, 'Ficha', '2026-10-20', InstrumentStatus::InCorrection);
            // Uma aula de grupo com sumário e assiduidade de hoje por turma: é o
            // que faria um N+1 no «arrumado» (contextLabel lê o grupo).
            $group = $this->makeGroup('T1', $class);
            $lesson = $this->lessonFor($class, '2026-10-26 08:30:00', 90, ['class_group_id' => $group->id, 'attendance_recorded_at' => '2026-10-26 10:05:00', 'attendance_recorded_by' => $this->teacher->id]);
            $this->summarise($lesson, 'Sumário de hoje.', '2026-10-26 10:07:00');
        }

        $this->activity('Reunião de departamento', 1, '14:30', '15:30');
        $this->calendarEvent('Conselho de turma', '2026-10-29');

        // Aquece o que o middleware já aqueceu num pedido real (plano e módulos).
        $this->compose();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->compose();

        $budget = self::QUERY_BUDGET_FIXED + self::QUERY_BUDGET_PER_CLASS * count($classes);
        $this->assertLessThanOrEqual($budget, $queries, sprintf('o painel fez %d consultas para %d turmas (orçamento %d)', $queries, count($classes), $budget));
        fwrite(STDERR, sprintf("\n[dashboard] %d consultas para %d turmas (orçamento %d)\n", $queries, count($classes), $budget));
    }
}
