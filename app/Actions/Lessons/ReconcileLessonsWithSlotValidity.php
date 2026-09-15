<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\RecurringLessonSlot;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A VIGÊNCIA DE UM TEMPO DO HORÁRIO DIZ QUANDO ELE PODE PRODUZIR AULAS — e isso
 * vale também para as aulas que ele JÁ produziu (0.146.2).
 *
 * Antes disto, mudar `starts_on`/`ends_on` (edição no lugar, revisão versionada
 * ou fecho do tempo) só mexia no tempo: a aula materializada antes da mudança,
 * agora fora da vigência, ficava para sempre na semana — a «Lição 2» de 18/09 de
 * um tempo que só começa a 21/09.
 *
 * O QUE SAI: aulas do tempo, fora da vigência, ainda abertas e sem conteúdo —
 * sem resultado, não lecionadas, sem sumário, sem planeamento e sem nenhuma
 * linha de assiduidade (nem rascunho). Só essas; não há nada nelas a perder.
 *
 * O QUE FICA, SEMPRE: tudo o resto. Uma aula fechada é histórico (§14); uma
 * aula com sumário, plano ou faltas tem conteúdo pedagógico que não se perde em
 * silêncio. Ficam como estão, e são devolvidas como `preserved` para quem as
 * quiser mostrar.
 *
 * NÃO É UM CANCELAMENTO. Uma CancelledLessonOccurrence diz «esta ocorrência
 * válida não acontece». Aqui a ocorrência deixou de pertencer ao horário, e é a
 * própria vigência que impede a materialização de a recriar — pelo que marcar
 * um cancelamento só serviria para bloquear a ocorrência se a vigência voltar
 * a abranger aquela data.
 *
 * SEMPRE DENTRO DE UMA TRANSAÇÃO DE QUEM CHAMA, e com a turma já bloqueada
 * (`lockForUpdate`), a mesma disciplina de DeleteLesson, InsertLessonIntoSequence
 * e MaterializeLessonsForRange.
 */
class ReconcileLessonsWithSlotValidity
{
    private const TIMEZONE = 'Europe/Lisbon';

    public function __construct(private readonly LessonNumbering $numbering) {}

    /**
     * @param  bool  $strict  `true` (o padrão, usado pelo controlador): recusa
     *                        — e não escreve nada — se fechar a sequência
     *                        mudasse o número de uma aula já lecionada,
     *                        exatamente como `LessonNumbering::resequence()`.
     *                        `false` (usado pela materialização automática,
     *                        que corre sozinha e não pode falhar): usa o
     *                        plano B de `LessonNumbering::
     *                        numberMaterializedLessons()` em vez de recusar —
     *                        as aulas removidas ficam removidas, a turma
     *                        nunca fica sem número atribuído por causa disto.
     * @return array{removed: int, preserved: int}
     *
     * @throws ValidationException quando `$strict` e fechar a sequência
     *                             mudaria o número de uma aula já lecionada;
     *                             nada é escrito.
     */
    public function execute(int $classId, bool $strict = true): array
    {
        $removed = 0;
        $preserved = 0;

        return DB::transaction(function () use ($classId, $strict, &$removed, &$preserved): array {
            /** @var RecurringLessonSlot $slot */
            foreach (RecurringLessonSlot::query()->where('class_id', $classId)->orderBy('id')->get() as $slot) {
                if ($slot->starts_on === null && $slot->ends_on === null) {
                    continue;
                }

                $outside = Lesson::query()
                    ->where('class_id', $classId)
                    ->where('recurring_lesson_slot_id', $slot->id)
                    ->where(function ($query) use ($slot): void {
                        if ($slot->starts_on !== null) {
                            $query->orWhere('starts_at', '<', CarbonImmutable::parse($slot->starts_on->toDateString(), self::TIMEZONE)->startOfDay());
                        }

                        if ($slot->ends_on !== null) {
                            $query->orWhere('starts_at', '>=', CarbonImmutable::parse($slot->ends_on->toDateString(), self::TIMEZONE)->startOfDay()->addDay());
                        }
                    })
                    ->withExists(['summary', 'plan', 'attendances'])
                    ->lockForUpdate()
                    ->get();

                foreach ($outside as $lesson) {
                    if ($this->carriesContent($lesson)) {
                        $preserved++;

                        continue;
                    }

                    $lesson->delete();
                    $removed++;
                }
            }

            if ($removed > 0) {
                if ($strict) {
                    $this->numbering->resequence($classId);
                } else {
                    $this->numbering->numberMaterializedLessons($classId);
                }
            }

            return ['removed' => $removed, 'preserved' => $preserved];
        });
    }

    private function carriesContent(Lesson $lesson): bool
    {
        return $lesson->isClosed()
            || $lesson->status === LessonStatus::Taught
            || $lesson->attendance_recorded_at !== null
            || (bool) $lesson->getAttribute('summary_exists')
            || (bool) $lesson->getAttribute('plan_exists')
            || (bool) $lesson->getAttribute('attendances_exists');
    }
}
