<?php

namespace App\Actions\Lessons;

use App\Models\AttendanceStatus;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\SchoolClass;
use App\Models\TeacherStrikeSituation;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\ShiftLessonPlanning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ANULAR UM «LECIONADA»: a aula não se realizou (0.194.0).
 *
 * O registo normal de «Aula não realizada» recusa aulas fechadas e o retroativo
 * só aceita aulas vazias; esta é a única porta para desfazer um fecho dado por
 * engano. Faz, numa transação com a turma bloqueada, o que o registo normal
 * faria — e nunca reescreve uma aula lecionada depois desta:
 *
 *  - a assiduidade é apagada (não há aula, não há faltas);
 *  - sem aulas fechadas a seguir, o sumário desce para a aula seguinte
 *    (ShiftLessonPlanning) e a numeração fecha sem lacuna; com aulas fechadas a
 *    seguir, o sumário sai da aula para o histórico e a numeração aceita a
 *    lacuna (as fechadas são fixas);
 *  - o número manual passa à próxima unidade livre, como no registo normal;
 *  - o TITULAR DO FECHO NÃO MUDA (`outcome_recorded_by`): é ele que lê o seu
 *    histórico; quem anulou fica na linha do histórico (`rectified_by`).
 *
 * Quem pode é a policy (`LessonPolicy::annulTaught`): o titular, ou a direção
 * de uma organização institucional, que nunca lê o sumário — o texto só viaja
 * para o histórico, que só o titular lê (LessonOutcomeRectificationView).
 */
final class AnnulTaughtLesson
{
    /** Os campos do sumário que vão para o histórico quando o texto não pode descer. */
    private const array SUMMARY_FIELDS = ['content', 'resources', 'homework', 'private_notes'];

    public function __construct(
        protected RecordLessonOutcome $recorder,
        protected RectifyLessonOutcome $rectifier,
        protected LessonNumbering $numbering,
        protected ShiftLessonPlanning $planning,
        protected AuditLog $audit,
    ) {}

    /**
     * @return array{lesson: Lesson, planning: array<string, mixed>|null, summary_kept_in_history: bool, renumbered: array<int, int|null>}
     */
    public function execute(
        Lesson $lesson,
        User $actor,
        LessonNotHeldCause $cause,
        string $motive,
        ?string $causeNote = null,
        ?TeacherStrikeSituation $situation = null,
    ): array {
        return DB::transaction(function () use ($lesson, $actor, $cause, $motive, $causeNote, $situation): array {
            SchoolClass::query()->whereKey($lesson->class_id)->lockForUpdate()->first();

            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            $motive = $this->guard($locked, $cause, $motive, $causeNote, $situation);

            $fromNumber = $locked->lesson_number === null ? null : (int) $locked->lesson_number;
            $closedAfter = $this->planning->closedLessonAfter($locked);
            $snapshot = $this->numbering->guardSnapshot($locked->class_id);
            $previous = $this->rectifier->snapshot($locked) + ['lesson_number' => $fromNumber];

            // 1. Assiduidade: não há aula, não há faltas. Só contagens para a auditoria.
            $absences = $locked->attendances()->where('status', AttendanceStatus::Absent)->count();
            $rows = $locked->attendances()->delete();
            $locked->attendance_recorded_at = null;
            $locked->attendance_recorded_by = null;

            // 2. Resultado. O titular do fecho fica.
            $locked->outcome = LessonOutcome::NotHeld;
            $locked->outcome_reason = null;
            $locked->outcome_note = null;
            $locked->outcome_cause = $cause;
            $locked->outcome_teacher_situation = $cause === LessonNotHeldCause::Strike ? $situation : null;
            $locked->outcome_cause_note = $cause === LessonNotHeldCause::Other ? $this->recorder->cleanNote($causeNote) : null;
            $locked->outcome_recorded_at = Carbon::now();
            $locked->status = LessonStatus::Preparation;

            // 3. Número: um manual passa à frente; a aula fica sem número.
            $manualNumber = $locked->lesson_number_manual ? $fromNumber : null;
            $locked->lesson_number = null;
            $locked->lesson_number_end = null;
            $locked->lesson_number_manual = false;
            $locked->save();

            $manualHandedOver = $manualNumber !== null
                ? $this->numbering->handOverManualNumber($locked, $manualNumber)
                : false;

            // 4. Sumário e planeamento.
            $planning = null;
            $summaryKept = null;

            if ($closedAfter === null) {
                $planning = $this->planning->from($locked, $actor);
            } else {
                $summaryKept = $this->takeSummaryIntoHistory($locked);
            }

            // 5. Renumeração: as fechadas são fixas; sem fechadas a seguir, a
            // sequência fecha-se como em RecordLessonOutcome::renumber.
            $renumbered = match (true) {
                $closedAfter !== null => $this->numbering->resequenceAcceptingGaps($locked->class_id),
                $planning !== null && $planning['materialized'] => $this->numbering->numberMaterializedLessons($locked->class_id),
                default => $this->numbering->resequence($locked->class_id, $snapshot),
            };

            $locked->refresh();
            $current = $this->rectifier->snapshot($locked);

            LessonOutcomeRectification::create([
                'lesson_id' => $locked->getKey(),
                'rectified_by' => $actor->getKey(),
                'rectified_at' => Carbon::now(),
                'kind' => LessonOutcomeRectification::KIND_ANNULMENT,
                'previous' => $summaryKept === null ? $previous : $previous + $summaryKept,
                'current' => $current,
                'motive' => $motive,
            ]);

            // SÓ CÓDIGOS E CONTAGENS: nem o texto do sumário nem o motivo.
            $this->audit->record(
                'lesson.taught_annulled',
                $locked,
                $actor,
                'Marcação de lecionada anulada: a aula não se realizou.',
                [
                    'cause' => $cause->value,
                    'from_lesson_number' => $fromNumber,
                    'attendance_rows_deleted' => $rows,
                    'absences_deleted' => $absences,
                    'summary_shifted' => $planning !== null && $planning['shifted'] > 0,
                    'summary_kept_in_history' => $summaryKept !== null,
                    'planning_shifted' => $planning === null ? 0 : $planning['shifted'],
                    'planning_materialized' => $planning !== null && $planning['materialized'],
                    'planning_pending' => $planning !== null && $planning['pending'],
                    'manual_number_handed_over' => $manualHandedOver,
                    'renumbered_lessons' => count($renumbered),
                    'gaps' => $this->gapsIn($locked->class_id),
                    'by_direction' => (int) $locked->outcome_recorded_by !== (int) $actor->getKey(),
                ],
            );

            return [
                'lesson' => $locked->refresh(),
                'planning' => $planning,
                'summary_kept_in_history' => $summaryKept !== null,
                'renumbered' => $renumbered,
            ];
        });
    }

    /**
     * @throws ValidationException
     */
    private function guard(Lesson $locked, LessonNotHeldCause $cause, string $motive, ?string $causeNote, ?TeacherStrikeSituation $situation): string
    {
        if (! $locked->isTaught()) {
            throw ValidationException::withMessages(['outcome' => __('Só uma aula marcada como lecionada pode ser anulada.')]);
        }

        if ($locked->lesson_unit_key !== null) {
            throw ValidationException::withMessages(['outcome' => __('Esta aula partilha a lição com outro grupo: ainda não se anula por aqui.')]);
        }

        $motive = trim($motive);

        if (mb_strlen($motive) < RectifyLessonOutcome::MOTIVE_MIN || mb_strlen($motive) > RectifyLessonOutcome::MOTIVE_MAX) {
            throw ValidationException::withMessages([
                'rectification_note' => __('Indica o motivo da correção (entre :min e :max carateres).', ['min' => RectifyLessonOutcome::MOTIVE_MIN, 'max' => RectifyLessonOutcome::MOTIVE_MAX]),
            ]);
        }

        $this->recorder->guardDetails(LessonOutcome::NotHeld, null, $cause, $situation, $causeNote);

        return $motive;
    }

    /**
     * O texto do sumário sai da aula (uma aula que não houve não fica a dizer
     * que houve) e vai para o histórico, que só o titular lê. Apagado pelo
     * MODELO para subir `summary_version` — um cartão aberto antes não grava
     * por cima. `null` quando não havia texto nenhum.
     *
     * @return array<string, string|null>|null
     */
    private function takeSummaryIntoHistory(Lesson $locked): ?array
    {
        /** @var LessonSummary|null $summary */
        $summary = $locked->summary()->lockForUpdate()->first();

        if ($summary === null) {
            return null;
        }

        $values = [];

        foreach (self::SUMMARY_FIELDS as $field) {
            $value = $summary->getAttribute($field);
            $values['summary_'.$field] = is_string($value) && trim($value) !== '' ? $value : null;
        }

        $summary->delete();

        return array_filter($values) === [] ? null : $values;
    }

    /**
     * As lacunas da numeração da turma depois da anulação (só para a auditoria).
     *
     * @return list<int>
     */
    private function gapsIn(int $classId): array
    {
        $numbers = Lesson::query()
            ->where('class_id', $classId)
            ->whereNotNull('lesson_number')
            ->pluck('lesson_number')
            ->map(fn ($number): int => (int) $number)
            ->unique()
            ->sort()
            ->values();

        if ($numbers->isEmpty()) {
            return [];
        }

        return array_values(array_diff(range((int) $numbers->first(), (int) $numbers->last()), $numbers->all()));
    }
}
