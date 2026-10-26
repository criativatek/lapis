<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * O ponto de entrada do Painel do Professor: resolve o contexto uma vez e pede
 * cada bloco ao seu serviço. Decide, pelos módulos que a organização pode
 * consultar, que blocos existem — e nada mais: sem lógica de apresentação,
 * sem frases, sem cores. É o que o controlador entrega ao Inertia.
 */
final class DashboardComposer
{
    private const WEEKDAYS = ['segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado', 'domingo'];

    public function __construct(
        private readonly DashboardContextFactory $contextFactory,
        private readonly TeacherDayAgenda $dayAgenda,
        private readonly TeacherWeekAgenda $weekAgenda,
        private readonly UpcomingEvents $upcomingEvents,
        private readonly PendingWork $pendingWork,
        private readonly DoneToday $doneToday,
        private readonly ClassOverview $classOverview,
        private readonly PeriodRuler $periodRuler,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compose(User $teacher, ?CarbonImmutable $now = null): array
    {
        $context = $this->contextFactory->for($teacher, $now);

        $pending = $this->pendingWork->for($context);
        $classes = $this->classOverview->for($context);

        foreach ($classes as $index => $item) {
            $classId = (int) ($context->classes->first(fn ($class): bool => $class->ulid === $item['class']['ulid'])?->getKey() ?? 0);
            $classes[$index]['pending'] = $pending['per_class'][$classId] ?? null;
        }

        return [
            'context' => $this->describe($context),
            'day' => $this->dayAgenda->for($context),
            'week' => $this->weekAgenda->for($context),
            'upcoming' => $this->upcomingEvents->for($context),
            'pending' => $pending,
            'done' => $this->doneToday->for($context),
            'classes' => $classes,
            'period' => $this->periodRuler->for($context),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(DashboardContext $context): array
    {
        $year = $context->academicYear;
        $weekNumber = null;

        if ($year !== null) {
            $yearStart = CarbonImmutable::parse((string) $year->starts_on, DashboardContext::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
            $weekNumber = max(1, (int) $yearStart->diffInWeeks($context->weekStart()) + 1);
        }

        return [
            'date' => $context->today(),
            'weekday' => self::WEEKDAYS[$context->now->dayOfWeekIso - 1],
            'now' => $context->now->toIso8601String(),
            'academic_year' => $year === null ? null : ['ulid' => $year->ulid, 'label' => $year->label],
            'period' => $context->currentPeriod === null ? null : ['ulid' => $context->currentPeriod->ulid, 'label' => $context->currentPeriod->label],
            'week_number' => $weekNumber,
            'has_classes' => $context->hasClasses(),
            'class_count' => $context->classes->count(),
            'modules' => [
                'lessons' => $context->hasLessons,
                'calendar' => $context->hasCalendar,
                'assessments' => $context->hasAssessments,
                'records' => $context->hasRecords,
                'interventions' => $context->hasInterventions,
                'self_assessments' => $context->hasSelfAssessments,
            ],
        ];
    }
}
