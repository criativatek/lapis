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
use App\Support\Lessons\LessonNumberingFreeze;
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
 *     temporariamente atrás do outro, sem alinhamento artificial por data.
 *     UM NÚMERO INDICADO PELO PROFESSOR NÃO GERA SALTOS (0.169.0): a lição que
 *     estava naquela aula passa à seguinte — a próxima unidade da turma, se for
 *     livre, ou, num grupo, a ocorrência seguinte do grupo, com a chave da
 *     lição a levar consigo o número manual;
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

            // A FOTOGRAFIA DA GUARDA, antes de fechar a aula e de mexer nas
            // chaves de lição: as lecionadas coerentes agora têm de o continuar.
            $snapshot = $this->numbering->guardSnapshot($locked->class_id);
            $droppedDrafts = $locked->attendances()->delete();

            $locked->outcome = $outcome;
            $locked->outcome_reason = $outcome === LessonOutcome::TeacherAbsent ? $reason : null;
            $locked->outcome_note = $outcome === LessonOutcome::ClassExternalActivity ? $this->cleanNote($note) : null;
            $locked->outcome_recorded_at = Carbon::now();
            $locked->outcome_recorded_by = $actor->getKey();

            $relinked = 0;
            $manualHandedOver = false;
            $wasManual = false;
            $carriedNumber = null;
            $carriedEnd = null;
            $hadUnitKey = false;
            $leftover = null;

            if ($outcome === LessonOutcome::TeacherAbsent) {
                $wasManual = $locked->lesson_number_manual && $locked->lesson_number !== null;
                $carriedNumber = $locked->lesson_number;
                $carriedEnd = $locked->lesson_number_end;
                $hadUnitKey = $locked->class_group_id !== null && $locked->lesson_unit_key !== null;

                ['relinked' => $relinked, 'leftover' => $leftover] = $this->handOverLessonUnit($locked);
                $locked->lesson_number = null;
                $locked->lesson_number_end = null;
                $locked->lesson_number_manual = false;
                $locked->lesson_unit_key = null;
            }

            $locked->save();

            if ($outcome === LessonOutcome::TeacherAbsent && $wasManual && $carriedNumber !== null) {
                // Num grupo com lição partilhada o número viajou com a chave; nos
                // outros casos passa à próxima unidade da turma, se esta for livre.
                $manualHandedOver = $hadUnitKey
                    ? $relinked > 0
                    : $this->numbering->handOverManualNumber($locked, $carriedNumber, $carriedEnd);
            }

            $planning = $this->planning->from($locked, $actor);

            // A ÚLTIMA CHAVE DESLOCADA DA FILA fica sem aula: se levava um número
            // manual e nenhum parceiro a mantém, o número passa à próxima unidade
            // livre da turma depois da última aula religada — já com a ocorrência
            // que o deslocamento do planeamento possa ter materializado.
            if ($leftover !== null) {
                $manualHandedOver = $this->numbering->handOverManualNumber($leftover['after'], $leftover['number'], $leftover['end']) || $manualHandedOver;
            }

            $renumbered = $this->renumber($locked->class_id, $planning['materialized'], $snapshot);

            // ShiftLessonPlanning só retira o sumário da aula fechada quando
            // algum campo tem texto — uma linha totalmente em branco fica
            // para trás. Regra canónica: nenhum resultado que não seja
            // «lecionada» mantém sumário, nem em branco.
            //
            // Apagado pelo MODELO, e não por `summary()->delete()`: o delete do
            // query builder não dispara eventos e deixava `summary_version`
            // parada, de modo que um cartão aberto antes ainda gravava por cima.
            $locked->summary()->first()?->delete();

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
                    'manual_number_handed_over' => $manualHandedOver,
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
     * @param  array<int, int>  $snapshot  de `LessonNumbering::guardSnapshot()`
     * @return array<int, int|null>
     */
    private function renumber(int $classId, bool $materialized, array $snapshot): array
    {
        if (! $materialized) {
            return $this->numbering->resequence($classId, $snapshot);
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
    /**
     * @return array{relinked: int, leftover: array{after: Lesson, number: int, end: int|null}|null}
     */
    private function handOverLessonUnit(Lesson $absent): array
    {
        if ($absent->class_group_id === null || $absent->lesson_unit_key === null) {
            return ['relinked' => 0, 'leftover' => null];
        }

        // A chave leva consigo o que dela depende: o número manual (início, fim e
        // marca) fica na chave, e não na aula onde por acaso estava escrito.
        //
        // COM A NUMERAÇÃO CONGELADA (`LAPIS_LESSON_NUMBERING_FROZEN`) viaja só a
        // chave: os números das aulas que já têm número não mudam.
        $frozen = LessonNumberingFreeze::frozen();
        $carry = [
            'lesson_unit_key' => $absent->lesson_unit_key,
            'lesson_number' => $absent->lesson_number,
            'lesson_number_end' => $absent->lesson_number_end,
            'lesson_number_manual' => (bool) $absent->lesson_number_manual,
        ];
        $relinked = 0;
        $after = $absent;

        foreach ($this->planning->openLessonsAfter($absent) as $next) {
            // Uma aula do grupo sem lição partilhada (tempo sem vínculo) é uma
            // lição própria: não entra na fila, e a fila continua na seguinte.
            if ($next->lesson_unit_key === null) {
                continue;
            }

            $displaced = [
                'lesson_unit_key' => $next->lesson_unit_key,
                'lesson_number' => $next->lesson_number,
                'lesson_number_end' => $next->lesson_number_end,
                'lesson_number_manual' => (bool) $next->lesson_number_manual,
            ];
            DB::table('lessons')->where('id', $next->getKey())->update($frozen ? ['lesson_unit_key' => $carry['lesson_unit_key']] : $carry);
            $carry = $displaced;
            $after = $next;
            $relinked++;
        }

        // A chave que sobra da fila: sem nenhuma outra aula que a mantenha (um
        // parceiro T2), o número manual que levava perderia-se em silêncio.
        $leftover = null;

        if (! $frozen && $carry['lesson_number_manual'] && $carry['lesson_number'] !== null && ! Lesson::query()
            ->where('class_id', $absent->class_id)
            ->where('lesson_unit_key', $carry['lesson_unit_key'])
            ->whereKeyNot($absent->getKey())
            ->exists()) {
            $leftover = ['after' => $after, 'number' => (int) $carry['lesson_number'], 'end' => $carry['lesson_number_end']];
        }

        return ['relinked' => $relinked, 'leftover' => $leftover];
    }

    private function cleanNote(?string $note): ?string
    {
        $note = $note === null ? '' : trim($note);

        return $note === '' ? null : mb_substr($note, 0, 160);
    }
}
