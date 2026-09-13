<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Limpar sumário» — apagar o TEXTO do sumário sem apagar a aula.
 *
 * A DISTINÇÃO QUE ESTA AÇÃO EXISTE PARA FAZER: eliminar a aula tira-a do
 * horário; limpar o sumário deixa a aula exatamente onde está — data, hora,
 * turma, grupo, tempo do horário, número da lição — e devolve-a ao estado de
 * quem ainda não a preparou.
 *
 * O QUE É APAGADO É O `content`, E SÓ ELE. `private_notes`, `resources` e
 * `homework` são campos separados da mesma linha, com significados próprios, e
 * o professor que pede «limpar sumário» não está a pedir que as notas e o TPC
 * desapareçam com ele. Como `content` é NOT NULL na tabela, a linha inteira só
 * é removida quando mais nada lá vive; havendo notas, recursos ou TPC, o
 * `content` fica vazio e o resto permanece.
 *
 * O ESTADO REGRESSA A «Por preparar» porque é exatamente a transição inversa
 * da que SaveLessonSummary faz ao gravar (preparation → prepared). O estado
 * «Preparado» nesta aplicação quer dizer «tem sumário escrito»; sem sumário,
 * dizê-lo seria falso.
 *
 * UMA AULA LECIONADA NÃO É LIMPA POR AQUI. Editar o sumário de uma aula dada
 * continua a ser possível pelo caminho que sempre foi (SaveLessonSummary, que
 * regista a revisão), mas esvaziá-lo é outra coisa: seria apagar o registo do
 * que aconteceu.
 */
class ClearLessonSummary
{
    public function __construct(protected AuditLog $audit) {}

    public function execute(Lesson $lesson, User $actor): void
    {
        DB::transaction(function () use ($actor, $lesson): void {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            if ($locked->status === LessonStatus::Taught) {
                throw ValidationException::withMessages([
                    'summary' => __('O sumário de uma aula já lecionada não pode ser limpo.'),
                ]);
            }

            $summary = $locked->summary()->first();

            if ($summary === null) {
                return;
            }

            $keepsOtherContent = ($summary->private_notes ?? '') !== ''
                || ($summary->resources ?? '') !== ''
                || ($summary->homework ?? '') !== '';

            if ($keepsOtherContent) {
                $summary->content = '';
                $summary->save();
            } else {
                $summary->delete();
            }

            if ($locked->status === LessonStatus::Prepared) {
                $locked->status = LessonStatus::Preparation;
                $locked->save();
            }

            $this->audit->record(
                'lesson.summary_cleared',
                $locked,
                $actor,
                'Sumário de aula limpo.',
                [
                    'kept_optional_details' => $keepsOtherContent,
                    'to_status' => $locked->status->value,
                ],
            );
        });
    }
}
