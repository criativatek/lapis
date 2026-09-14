<?php

namespace App\Actions\Lessons;

use App\Models\RecurringLessonSlot;
use Illuminate\Support\Str;

/**
 * «Estes tempos correspondem à mesma lição» — gravar (ou desfazer) o vínculo
 * explícito entre tempos de grupos da mesma turma (0.145.2).
 *
 * O professor nunca vê a chave: escolhe o tempo do outro grupo, e os dois
 * passam a partilhar `split_lesson_key`. Ligar a um tempo que já pertence a um
 * conjunto (T1+T2) junta este ao conjunto — é assim que se chega a T1+T2+T3.
 *
 * DESFAZER SÓ VALE PARA O FUTURO: as aulas já ligadas guardam o seu
 * `lesson_unit_key` e continuam a ser a mesma lição; as próximas deixam de se
 * ligar. Um tempo da turma inteira nunca tem chave.
 */
class LinkSplitLessonSlot
{
    public function execute(RecurringLessonSlot $slot, ?RecurringLessonSlot $partner): void
    {
        if ($slot->class_group_id === null || $partner === null) {
            $slot->update(['split_lesson_key' => null]);

            return;
        }

        $key = $partner->split_lesson_key ?? (string) Str::ulid();

        if ($partner->split_lesson_key === null) {
            $partner->update(['split_lesson_key' => $key]);
        }

        $slot->update(['split_lesson_key' => $key]);
    }
}
