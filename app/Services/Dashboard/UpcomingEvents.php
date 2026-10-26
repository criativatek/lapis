<?php

namespace App\Services\Dashboard;

use App\Models\CalendarEvent;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\SchoolClass;
use Carbon\CarbonImmutable;

/**
 * «O que se aproxima»: os próximos catorze dias a partir de amanhã — os
 * acontecimentos do calendário do professor, os elementos de avaliação com
 * data marcada nas suas turmas — e o fim do período em curso, que entra
 * sempre, com os dias que faltam, na sua posição cronológica.
 */
final class UpcomingEvents
{
    public const HORIZON_DAYS = 14;

    /**
     * @return list<array<string, mixed>>
     */
    public function for(DashboardContext $context): array
    {
        $from = $context->startOfToday()->addDay();
        $to = $from->addDays(self::HORIZON_DAYS - 1);
        $items = [];

        if ($context->hasCalendar) {
            $events = CalendarEvent::query()
                ->where('user_id', $context->teacher->getKey())
                ->whereDate('starts_on', '<=', $to->toDateString())
                ->whereDate('ends_on', '>=', $from->toDateString())
                ->with('schoolClasses')
                ->orderBy('starts_on')
                ->get();

            foreach ($events as $event) {
                $date = $event->starts_on->toImmutable()->max($from);
                /** @var SchoolClass|null $class */
                $class = $event->schoolClasses->first();

                $items[] = $this->item(
                    $context,
                    $date,
                    'calendar',
                    $event->title,
                    $event->type->label().($event->starts_at === null ? '' : ' · '.substr((string) $event->starts_at, 0, 5)),
                    $class,
                    route('calendar.index', ['month' => $date->format('Y-m')]),
                );
            }
        }

        if ($context->hasAssessments && $context->hasClasses()) {
            $instruments = Instrument::query()
                ->whereIn('class_id', $context->classIds())
                ->whereNotNull('applied_on')
                ->whereDate('applied_on', '>=', $from->toDateString())
                ->whereDate('applied_on', '<=', $to->toDateString())
                ->whereNotIn('status', InstrumentStatus::hiddenFromDashboard())
                ->orderBy('applied_on')
                ->get();

            foreach ($instruments as $instrument) {
                $items[] = $this->item(
                    $context,
                    $instrument->applied_on->toImmutable(),
                    'instrument',
                    $instrument->title,
                    'Elemento de avaliação · '.$instrument->status->label(),
                    $context->classById((int) $instrument->class_id) ?? $instrument->schoolClass,
                    route('assessments.show', $instrument),
                );
            }
        }

        $period = $context->currentPeriod;

        if ($period !== null) {
            $end = CarbonImmutable::parse((string) $period->ends_on, DashboardContext::TIMEZONE)->startOfDay();

            if ($end->greaterThanOrEqualTo($context->startOfToday())) {
                $items[] = $this->item(
                    $context,
                    $end,
                    'period_end',
                    'Fim do '.$period->label,
                    'Estrutura do ano letivo',
                    null,
                    route('academic-years.index'),
                );
            }
        }

        usort($items, fn (array $a, array $b): int => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(DashboardContext $context, CarbonImmutable $date, string $kind, string $title, string $subtitle, ?SchoolClass $class, string $href): array
    {
        return [
            'date' => $date->toDateString(),
            'days_away' => (int) $context->startOfToday()->diffInDays($date->startOfDay(), false),
            'kind' => $kind,
            'title' => $title,
            'subtitle' => $subtitle,
            'class' => $class === null ? null : [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => null,
                'tone' => $context->tone((int) $class->getKey()),
            ],
            'href' => $href,
        ];
    }
}
