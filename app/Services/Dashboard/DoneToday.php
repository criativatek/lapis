<?php

namespace App\Services\Dashboard;

use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\Intervention;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonSummary;
use App\Models\RecordFollowupConfirmation;
use App\Models\TimetableActivitySession;
use App\Models\TimetableActivitySessionStatus;
use Carbon\CarbonImmutable;

/**
 * «Arrumado hoje»: os factos de hoje, com a hora a que ficaram guardados,
 * do mais recente para o mais antigo — e só do próprio professor onde a base
 * de dados diz quem foi (assiduidade, desfecho, confirmações, revisões,
 * sessões); o sumário é da aula, e a aula é das turmas dele.
 *
 * Agrega o que o professor vive como uma só coisa: sumário e assiduidade da
 * mesma aula são uma linha; doze classificações confirmadas na mesma turma
 * são uma linha com o número. Nenhuma hora é inventada: sem carimbo, não há
 * linha.
 */
final class DoneToday
{
    /**
     * @return list<array<string, mixed>>
     */
    public function for(DashboardContext $context): array
    {
        if (! $context->hasClasses()) {
            return [];
        }

        $items = [];

        if ($context->hasLessons) {
            $items = array_merge($items, $this->lessons($context), $this->sessions($context));
        }

        if ($context->hasAssessments) {
            $items = array_merge($items, $this->classifications($context));
        }

        if ($context->hasRecords) {
            $items = array_merge($items, $this->alertsHandled($context));
        }

        if ($context->hasInterventions) {
            $items = array_merge($items, $this->reviews($context));
        }

        usort($items, fn (array $a, array $b): int => [$b['at'], $a['title']] <=> [$a['at'], $b['title']]);

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lessons(DashboardContext $context): array
    {
        $from = $context->startOfToday();
        $to = $context->endOfToday();
        $teacherId = (int) $context->teacher->getKey();
        $classIds = $context->classIds();

        $summaries = LessonSummary::query()
            ->whereBetween('updated_at', [$from, $to])
            ->whereNotNull('content')
            ->whereRaw("TRIM(content) <> ''")
            ->whereHas('lesson', fn ($query) => $query->whereIn('class_id', $classIds))
            // `classGroup` porque contextLabel() o lê («8.º F · T1»): sem isto
            // seria uma consulta por aula de grupo.
            ->with('lesson.classGroup')
            ->get();

        $stamped = Lesson::query()
            ->whereIn('class_id', $classIds)
            ->where(function ($query) use ($from, $to, $teacherId): void {
                $query
                    ->where(fn ($attendance) => $attendance->whereBetween('attendance_recorded_at', [$from, $to])->where('attendance_recorded_by', $teacherId))
                    ->orWhere(fn ($outcome) => $outcome->whereBetween('outcome_recorded_at', [$from, $to])->where('outcome_recorded_by', $teacherId));
            })
            ->with('classGroup')
            ->get();

        /** @var array<int, array{lesson: Lesson, at: CarbonImmutable|null, parts: array<string, bool>}> $byLesson */
        $byLesson = [];

        foreach ($summaries as $summary) {
            $lesson = $summary->lesson;
            $at = CarbonImmutable::parse((string) $summary->updated_at)->setTimezone(DashboardContext::TIMEZONE);
            $byLesson[(int) $lesson->getKey()] = ['lesson' => $lesson, 'at' => $at, 'parts' => ['summary' => true]];
        }

        foreach ($stamped as $lesson) {
            $key = (int) $lesson->getKey();
            $entry = $byLesson[$key] ?? ['lesson' => $lesson, 'at' => null, 'parts' => []];

            $attendanceAt = $lesson->attendance_recorded_at;

            if ($attendanceAt !== null && (int) $lesson->attendance_recorded_by === $teacherId && $attendanceAt->between($from, $to)) {
                $entry['parts']['attendance'] = true;
                $entry['at'] = $this->later($entry['at'], $attendanceAt->toImmutable());
            }

            $outcomeAt = $lesson->outcome_recorded_at;

            if ($outcomeAt !== null && (int) $lesson->outcome_recorded_by === $teacherId && $outcomeAt->between($from, $to)) {
                if ($lesson->outcome !== null && $lesson->outcome !== LessonOutcome::Taught) {
                    $entry['parts']['outcome'] = true;
                }

                $entry['at'] = $this->later($entry['at'], $outcomeAt->toImmutable());
            }

            if ($entry['parts'] === []) {
                continue;
            }

            $byLesson[$key] = $entry;
        }

        $items = [];

        foreach ($byLesson as $entry) {
            if ($entry['at'] === null) {
                continue;
            }

            $lesson = $entry['lesson'];
            $class = $context->classById((int) $lesson->class_id) ?? $lesson->schoolClass;
            $lesson->setRelation('schoolClass', $class);
            $parts = $entry['parts'];

            $title = match (true) {
                isset($parts['outcome']) => ($lesson->outcome?->label() ?? 'Aula fechada').' — '.$lesson->contextLabel(),
                isset($parts['summary'], $parts['attendance']) => 'Sumário e assiduidade — '.$lesson->contextLabel(),
                isset($parts['attendance']) => 'Assiduidade — '.$lesson->contextLabel(),
                default => 'Sumário — '.$lesson->contextLabel(),
            };

            $items[] = $this->item($entry['at'], 'lesson', $title, $context->classSummary($class), route('lessons.show', $lesson), 1);
        }

        return $items;
    }

    /**
     * As sessões dadas como realizadas (ou não realizadas) hoje. A tabela não
     * guarda a hora do estado, só `updated_at`: uma sessão de ontem cujo texto
     * se edita hoje volta a aparecer aqui — limitação conhecida, documentada.
     *
     * @return list<array<string, mixed>>
     */
    private function sessions(DashboardContext $context): array
    {
        $sessions = TimetableActivitySession::query()
            ->where('user_id', $context->teacher->getKey())
            ->whereIn('status', [TimetableActivitySessionStatus::Done->value, TimetableActivitySessionStatus::NotHeld->value])
            ->whereBetween('updated_at', [$context->startOfToday(), $context->endOfToday()])
            ->get();

        $items = [];

        foreach ($sessions as $session) {
            $at = CarbonImmutable::parse((string) $session->updated_at)->setTimezone(DashboardContext::TIMEZONE);
            $class = $session->class_id === null ? null : $context->classById((int) $session->class_id);

            $items[] = $this->item(
                $at,
                'session',
                $session->status->label().' — '.$session->activity_title,
                $class === null ? null : $context->classSummary($class),
                route('activity-sessions.index'),
                1,
            );
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function classifications(DashboardContext $context): array
    {
        $from = $context->startOfToday();
        $to = $context->endOfToday();
        $teacherId = (int) $context->teacher->getKey();

        $confirmed = Classification::query()
            ->join('enrollments', 'classifications.enrollment_id', '=', 'enrollments.id')
            ->whereIn('enrollments.class_id', $context->classIds())
            // Só as que FICARAM confirmadas: uma classificação confirmada e
            // publicada no mesmo dia conta uma vez, como publicada.
            ->where('classifications.status', ClassificationStatus::Confirmed->value)
            ->where('classifications.confirmed_by', $teacherId)
            ->whereBetween('classifications.confirmed_at', [$from, $to])
            ->selectRaw('enrollments.class_id, count(*) as total, max(classifications.confirmed_at) as latest')
            ->groupBy('enrollments.class_id')
            ->toBase()
            ->get();

        $published = Classification::query()
            ->join('enrollments', 'classifications.enrollment_id', '=', 'enrollments.id')
            ->whereIn('enrollments.class_id', $context->classIds())
            ->where('classifications.status', ClassificationStatus::Published->value)
            ->whereBetween('classifications.published_at', [$from, $to])
            ->selectRaw('enrollments.class_id, count(*) as total, max(classifications.published_at) as latest')
            ->groupBy('enrollments.class_id')
            ->toBase()
            ->get();

        $items = [];

        foreach ([['rows' => $confirmed, 'kind' => 'classifications_confirmed', 'word' => 'confirmadas'], ['rows' => $published, 'kind' => 'classifications_published', 'word' => 'publicadas']] as $group) {
            foreach ($group['rows'] as $row) {
                $class = $context->classById((int) $row->class_id);

                if ($class === null) {
                    continue;
                }

                $total = (int) $row->total;
                $items[] = $this->item(
                    CarbonImmutable::parse((string) $row->latest)->setTimezone(DashboardContext::TIMEZONE),
                    $group['kind'],
                    $total.' '.($total === 1 ? 'classificação '.substr($group['word'], 0, -1) : 'classificações '.$group['word']).' — '.$class->label,
                    $context->classSummary($class),
                    route('classifications.show', $class),
                    $total,
                );
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alertsHandled(DashboardContext $context): array
    {
        $confirmations = RecordFollowupConfirmation::query()
            ->where('confirmed_by', $context->teacher->getKey())
            ->whereNull('undone_at')
            ->whereBetween('confirmed_at', [$context->startOfToday(), $context->endOfToday()])
            ->whereHas('followupCase', fn ($query) => $query->whereIn('class_id', $context->classIds()))
            ->with('followupCase')
            ->get();

        $items = [];

        foreach ($confirmations as $confirmation) {
            $class = $context->classById((int) $confirmation->followupCase->class_id);

            if ($class === null) {
                continue;
            }

            $items[] = $this->item(
                CarbonImmutable::parse((string) $confirmation->confirmed_at)->setTimezone(DashboardContext::TIMEZONE),
                'alert_handled',
                'Alerta de registos tratado — '.$class->label,
                $context->classSummary($class),
                route('records.index', $context->academicYear === null ? [] : ['year' => $context->academicYear->ulid]),
                1,
            );
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviews(DashboardContext $context): array
    {
        $today = fn ($query) => $query
            ->where('reviewed_by', $context->teacher->getKey())
            ->whereBetween('created_at', [$context->startOfToday(), $context->endOfToday()]);

        $interventions = Intervention::query()
            ->whereIn('class_id', $context->classIds())
            ->whereHas('reviews', $today)
            ->with(['reviews' => $today])
            ->get();

        $items = [];

        foreach ($interventions as $intervention) {
            $class = $context->classById((int) $intervention->class_id);

            if ($class === null) {
                continue;
            }

            foreach ($intervention->reviews as $review) {
                $items[] = $this->item(
                    CarbonImmutable::parse((string) $review->created_at)->setTimezone(DashboardContext::TIMEZONE),
                    'review',
                    'Medida revista — '.$class->label,
                    $context->classSummary($class),
                    route('interventions.show', $class),
                    1,
                );
            }
        }

        return $items;
    }

    private function later(?CarbonImmutable $current, CarbonImmutable $candidate): CarbonImmutable
    {
        $candidate = $candidate->setTimezone(DashboardContext::TIMEZONE);

        return $current === null ? $candidate : $current->max($candidate);
    }

    /**
     * @param  array{ulid: string, label: string, subject: string|null, tone: string|null}|null  $class
     * @return array<string, mixed>
     */
    private function item(CarbonImmutable $at, string $kind, string $title, ?array $class, string $href, int $count): array
    {
        return [
            'at' => $at->toIso8601String(),
            'time' => $at->format('H:i'),
            'kind' => $kind,
            'title' => $title,
            'class' => $class,
            'href' => $href,
            'count' => $count,
        ];
    }
}
