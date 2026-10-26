<?php

namespace App\Services\Dashboard;

use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\Intervention;
use App\Models\InterventionTargetType;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Services\Assessment\InstrumentCompleteness;
use App\Services\Evidence\Followup\RecordFollowupCaseBook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * «O que tenho de fazer»: só trabalho que a base de dados prova que falta.
 *
 *  - sumários por registar: aulas TERMINADAS, sem sumário, cujo desfecho é nulo
 *    ou «lecionada» (uma ausência do professor ou uma atividade da turma
 *    dispensam sumário), em turmas não arquivadas, dentro do ano letivo;
 *    as dos últimos catorze dias em lista, as anteriores numa contagem com
 *    ligação para consulta — nunca escondidas;
 *  - classificações propostas por confirmar e confirmadas por publicar;
 *  - elementos de avaliação EM CORREÇÃO com alunos por corrigir — um elemento
 *    apenas preparado ou já aplicado sem correção iniciada não é um alerta,
 *    e é contado à parte, como informação;
 *  - alertas de acumulação dos registos por tratar;
 *  - medidas com a data de revisão do professor já passada e ainda em curso.
 */
final class PendingWork
{
    public const RECENT_DAYS = 14;

    public function __construct(
        private readonly InstrumentCompleteness $completeness,
        private readonly RecordFollowupCaseBook $followupCases,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(DashboardContext $context): array
    {
        $perClass = [];

        foreach ($context->classIds() as $classId) {
            $perClass[$classId] = [
                'summaries_missing' => 0,
                'classifications_proposed' => 0,
                'classifications_confirmed' => 0,
                'corrections' => 0,
                'alerts' => 0,
                'reviews' => 0,
            ];
        }

        $summaries = $this->summaries($context, $perClass);
        $classifications = $this->classifications($context, $perClass);
        $corrections = $this->corrections($context, $perClass);
        $alerts = $this->alerts($context, $perClass);
        $reviews = $this->reviews($context, $perClass);

        return [
            'summaries' => $summaries,
            'classifications' => $classifications,
            'corrections' => $corrections,
            'record_alerts' => $alerts,
            'reviews' => $reviews,
            'per_class' => $perClass,
            'counts' => [
                'summaries_missing' => $summaries['recent_count'] + $summaries['older_count'],
                'classifications_proposed' => array_sum(array_column($classifications, 'proposed')),
                'classifications_confirmed' => array_sum(array_column($classifications, 'confirmed')),
                'corrections' => count($corrections['items']),
                'record_alerts' => $alerts['count'] ?? 0,
                'reviews' => count($reviews),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, int>>  $perClass
     * @return array<string, mixed>
     */
    private function summaries(DashboardContext $context, array &$perClass): array
    {
        $empty = ['available' => false, 'recent' => [], 'recent_count' => 0, 'older_count' => 0, 'older_href' => null];

        if (! $context->hasLessons || $context->academicYear === null || ! $context->hasClasses()) {
            return $empty;
        }

        $now = $context->now;
        $recentFrom = $now->subDays(self::RECENT_DAYS);

        $base = Lesson::query()
            ->whereIn('class_id', $context->classIds())
            ->where('starts_at', '>=', CarbonImmutable::parse((string) $context->academicYear->starts_on, DashboardContext::TIMEZONE)->startOfDay())
            // Terminada: pelo fim registado ou, sem fim (coluna anulável, só em
            // dados importados), pelo início já passado.
            ->where(fn (Builder $query) => $query
                ->where('ends_at', '<', $now)
                ->orWhere(fn (Builder $open) => $open->whereNull('ends_at')->where('starts_at', '<', $now)))
            ->where(fn (Builder $query) => $query->whereNull('outcome')->orWhere('outcome', LessonOutcome::Taught->value))
            // «Com sumário» é texto não vazio depois de aparado — a mesma leitura
            // de LessonRowBuilder (`trim(content) !== ''`), agora em SQL.
            ->whereDoesntHave('summary', fn (Builder $query) => $query->whereNotNull('content')->whereRaw("TRIM(content) <> ''"));

        $recent = (clone $base)
            ->where('ends_at', '>=', $recentFrom)
            ->with('classGroup')
            ->orderBy('ends_at')
            ->get();

        $olderByClass = (clone $base)
            ->where('ends_at', '<', $recentFrom)
            ->selectRaw('class_id, count(*) as total, min(starts_at) as oldest')
            ->groupBy('class_id')
            ->toBase()
            ->get();

        $olderCount = 0;
        $oldest = null;

        foreach ($olderByClass as $row) {
            $olderCount += (int) $row->total;
            $perClass[(int) $row->class_id]['summaries_missing'] += (int) $row->total;
            $rowOldest = CarbonImmutable::parse((string) $row->oldest, DashboardContext::TIMEZONE);
            $oldest = $oldest === null ? $rowOldest : $oldest->min($rowOldest);
        }

        $items = [];

        foreach ($recent as $lesson) {
            $perClass[(int) $lesson->class_id]['summaries_missing']++;
            $class = $context->classById((int) $lesson->class_id) ?? $lesson->schoolClass;
            // A turma do contexto, entregue à aula: contextLabel() lê-a, e
            // sem isto seria uma consulta por aula para a reencontrar.
            $lesson->setRelation('schoolClass', $class);
            $startsAt = $lesson->starts_at->toImmutable()->setTimezone(DashboardContext::TIMEZONE);

            $items[] = [
                'ulid' => $lesson->ulid,
                'starts_at' => $startsAt->toIso8601String(),
                'date' => $startsAt->toDateString(),
                'start_time' => $startsAt->format('H:i'),
                'label' => $lesson->contextLabel(),
                'number' => $lesson->lessonNumberLabel(),
                'class' => $context->classSummary($class),
                'attendance_recorded' => $lesson->attendanceRecorded(),
                'href' => route('lessons.show', $lesson),
            ];
        }

        return [
            'available' => true,
            'recent' => $items,
            'recent_count' => count($items),
            'older_count' => $olderCount,
            // A página das aulas filtra «sem sumário» semana a semana: a
            // ligação abre a semana mais antiga que ainda tem sumários em falta.
            'older_href' => $oldest === null ? null : route('lessons.index', [
                'view' => 'semana',
                'week' => $oldest->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                'summary' => 'sem',
            ]),
        ];
    }

    /**
     * @param  array<int, array<string, int>>  $perClass
     * @return list<array<string, mixed>>
     */
    private function classifications(DashboardContext $context, array &$perClass): array
    {
        if (! $context->hasAssessments || ! $context->hasClasses()) {
            return [];
        }

        $counts = Classification::query()
            ->join('enrollments', 'classifications.enrollment_id', '=', 'enrollments.id')
            ->whereIn('enrollments.class_id', $context->classIds())
            ->whereNot('classifications.status', ClassificationStatus::Superseded->value)
            ->whereIn('classifications.status', [ClassificationStatus::Proposed->value, ClassificationStatus::Confirmed->value])
            ->selectRaw('enrollments.class_id, classifications.status, count(*) as total')
            ->groupBy('enrollments.class_id', 'classifications.status')
            ->toBase()
            ->get();

        $byClass = [];

        foreach ($counts as $row) {
            $classId = (int) $row->class_id;
            $status = (string) $row->status;
            $byClass[$classId] ??= ['proposed' => 0, 'confirmed' => 0];

            if ($status === ClassificationStatus::Proposed->value) {
                $byClass[$classId]['proposed'] = (int) $row->total;
                $perClass[$classId]['classifications_proposed'] = (int) $row->total;
            } elseif ($status === ClassificationStatus::Confirmed->value) {
                $byClass[$classId]['confirmed'] = (int) $row->total;
                $perClass[$classId]['classifications_confirmed'] = (int) $row->total;
            }
        }

        $items = [];

        foreach ($context->classes as $class) {
            $counts = $byClass[(int) $class->getKey()] ?? null;

            if ($counts === null || ($counts['proposed'] === 0 && $counts['confirmed'] === 0)) {
                continue;
            }

            $items[] = [
                'class' => $context->classSummary($class),
                'proposed' => $counts['proposed'],
                'confirmed' => $counts['confirmed'],
                'href' => route('classifications.show', $class),
            ];
        }

        usort($items, fn (array $a, array $b): int => [$b['proposed'], $b['confirmed']] <=> [$a['proposed'], $a['confirmed']]);

        return $items;
    }

    /**
     * @param  array<int, array<string, int>>  $perClass
     * @return array<string, mixed>
     */
    private function corrections(DashboardContext $context, array &$perClass): array
    {
        if (! $context->hasAssessments || ! $context->hasClasses()) {
            return ['items' => [], 'applied_without_correction' => 0];
        }

        $inCorrection = Instrument::query()
            ->whereIn('class_id', $context->classIds())
            ->where('status', InstrumentStatus::InCorrection->value)
            ->orderBy('applied_on')
            ->get();

        $progress = $inCorrection->isEmpty() ? collect() : $this->completeness->forMany($inCorrection);
        $items = [];

        foreach ($inCorrection as $instrument) {
            $row = $progress->get($instrument->id);
            $pending = $row === null ? 0 : max(0, (int) $row['applicable'] - (int) $row['completed']);

            if ($pending === 0) {
                continue;
            }

            $perClass[(int) $instrument->class_id]['corrections']++;
            $class = $context->classById((int) $instrument->class_id) ?? $instrument->schoolClass;

            $items[] = [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'applied_on' => $instrument->applied_on->toDateString(),
                'pending_students' => $pending,
                'applicable_students' => (int) ($row['applicable'] ?? 0),
                'class' => $context->classSummary($class),
                'href' => route('assessments.show', $instrument),
            ];
        }

        $appliedWithoutCorrection = Instrument::query()
            ->whereIn('class_id', $context->classIds())
            ->where('status', InstrumentStatus::Prepared->value)
            ->whereNotNull('applied_on')
            ->whereDate('applied_on', '<', $context->today())
            ->count();

        return ['items' => $items, 'applied_without_correction' => $appliedWithoutCorrection];
    }

    /**
     * @param  array<int, array<string, int>>  $perClass
     * @return array<string, mixed>|null
     */
    private function alerts(DashboardContext $context, array &$perClass): ?array
    {
        if (! $context->hasRecords || $context->academicYear === null || ! $context->hasClasses()) {
            return null;
        }

        $pending = $this->followupCases->pendingAlerts($context->classIds(), $context->timeline, null, false);

        if ($pending->isEmpty()) {
            return null;
        }

        foreach ($pending as $row) {
            $perClass[(int) $row['case']->class_id]['alerts']++;
        }

        return [
            'count' => $pending->count(),
            'href' => route('records.index', ['year' => $context->academicYear->ulid]),
        ];
    }

    /**
     * @param  array<int, array<string, int>>  $perClass
     * @return list<array<string, mixed>>
     */
    private function reviews(DashboardContext $context, array &$perClass): array
    {
        if (! $context->hasInterventions || ! $context->hasClasses()) {
            return [];
        }

        $interventions = Intervention::query()
            ->whereIn('class_id', $context->classIds())
            ->needingReview($context->now)
            ->with('participants.student.identity')
            ->orderBy('review_on')
            ->get();

        $items = [];

        foreach ($interventions as $intervention) {
            $perClass[(int) $intervention->class_id]['reviews']++;
            $class = $context->classById((int) $intervention->class_id) ?? $intervention->schoolClass;

            $items[] = [
                'ulid' => $intervention->ulid,
                'title' => $intervention->displayTitle(),
                'target_label' => $this->targetLabel($intervention),
                'review_on' => $intervention->review_on?->toDateString(),
                'support_measure_level' => $intervention->support_measure_level,
                'class' => $context->classSummary($class),
                'href' => route('interventions.show', [$class, 'needs_review' => 1]),
            ];
        }

        return $items;
    }

    /**
     * A mesma regra de InterventionController::targetLabel(): o aluno pelo
     * nome de exibição, o grupo pela contagem, a turma inteira pelo nome.
     */
    private function targetLabel(Intervention $intervention): string
    {
        return match ($intervention->target_type) {
            InterventionTargetType::SchoolClass => __('Turma inteira'),
            InterventionTargetType::Student => $intervention->participants
                ->map(fn (Enrollment $enrollment): string => (string) (optional($enrollment->student->identity)->display_name ?? __('(sem identidade)')))
                ->first() ?? (string) __('(sem aluno)'),
            InterventionTargetType::Group => (string) __(':count alunos', ['count' => $intervention->participants->count()]),
        };
    }
}
