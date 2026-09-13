<?php

namespace App\Actions\Lessons;

use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\LessonNumbering;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Eliminar UMA ocorrência criada por engano — e mais nada.
 *
 * O QUE NÃO É TOCADO: o tempo do horário recorrente que a gerou, as outras
 * aulas desse tempo, a turma, o grupo e o calendário. A rotina («Português às
 * quintas») sobrevive intacta; o que desaparece é aquela quinta-feira. Abrir a
 * semana outra vez volta a criar a aula a partir do horário, exatamente como
 * criaria qualquer outra — eliminar uma ocorrência não é uma exclusão
 * permanente daquela data, é desfazer um engano.
 *
 * UMA AULA LECIONADA NUNCA É ELIMINADA POR AQUI. É histórico pedagógico
 * consolidado, e esta fatia não abre nenhuma política nova de alteração de
 * histórico: a recusa é explícita e antecede qualquer escrita.
 *
 * DEPENDÊNCIAS REAIS DE UMA Lesson, auditadas antes de escrever isto: apenas
 * `lesson_summaries` e `lesson_plans`, ambas hasOne e ambas `restrictOnDelete`.
 * Nenhuma avaliação, evidência ou classificação aponta para uma aula, pelo que
 * eliminar uma ocorrência não pode levar histórico de avaliação atrás. As duas
 * filhas são removidas aqui, na mesma transação, porque `restrictOnDelete`
 * rebentaria de outra forma — e porque é isso que a confirmação no ecrã diz
 * que vai acontecer.
 */
class DeleteLesson
{
    public function __construct(
        protected AuditLog $audit,
        protected LessonNumbering $numbering,
    ) {}

    /**
     * @return array<int, int> as aulas da sequência que mudaram de número
     */
    public function execute(Lesson $lesson, User $actor): array
    {
        return DB::transaction(function () use ($actor, $lesson): array {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            if ($locked->status === LessonStatus::Taught) {
                throw ValidationException::withMessages([
                    'lesson' => __('Uma aula já lecionada não pode ser eliminada.'),
                ]);
            }

            $classId = $locked->class_id;
            $classGroupId = $locked->class_group_id;

            $this->audit->record(
                'lesson.deleted',
                $locked,
                $actor,
                'Aula eliminada.',
                [
                    'starts_at' => $locked->starts_at->toIso8601String(),
                    'status' => $locked->status->value,
                    'lesson_number' => $locked->lesson_number,
                    'had_summary' => $locked->summary()->exists(),
                    'had_plan' => $locked->plan()->exists(),
                ],
            );

            // A OCORRÊNCIA FICA MARCADA, e a rotina não é tocada. Sem isto,
            // abrir a semana outra vez materializava a aula de volta a partir do
            // mesmo tempo do horário, e o «eliminar» desfazia-se sozinho ao
            // recarregar a página. Só as aulas NASCIDAS de um tempo do horário
            // precisam da marca: uma aula inserida à mão não tem quem a recrie.
            if ($locked->recurring_lesson_slot_id !== null) {
                CancelledLessonOccurrence::query()->updateOrCreate(
                    [
                        'class_id' => $locked->class_id,
                        'recurring_lesson_slot_id' => $locked->recurring_lesson_slot_id,
                        'occurs_at' => $locked->starts_at,
                    ],
                    [
                        'class_group_id' => $locked->class_group_id,
                        'cancelled_by' => $actor->getKey(),
                    ],
                );
            }

            $locked->summary()->delete();
            $locked->plan()->delete();
            $locked->delete();

            // Fecha o buraco que a eliminação deixou. Recusa-se por inteiro se
            // isso exigisse renumerar uma aula já lecionada — caso em que a
            // transação reverte e a aula não chega a ser eliminada.
            return $this->numbering->resequence($classId, $classGroupId);
        });
    }
}
