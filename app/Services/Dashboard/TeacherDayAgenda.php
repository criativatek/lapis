<?php

namespace App\Services\Dashboard;

use App\Models\Lesson;
use App\Models\LessonSummary;
use App\Models\SchoolClass;
use App\Services\Lessons\LessonRowBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * «O que tenho hoje»: as ocorrências do horário de hoje, as aulas já
 * materializadas que lhes correspondem (ou que existem sem horário, inseridas
 * à mão) e as sessões das outras atividades, numa só lista cronológica — e os
 * limites reais do horário desta semana, para a régua do dia não assumir um
 * horário de secretária.
 *
 * SÓ LEITURA: uma ocorrência que ainda ninguém abriu é uma linha desta lista
 * com `lesson = null`, nunca uma aula criada.
 */
final class TeacherDayAgenda
{
    public function __construct(
        private readonly DashboardWeek $week,
        private readonly LessonRowBuilder $rowBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(DashboardContext $context): array
    {
        $empty = [
            'available' => false,
            'date' => $context->today(),
            'bounds' => null,
            'items' => [],
            'totals' => ['lessons' => 0, 'summaries_registered' => 0, 'sessions' => 0, 'sessions_done' => 0, 'absences_recorded' => 0],
        ];

        if (! $context->hasLessons || $context->academicYear === null || ! $context->hasClasses()) {
            return $empty;
        }

        $now = $context->now;
        $today = $context->today();
        $weekLessons = $this->week->lessons($context);

        $todayLessons = $weekLessons->filter(
            fn (Lesson $lesson): bool => $lesson->starts_at->toImmutable()->setTimezone(DashboardContext::TIMEZONE)->toDateString() === $today,
        )->values();

        $rows = collect($this->rowBuilder->rows($todayLessons, $context->teacher))->keyBy('ulid');

        $summaryTimes = $todayLessons->isEmpty()
            ? collect()
            : LessonSummary::query()
                ->whereIn('lesson_id', $todayLessons->map(fn (Lesson $lesson): int => (int) $lesson->getKey())->all())
                ->get(['lesson_id', 'updated_at'])
                ->keyBy('lesson_id');

        $items = [];
        $matched = [];

        foreach ($context->classes as $class) {
            foreach ($context->weekOccurrences[(int) $class->getKey()] ?? [] as $occurrence) {
                if ($occurrence['starts_at']->toDateString() !== $today) {
                    continue;
                }

                $lesson = DashboardWeek::lessonForOccurrence($todayLessons, (int) $class->getKey(), $occurrence, $matched);

                if ($lesson !== null) {
                    $matched[(int) $lesson->getKey()] = true;
                    $items[] = $this->lessonItem($context, $lesson, $rows->get($lesson->ulid), $summaryTimes->get((int) $lesson->getKey())?->updated_at);

                    continue;
                }

                $items[] = $this->occurrenceItem($context, $class, $occurrence['starts_at'], $occurrence['ends_at']);
            }
        }

        foreach ($todayLessons as $lesson) {
            if (isset($matched[(int) $lesson->getKey()]) || ! $rows->has($lesson->ulid)) {
                continue;
            }

            $items[] = $this->lessonItem($context, $lesson, $rows->get($lesson->ulid), $summaryTimes->get((int) $lesson->getKey())?->updated_at);
        }

        foreach ($this->week->sessions($context) as $session) {
            if ($session['occurs_on'] !== $today) {
                continue;
            }

            $items[] = $this->sessionItem($context, $session);
        }

        usort($items, fn (array $a, array $b): int => [$a['starts_at'], $a['key']] <=> [$b['starts_at'], $b['key']]);

        $lessonItems = array_values(array_filter($items, fn (array $item): bool => $item['kind'] === 'lesson'));
        $sessionItems = array_values(array_filter($items, fn (array $item): bool => $item['kind'] === 'activity'));

        return [
            'available' => true,
            'date' => $today,
            'bounds' => $this->bounds($context, $weekLessons),
            'items' => $items,
            'totals' => [
                'lessons' => count($lessonItems),
                'summaries_registered' => count(array_filter($lessonItems, fn (array $item): bool => $item['lesson'] !== null && $item['lesson']['has_summary'])),
                'sessions' => count($sessionItems),
                'sessions_done' => count(array_filter($sessionItems, fn (array $item): bool => $item['state'] === ItemState::DONE)),
                'absences_recorded' => array_sum(array_map(
                    fn (array $item): int => (int) ($item['lesson']['absent_count'] ?? 0),
                    $lessonItems,
                )),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $row  a linha de LessonRowBuilder desta aula
     * @return array<string, mixed>
     */
    private function lessonItem(DashboardContext $context, Lesson $lesson, ?array $row, mixed $summaryUpdatedAt): array
    {
        $class = $context->classById((int) $lesson->class_id) ?? $lesson->schoolClass;
        $hasSummary = (bool) ($row['has_summary'] ?? false);
        $startsAt = $lesson->starts_at->toImmutable()->setTimezone(DashboardContext::TIMEZONE);
        $endsAt = $lesson->ends_at?->toImmutable()->setTimezone(DashboardContext::TIMEZONE);

        return [
            'kind' => 'lesson',
            'key' => 'lesson-'.$lesson->ulid,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt?->toIso8601String(),
            'start_time' => $startsAt->format('H:i'),
            'end_time' => $endsAt?->format('H:i'),
            'title' => (string) ($row['context_label'] ?? $class->label),
            'subtitle' => (string) ($row['subject'] ?? $class->subject?->name),
            'class' => $context->classSummary($class),
            'lesson' => [
                'ulid' => $lesson->ulid,
                'number' => $lesson->lessonNumberLabel(),
                'status' => $lesson->status->value,
                'outcome' => $lesson->outcome?->value,
                'outcome_label' => $lesson->outcome?->label(),
                'has_summary' => $hasSummary,
                'attendance_recorded' => $lesson->attendanceRecorded(),
                'absent_count' => $row['absent_count'] ?? null,
                'summary_registered_at' => $hasSummary && $summaryUpdatedAt !== null
                    ? CarbonImmutable::parse((string) $summaryUpdatedAt)->setTimezone(DashboardContext::TIMEZONE)->toIso8601String()
                    : null,
            ],
            'session' => null,
            'state' => ItemState::forLesson($lesson, $hasSummary, $context->now),
            'href' => route('lessons.show', $lesson),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function occurrenceItem(DashboardContext $context, SchoolClass $class, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        return [
            'kind' => 'lesson',
            'key' => 'occurrence-'.$class->ulid.'-'.$startsAt->getTimestamp(),
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'start_time' => $startsAt->format('H:i'),
            'end_time' => $endsAt->format('H:i'),
            'title' => $class->label,
            'subtitle' => $class->subject?->name,
            'class' => $context->classSummary($class),
            'lesson' => null,
            'session' => null,
            // Terminada e nunca aberta: não é um sumário em falta (não há
            // aula), é uma ocorrência por abrir — a fila não a conta.
            'state' => ItemState::forWindow($startsAt, $endsAt, $context->now) === ItemState::PENDING
                ? ItemState::UNOPENED
                : ItemState::forWindow($startsAt, $endsAt, $context->now),
            'href' => route('lessons.index', ['view' => 'semana', 'week' => $context->weekStart()->toDateString()]),
        ];
    }

    /**
     * @param  array<string, mixed>  $session  uma linha de ActivitySessionWeek
     * @return array<string, mixed>
     */
    private function sessionItem(DashboardContext $context, array $session): array
    {
        $startsAt = CarbonImmutable::parse((string) $session['starts_at'])->setTimezone(DashboardContext::TIMEZONE);
        $endsAt = CarbonImmutable::parse((string) $session['ends_at'])->setTimezone(DashboardContext::TIMEZONE);
        $class = is_array($session['school_class'] ?? null) ? $session['school_class'] : null;

        return [
            'kind' => 'activity',
            'key' => (string) $session['key'],
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'start_time' => (string) $session['start_time'],
            'end_time' => (string) $session['end_time'],
            'title' => (string) $session['title'],
            'subtitle' => (string) $session['kind_label'],
            'class' => $class === null ? null : ['ulid' => $class['ulid'], 'label' => $class['label'], 'subject' => null, 'tone' => null],
            'lesson' => null,
            'session' => [
                'ulid' => $session['session_ulid'],
                'activity_ulid' => $session['activity_ulid'],
                'status' => (string) $session['status'],
                'presentation' => (string) $session['presentation'],
                'presentation_label' => (string) $session['presentation_label'],
                'has_summary' => (bool) $session['has_summary'],
            ],
            'state' => ItemState::forSession((string) $session['status'], $startsAt, $endsAt, $context->now),
            'href' => route('timetable.index', ['week' => $context->weekStart()->toDateString()]),
        ];
    }

    /**
     * Os limites do horário DESTA semana, arredondados à hora: do início mais
     * cedo ao fim mais tarde entre as ocorrências projetadas e as aulas já
     * existentes. NULL quando a semana não tem nada — a régua não se desenha.
     *
     * @param  EloquentCollection<int, Lesson>  $weekLessons
     * @return array{start: string, end: string}|null
     */
    private function bounds(DashboardContext $context, EloquentCollection $weekLessons): ?array
    {
        $earliest = null;
        $latest = null;

        $consider = function (CarbonImmutable $startsAt, ?CarbonImmutable $endsAt) use (&$earliest, &$latest): void {
            $startMinutes = $startsAt->hour * 60 + $startsAt->minute;
            $endMinutes = $endsAt === null ? $startMinutes + 60 : $endsAt->hour * 60 + $endsAt->minute;

            $earliest = $earliest === null ? $startMinutes : min($earliest, $startMinutes);
            $latest = $latest === null ? $endMinutes : max($latest, $endMinutes);
        };

        foreach ($context->weekOccurrences as $occurrences) {
            foreach ($occurrences as $occurrence) {
                $consider($occurrence['starts_at'], $occurrence['ends_at']);
            }
        }

        foreach ($weekLessons as $lesson) {
            $consider(
                $lesson->starts_at->toImmutable()->setTimezone(DashboardContext::TIMEZONE),
                $lesson->ends_at?->toImmutable()->setTimezone(DashboardContext::TIMEZONE),
            );
        }

        foreach ($this->week->sessions($context) as $session) {
            $consider(
                CarbonImmutable::parse((string) $session['starts_at'])->setTimezone(DashboardContext::TIMEZONE),
                CarbonImmutable::parse((string) $session['ends_at'])->setTimezone(DashboardContext::TIMEZONE),
            );
        }

        if ($earliest === null || $latest === null) {
            return null;
        }

        $startHour = intdiv($earliest, 60);
        $endHour = min(24, intdiv($latest + 59, 60));

        return [
            'start' => sprintf('%02d:00', $startHour),
            'end' => sprintf('%02d:00', $endHour),
        ];
    }
}
