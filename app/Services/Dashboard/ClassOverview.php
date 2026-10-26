<?php

namespace App\Services\Dashboard;

use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\Intervention;
use App\Models\InterventionStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentStatus;
use App\Services\Evidence\Followup\FollowupWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * «Como estão as minhas turmas»: por turma, factos sintéticos que a base de
 * dados já tem — alunos ativos, próxima aula desta semana, faltas marcadas
 * esta semana, medidas em curso, elementos do período corrigidos / em correção
 * / por aplicar, registos no período, autoavaliações respondidas e o último
 * registo. Nenhuma média, nenhuma nota: o acompanhamento vive em «Turma» e
 * «Aluno».
 *
 * Uma consulta agrupada por facto, para a lista inteira — nunca uma por turma.
 */
final class ClassOverview
{
    /**
     * @return list<array<string, mixed>>
     */
    public function for(DashboardContext $context): array
    {
        if (! $context->hasClasses()) {
            return [];
        }

        $classIds = $context->classIds();
        $period = $context->currentPeriod;

        $students = Enrollment::query()
            ->whereIn('class_id', $classIds)
            ->active()
            ->selectRaw('class_id, count(*) as total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        $absences = $context->hasLessons
            ? LessonAttendance::query()
                ->join('lessons', 'lessons.id', '=', 'lesson_attendances.lesson_id')
                ->whereIn('lessons.class_id', $classIds)
                ->whereBetween('lessons.starts_at', [$context->weekStart(), $context->weekEnd()])
                // A regra única de «assiduidade que conta» (Lesson): consolidada
                // e numa aula cujo desfecho não dispensa presenças.
                ->where(function ($query): void {
                    Lesson::whereAttendanceCounts($query);
                })
                ->where('lesson_attendances.status', 'absent')
                ->selectRaw('lessons.class_id, count(*) as total')
                ->groupBy('lessons.class_id')
                ->pluck('total', 'lessons.class_id')
            : collect();

        $openInterventions = $context->hasInterventions
            ? Intervention::query()
                ->whereIn('class_id', $classIds)
                ->whereIn('status', [InterventionStatus::New->value, InterventionStatus::InProgress->value])
                ->selectRaw('class_id, count(*) as total')
                ->groupBy('class_id')
                ->pluck('total', 'class_id')
            : collect();

        $instruments = $context->hasAssessments && $period !== null
            ? Instrument::query()
                ->whereIn('class_id', $classIds)
                ->where('academic_period_id', $period->getKey())
                ->whereNotIn('status', [InstrumentStatus::Cancelled->value, InstrumentStatus::Archived->value])
                ->get(['id', 'class_id', 'status', 'applied_on'])
                ->groupBy('class_id')
            : collect();

        $records = $context->hasRecords && $period !== null
            ? EvidenceRecord::query()
                ->whereIn('class_id', $classIds)
                ->tap(fn ($query) => FollowupWindow::forPeriod($period, $context->timeline)->applyTo($query))
                ->selectRaw('class_id, count(*) as total, max(occurred_at) as latest')
                ->groupBy('class_id')
                ->toBase()
                ->get()
                ->keyBy('class_id')
            : collect();

        $selfAssessments = $context->hasSelfAssessments && $period !== null
            ? SelfAssessment::query()
                ->join('enrollments', 'self_assessments.enrollment_id', '=', 'enrollments.id')
                ->whereIn('enrollments.class_id', $classIds)
                ->where('self_assessments.academic_period_id', $period->getKey())
                ->selectRaw('enrollments.class_id, self_assessments.status, count(*) as total')
                ->groupBy('enrollments.class_id', 'self_assessments.status')
                ->toBase()
                ->get()
                ->groupBy('class_id')
            : collect();

        $items = [];

        foreach ($context->classes as $class) {
            $classId = (int) $class->getKey();
            $periodInstruments = $instruments->get($classId, collect());
            $record = $records->get($classId);

            $submitted = 0;
            $totalSelf = 0;

            foreach ($selfAssessments->get($classId, collect()) as $row) {
                $status = (string) $row->status;
                $totalSelf += (int) $row->total;

                if ($status !== SelfAssessmentStatus::Draft->value) {
                    $submitted += (int) $row->total;
                }
            }

            $items[] = [
                'class' => $context->classSummary($class),
                'label' => $class->label,
                'subject' => $class->subject?->name,
                'students' => (int) ($students[$classId] ?? 0),
                // Sem perfil de avaliação não há propostas: o cartão avisa, como o painel antigo avisava.
                'has_profile' => $class->assessment_profile_version_id !== null,
                'next_lesson' => $this->nextLesson($context, $class),
                'absences_this_week' => $context->hasLessons ? (int) ($absences[$classId] ?? 0) : null,
                'open_interventions' => $context->hasInterventions ? (int) ($openInterventions[$classId] ?? 0) : null,
                'instruments' => $context->hasAssessments && $period !== null ? $this->instrumentCounts($periodInstruments, $period->label, $context->today()) : null,
                'records_in_period' => $context->hasRecords && $period !== null ? (int) ($record->total ?? 0) : null,
                'last_record_at' => $record?->latest === null ? null : CarbonImmutable::parse((string) $record->latest)->setTimezone(DashboardContext::TIMEZONE)->toIso8601String(),
                'self_assessments' => $context->hasSelfAssessments && $totalSelf > 0 ? ['submitted' => $submitted, 'total' => $totalSelf] : null,
                'hrefs' => [
                    'class' => route('classes.show', $class),
                    'assessments' => $context->hasAssessments ? route('assessments.index') : null,
                    'classifications' => $context->hasAssessments ? route('classifications.show', $class) : null,
                    'records' => $context->hasRecords ? route('records.index') : null,
                ],
            ];
        }

        return $items;
    }

    /**
     * @return array{starts_at: string, date: string, start_time: string, is_today: bool}|null
     */
    private function nextLesson(DashboardContext $context, SchoolClass $class): ?array
    {
        if (! $context->hasLessons) {
            return null;
        }

        foreach ($context->weekOccurrences[(int) $class->getKey()] ?? [] as $occurrence) {
            if ($occurrence['starts_at']->greaterThan($context->now)) {
                return [
                    'starts_at' => $occurrence['starts_at']->toIso8601String(),
                    'date' => $occurrence['starts_at']->toDateString(),
                    'start_time' => $occurrence['starts_at']->format('H:i'),
                    'is_today' => $occurrence['starts_at']->toDateString() === $context->today(),
                ];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Instrument>  $instruments
     * @return array{period_label: string, total: int, corrected: int, in_correction: int, applied: int, to_apply: int, drafts: int}
     */
    private function instrumentCounts($instruments, string $periodLabel, string $today): array
    {
        // Um rascunho ainda não é um elemento do período: conta-se à parte e
        // fica fora do total e das barras, como nas marcas da régua.
        $counts = ['period_label' => $periodLabel, 'total' => 0, 'corrected' => 0, 'in_correction' => 0, 'applied' => 0, 'to_apply' => 0, 'drafts' => 0];

        foreach ($instruments as $instrument) {
            if ($instrument->status === InstrumentStatus::Draft) {
                $counts['drafts']++;

                continue;
            }

            $counts['total']++;

            match ($instrument->status) {
                InstrumentStatus::Completed, InstrumentStatus::Published => $counts['corrected']++,
                InstrumentStatus::InCorrection => $counts['in_correction']++,
                InstrumentStatus::Prepared => $instrument->applied_on->toDateString() < $today
                    ? $counts['applied']++
                    : $counts['to_apply']++,
                default => $counts['to_apply']++,
            };
        }

        return $counts;
    }
}
