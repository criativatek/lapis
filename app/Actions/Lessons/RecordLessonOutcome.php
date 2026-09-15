<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\SchoolClass;
use App\Models\TeacherAbsenceReason;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\ShiftLessonPlanning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Esta aula não aconteceu como planeada» — fechar uma ocorrência como
 * PROFESSOR AUSENTE ou TURMA EM OUTRAS ATIVIDADES LETIVAS (0.146.0).
 * «Lecionada» continua a ser MarkLessonAsTaught.
 *
 * Numa só transação, com a turma bloqueada, e com todas as recusas antes de
 * qualquer escrita:
 *  1. o resultado fica gravado (motivo só por categoria; nota curta só na
 *     atividade da turma);
 *  2. os rascunhos de falta desaparecem — a assiduidade não se aplica;
 *  3. professor ausente: a aula sai da numeração e larga a sua lição. Num
 *     grupo com lição partilhada (T1/T2), a lição passa à ocorrência seguinte
 *     DESSE grupo, e cada uma das seguintes recua uma — o grupo fica
 *     temporariamente atrás do outro, sem alinhamento artificial por data;
 *  4. o planeamento desce uma posição (ShiftLessonPlanning);
 *  5. a turma é renumerada — e se isso mexesse no número de uma lecionada, a
 *     transação inteira reverte.
 */
class RecordLessonOutcome
{
    public function __construct(
        protected AuditLog $audit,
        protected LessonNumbering $numbering,
        protected ShiftLessonPlanning $planning,
    ) {}

    public function execute(
        Lesson $lesson,
        LessonOutcome $outcome,
        User $actor,
        ?TeacherAbsenceReason $reason = null,
        ?string $note = null,
    ): Lesson {
        if ($outcome === LessonOutcome::Taught) {
            throw new \InvalidArgumentException('Lecionada regista-se em MarkLessonAsTaught.');
        }

        return DB::transaction(function () use ($actor, $lesson, $note, $outcome, $reason): Lesson {
            SchoolClass::query()->whereKey($lesson->class_id)->lockForUpdate()->first();

            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            $this->guard($locked, $outcome, $reason);

            $fromNumber = $locked->lesson_number;
            $droppedDrafts = $locked->attendances()->delete();

            $locked->outcome = $outcome;
            $locked->outcome_reason = $outcome === LessonOutcome::TeacherAbsent ? $reason : null;
            $locked->outcome_note = $outcome === LessonOutcome::ClassExternalActivity ? $this->cleanNote($note) : null;
            $locked->outcome_recorded_at = Carbon::now();
            $locked->outcome_recorded_by = $actor->getKey();

            $relinked = 0;

            if ($outcome === LessonOutcome::TeacherAbsent) {
                $relinked = $this->handOverLessonUnit($locked);
                $locked->lesson_number = null;
                $locked->lesson_unit_key = null;
            }

            $locked->save();

            $planning = $this->planning->from($locked, $actor);
            $renumbered = $this->renumber($locked->class_id, $planning['materialized']);

            // SEM TEXTO: nem a nota da atividade nem nada sobre a pessoa. A
            // categoria do motivo é um código fechado.
            $this->audit->record(
                'lesson.outcome_recorded',
                $locked,
                $actor,
                'Resultado da aula registado.',
                [
                    'outcome' => $outcome->value,
                    'reason' => $locked->outcome_reason?->value,
                    'has_note' => $locked->outcome_note !== null,
                    'from_lesson_number' => $fromNumber,
                    'dropped_attendance_drafts' => $droppedDrafts,
                    'planning_shifted' => $planning['shifted'],
                    'planning_materialized' => $planning['materialized'],
                    'planning_pending' => $planning['pending'],
                    'lesson_units_relinked' => $relinked,
                    'renumbered_lessons' => count($renumbered),
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * A renumeração normal, que recusa mexer no número de uma lecionada.
     *
     * UMA EXCEÇÃO: se o deslocamento do planeamento teve de materializar a
     * ocorrência seguinte, essa aula já foi numerada pelo caminho automático
     * (`numberMaterializedLessons`), que cai no plano B quando a ordem estrita
     * colidiria com o histórico. Repetir aqui a renumeração estrita desfaria
     * esse plano B e recusaria justamente o caso T1/T2 dessincronizado. Nesse
     * caso usa-se o mesmo caminho automático; sem materialização, a recusa
     * continua a reverter tudo.
     *
     * @return array<int, int>
     */
    private function renumber(int $classId, bool $materialized): array
    {
        if (! $materialized) {
            return $this->numbering->resequence($classId);
        }

        return $this->numbering->numberMaterializedLessons($classId);
    }

    private function guard(Lesson $locked, LessonOutcome $outcome, ?TeacherAbsenceReason $reason): void
    {
        if ($locked->isClosed()) {
            throw ValidationException::withMessages([
                'outcome' => __('Esta aula já tem resultado registado.'),
            ]);
        }

        if ($outcome === LessonOutcome::TeacherAbsent && $reason === null) {
            throw ValidationException::withMessages([
                'reason' => __('Indica o motivo da ausência.'),
            ]);
        }

        if ($locked->attendanceRecorded()) {
            throw ValidationException::withMessages([
                'outcome' => __('A assiduidade desta aula já foi registada: não pode ficar sem assiduidade aplicável.'),
            ]);
        }

        $closedAfter = $this->planning->closedLessonAfter($locked);

        if ($closedAfter !== null) {
            throw ValidationException::withMessages([
                'outcome' => __(
                    'A aula de :date, depois desta, já está fechada. Os resultados registam-se por ordem, para que o planeamento não passe por cima do que já aconteceu.',
                    ['date' => $closedAfter->starts_at->setTimezone('Europe/Lisbon')->format('d/m/Y')],
                ),
            ]);
        }
    }

    /**
     * A fila de lições do grupo recua uma posição: a lição da aula ausente
     * passa à ocorrência aberta seguinte do mesmo grupo, a dela à seguinte, e
     * assim até à última, cuja lição fica livre — a próxima aula desse grupo a
     * nascer junta-se-lhe pela regra normal de LessonNumbering.
     *
     * Só ocorrências do MESMO tempo ligado (mesmo `split_lesson_key`
     * implícito): as lições são as da fila deste grupo, nunca de outro.
     */
    private function handOverLessonUnit(Lesson $absent): int
    {
        if ($absent->class_group_id === null || $absent->lesson_unit_key === null) {
            return 0;
        }

        $carry = $absent->lesson_unit_key;
        $relinked = 0;

        foreach ($this->planning->openLessonsAfter($absent) as $next) {
            // Uma aula do grupo sem lição partilhada (tempo sem vínculo) é uma
            // lição própria: não entra na fila, e a fila continua na seguinte.
            if ($next->lesson_unit_key === null) {
                continue;
            }

            $displaced = $next->lesson_unit_key;
            DB::table('lessons')->where('id', $next->getKey())->update(['lesson_unit_key' => $carry]);
            $carry = $displaced;
            $relinked++;
        }

        return $relinked;
    }

    private function cleanNote(?string $note): ?string
    {
        $note = $note === null ? '' : trim($note);

        return $note === '' ? null : mb_substr($note, 0, 160);
    }
}
