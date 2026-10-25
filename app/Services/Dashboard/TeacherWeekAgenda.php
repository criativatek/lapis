<?php

namespace App\Services\Dashboard;

use App\Models\CalendarEvent;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\Lesson;
use App\Models\SchoolClass;
use Carbon\CarbonImmutable;

/**
 * «Esta semana»: por dia, as aulas (ocorrências do horário mais as aulas
 * inseridas à mão) na cor da turma e com o seu estado, e os acontecimentos —
 * do calendário do professor, elementos de avaliação com data marcada e
 * sessões das outras atividades — mais os dias não letivos do ano.
 *
 * Sem o módulo `lessons` a semana continua a existir: fica com os
 * acontecimentos e as datas de avaliação, que são da Base.
 */
final class TeacherWeekAgenda
{
    private const WEEKDAYS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

    public function __construct(private readonly DashboardWeek $week) {}

    /**
     * @return array<string, mixed>
     */
    public function for(DashboardContext $context): array
    {
        $weekStart = $context->weekStart();
        $weekEnd = $context->weekEnd();
        $today = $context->today();

        $lessonsByDay = $this->lessonsByDay($context);
        $eventsByDay = $this->eventsByDay($context, $weekStart, $weekEnd);
        $nonTeaching = $this->nonTeachingByDay($context, $weekStart, $weekEnd);

        $days = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $weekStart->addDays($offset);
            $key = $date->toDateString();
            $lessons = $lessonsByDay[$key] ?? [];
            $events = $eventsByDay[$key] ?? [];

            // Sábado e domingo só quando há mesmo alguma coisa marcada.
            if ($offset >= 5 && $lessons === [] && $events === []) {
                continue;
            }

            usort($lessons, fn (array $a, array $b): int => [$a['start_time'], $a['class']['label']] <=> [$b['start_time'], $b['class']['label']]);
            usort($events, fn (array $a, array $b): int => [$a['start_time'] ?? '', $a['title']] <=> [$b['start_time'] ?? '', $b['title']]);

            $days[] = [
                'date' => $key,
                'day' => $date->day,
                'weekday' => self::WEEKDAYS[$offset],
                'is_today' => $key === $today,
                'is_past' => $key < $today,
                'non_teaching' => $nonTeaching[$key] ?? null,
                'lessons' => $lessons,
                'events' => $events,
            ];
        }

        return [
            'week' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekStart->addDays(4)->toDateString(),
                'is_current' => true,
            ],
            'has_timetable' => $context->hasLessons && array_sum(array_map('count', $context->weekOccurrences)) > 0,
            'days' => $days,
            'href' => $context->hasLessons
                ? route('lessons.index', ['view' => 'semana', 'week' => $weekStart->toDateString()])
                : route('calendar.index', ['month' => $weekStart->format('Y-m')]),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function lessonsByDay(DashboardContext $context): array
    {
        if (! $context->hasLessons) {
            return [];
        }

        $weekLessons = $this->week->lessons($context);
        $byDay = [];
        $matched = [];

        foreach ($context->classes as $class) {
            foreach ($context->weekOccurrences[(int) $class->getKey()] ?? [] as $occurrence) {
                $lesson = DashboardWeek::lessonForOccurrence($weekLessons, (int) $class->getKey(), $occurrence, $matched);

                $byDay[$occurrence['starts_at']->toDateString()][] = $this->entry($context, $class, $occurrence['starts_at'], $occurrence['ends_at'], $lesson);
            }
        }

        foreach ($weekLessons as $lesson) {
            if (isset($matched[(int) $lesson->getKey()])) {
                continue;
            }

            $class = $context->classById((int) $lesson->class_id);

            if ($class === null) {
                continue;
            }

            $startsAt = $lesson->starts_at->toImmutable()->setTimezone(DashboardContext::TIMEZONE);
            $byDay[$startsAt->toDateString()][] = $this->entry($context, $class, $startsAt, $lesson->ends_at?->toImmutable()->setTimezone(DashboardContext::TIMEZONE), $lesson);
        }

        return $byDay;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(DashboardContext $context, SchoolClass $class, CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, ?Lesson $lesson): array
    {
        $hasSummary = $lesson !== null && trim((string) $lesson->summary?->content) !== '';

        return [
            'start_time' => $startsAt->format('H:i'),
            'end_time' => $endsAt?->format('H:i'),
            'class' => $context->classSummary($class),
            'label' => $lesson?->contextLabel() ?? $class->label,
            'state' => $lesson === null
                ? (ItemState::forWindow($startsAt, $endsAt, $context->now) === ItemState::PENDING ? ItemState::UNOPENED : ItemState::forWindow($startsAt, $endsAt, $context->now))
                : ItemState::forLesson($lesson, $hasSummary, $context->now),
            'lesson_ulid' => $lesson?->ulid,
            'href' => $lesson === null
                ? route('lessons.index', ['view' => 'semana', 'week' => $context->weekStart()->toDateString()])
                : route('lessons.show', $lesson),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function eventsByDay(DashboardContext $context, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $byDay = [];

        if ($context->hasCalendar) {
            $events = CalendarEvent::query()
                ->where('user_id', $context->teacher->getKey())
                ->whereDate('starts_on', '<=', $weekEnd->toDateString())
                ->whereDate('ends_on', '>=', $weekStart->toDateString())
                ->with('schoolClasses')
                ->orderBy('starts_on')
                ->get();

            foreach ($events as $event) {
                $first = $event->starts_on->toImmutable()->max($weekStart->startOfDay());
                $last = $event->ends_on->toImmutable()->min($weekEnd->startOfDay());
                /** @var SchoolClass|null $class */
                $class = $event->schoolClasses->first();

                for ($date = $first->startOfDay(); $date->lessThanOrEqualTo($last); $date = $date->addDay()) {
                    $byDay[$date->toDateString()][] = [
                        'kind' => 'calendar',
                        'type' => $event->type->value,
                        'type_label' => $event->type->label(),
                        'title' => $event->title,
                        'start_time' => $event->starts_at === null ? null : substr((string) $event->starts_at, 0, 5),
                        'class_label' => $class?->label,
                        'href' => route('calendar.index', ['month' => $date->format('Y-m')]),
                    ];
                }
            }
        }

        if ($context->hasAssessments && $context->hasClasses()) {
            $instruments = Instrument::query()
                ->whereIn('class_id', $context->classIds())
                ->whereNotNull('applied_on')
                ->whereDate('applied_on', '>=', $weekStart->toDateString())
                ->whereDate('applied_on', '<=', $weekEnd->toDateString())
                ->whereNotIn('status', InstrumentStatus::hiddenFromDashboard())
                ->orderBy('applied_on')
                ->get();

            foreach ($instruments as $instrument) {
                $byDay[$instrument->applied_on->toDateString()][] = [
                    'kind' => 'instrument',
                    'type' => $instrument->status->value,
                    'type_label' => $instrument->status->label(),
                    'title' => $instrument->title,
                    'start_time' => null,
                    'class_label' => ($context->classById((int) $instrument->class_id) ?? $instrument->schoolClass)->label,
                    'href' => route('assessments.show', $instrument),
                ];
            }
        }

        foreach ($this->week->sessions($context) as $session) {
            $class = is_array($session['school_class'] ?? null) ? $session['school_class'] : null;

            $byDay[(string) $session['occurs_on']][] = [
                'kind' => 'activity',
                'type' => (string) $session['kind'],
                'type_label' => (string) $session['kind_label'],
                'title' => (string) $session['title'],
                'start_time' => (string) $session['start_time'],
                'class_label' => $class['label'] ?? null,
                'state' => (string) $session['presentation'],
                'href' => route('timetable.index', ['week' => $weekStart->toDateString()]),
            ];
        }

        return $byDay;
    }

    /**
     * @return array<string, string>
     */
    private function nonTeachingByDay(DashboardContext $context, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        if ($context->academicYear === null) {
            return [];
        }

        $exceptions = $context->academicYear->exceptions()
            ->whereDate('starts_on', '<=', $weekEnd->toDateString())
            ->whereDate('ends_on', '>=', $weekStart->toDateString())
            ->get();

        $byDay = [];

        foreach ($exceptions as $exception) {
            $first = CarbonImmutable::parse((string) $exception->starts_on, DashboardContext::TIMEZONE)->max($weekStart->startOfDay());
            $last = CarbonImmutable::parse((string) $exception->ends_on, DashboardContext::TIMEZONE)->min($weekEnd->startOfDay());

            for ($date = $first->startOfDay(); $date->lessThanOrEqualTo($last); $date = $date->addDay()) {
                $byDay[$date->toDateString()] = (string) ($exception->title ?? $exception->type->label());
            }
        }

        return $byDay;
    }
}
