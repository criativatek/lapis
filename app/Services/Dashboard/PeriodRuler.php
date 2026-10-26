<?php

namespace App\Services\Dashboard;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use Carbon\CarbonImmutable;

/**
 * «Onde estamos no período»: o dia n de m do período em curso, o fim, e as
 * marcas dos elementos de avaliação com data marcada nas turmas do professor,
 * cada uma com o seu estado real (corrigido, em correção, aplicado sem
 * correção iniciada, marcado para o futuro). Na Base ocupa o lugar do horário.
 */
final class PeriodRuler
{
    /**
     * @return array<string, mixed>|null
     */
    public function for(DashboardContext $context): ?array
    {
        $period = $context->currentPeriod;

        if ($period === null) {
            return null;
        }

        $start = CarbonImmutable::parse((string) $period->starts_on, DashboardContext::TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse((string) $period->ends_on, DashboardContext::TIMEZONE)->startOfDay();
        $today = $context->startOfToday();

        $days = max(1, (int) $start->diffInDays($end) + 1);
        $day = min($days, max(1, (int) $start->diffInDays($today) + 1));

        $marks = [];

        if ($context->hasAssessments && $context->hasClasses()) {
            $instruments = Instrument::query()
                ->whereIn('class_id', $context->classIds())
                ->where('academic_period_id', $period->getKey())
                ->whereNotNull('applied_on')
                ->whereNotIn('status', InstrumentStatus::hiddenFromDashboard())
                ->orderBy('applied_on')
                ->get();

            foreach ($instruments as $instrument) {
                $appliedOn = $instrument->applied_on->toImmutable()->startOfDay();
                $class = $context->classById((int) $instrument->class_id) ?? $instrument->schoolClass;

                $marks[] = [
                    'ulid' => $instrument->ulid,
                    'date' => $appliedOn->toDateString(),
                    'title' => $instrument->title,
                    'class' => $context->classSummary($class),
                    'state' => match ($instrument->status) {
                        InstrumentStatus::Completed, InstrumentStatus::Published => 'corrected',
                        InstrumentStatus::InCorrection => 'in_correction',
                        default => $appliedOn->lessThan($today) ? 'applied' : 'scheduled',
                    },
                    'position' => $days <= 1 ? 0.0 : round(min(1.0, max(0.0, $start->diffInDays($appliedOn) / ($days - 1))), 4),
                    'href' => route('assessments.show', $instrument),
                ];
            }
        }

        return [
            'period' => [
                'ulid' => $period->ulid,
                'label' => $period->label,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'day' => $day,
                'days' => $days,
                'progress' => $days <= 1 ? 1.0 : round(($day - 1) / ($days - 1), 4),
                'days_left' => max(0, (int) $today->diffInDays($end, false)),
            ],
            'marks' => $marks,
        ];
    }
}
