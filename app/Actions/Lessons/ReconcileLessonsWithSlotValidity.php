<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonOrigin;
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
 * silêncio; e uma aula com NÚMERO INDICADO PELO PROFESSOR (0.169.0) também —
 * sem sumário, é a parceira T2 de uma lição fixada em T1, a que ficou depois de
 * «Limpar sumário» ou a que recebeu o número numa ausência: apagá-la perdia uma
 * decisão do professor. Ficam como estão, e são devolvidas como `preserved` para quem as
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
     * @return array{removed: int, preserved: int, renumbered: array<int, int|null>} `renumbered`: lesson_id =>
     *                                                                               número novo das aulas que a
     *                                                                               renumeração mudou (NULL = ficou
     *                                                                               sem número)
     *
     * @throws ValidationException quando `$strict` e fechar a sequência
     *                             mudaria o número de uma aula já lecionada;
     *                             nada é escrito.
     */
    public function execute(int $classId, bool $strict = true): array
    {
        $removed = 0;
        $preserved = 0;
        $renumbered = [];

        return DB::transaction(function () use ($classId, $strict, &$removed, &$preserved, &$renumbered): array {
            // A FOTOGRAFIA DA GUARDA (só no caminho estrito), antes de apagar
            // seja o que for.
            $snapshot = $strict ? $this->numbering->guardSnapshot($classId) : [];

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
                    if ($this->carriesContent($lesson) || $this->carriesContentNow($lesson)) {
                        $preserved++;

                        continue;
                    }

                    $lesson->delete();
                    $removed++;
                }
            }

            // O PONTO CEGO DA RECONCILIAÇÃO ACIMA: o `foreach` só vê aulas
            // com `recurring_lesson_slot_id = $slot->id` — uma aula ÓRFÃ
            // (FK NULL) não pertence a slot nenhum e nunca é revisitada por
            // ele, ainda que tenha nascido do horário. Isto acontecia quando
            // `LessonScheduleController::destroy()` fazia um hard delete
            // sobre um slot que, por uma corrida, já tinha uma Lesson
            // materializada por baixo — a FK `nullOnDelete` orfanava-a em vez
            // de a apagar. Esse hard delete já lê `$hasLessons` sob bloqueio
            // (ver destroy()), por isso a corrida está fechada DAQUI PARA A
            // FRENTE; esta passagem cobre o resto: o estado que já ficou
            // órfão antes da correção, e qualquer outro caminho futuro que
            // deixe a mesma marca.
            //
            // Só `origin = schedule`: uma órfã MANUAL nunca nasceu de um
            // tempo do horário — apagá-la seria apagar uma aula que o
            // professor pôs ali de propósito. Só FUTURA: uma órfã passada é
            // história, ainda que vazia — não se reescreve o que já
            // aconteceu só porque perdeu o ponteiro para o slot. Só VAZIA
            // (`carriesContent()`, o MESMO critério do resto desta ação):
            // sumário, plano, faltas ou lecionada protegem-na tal como
            // protegem qualquer outra.
            $orphaned = Lesson::query()
                ->where('class_id', $classId)
                ->whereNull('recurring_lesson_slot_id')
                ->where('origin', LessonOrigin::Schedule)
                ->where('starts_at', '>=', CarbonImmutable::now(self::TIMEZONE))
                ->withExists(['summary', 'plan', 'attendances'])
                ->lockForUpdate()
                ->get();

            foreach ($orphaned as $lesson) {
                if ($this->carriesContent($lesson) || $this->carriesContentNow($lesson)) {
                    $preserved++;

                    continue;
                }

                $lesson->delete();
                $removed++;
            }

            if ($removed > 0) {
                $renumbered = $strict
                    ? $this->numbering->resequence($classId, $snapshot)
                    : $this->numbering->numberMaterializedLessons($classId);
            }

            return ['removed' => $removed, 'preserved' => $preserved, 'renumbered' => $renumbered];
        });
    }

    /**
     * A definição vive em `Lesson::carriesContent()` (partilhada com a
     * reconciliação com o calendário); aqui só se nomeia.
     */
    private function carriesContent(Lesson $lesson): bool
    {
        return $lesson->carriesContent();
    }

    /**
     * A MESMA PERGUNTA, COM LEITURAS CORRENTES — só para a aula que se ia
     * apagar. O `withExists` da consulta de cima é uma subconsulta dentro de um
     * SELECT … FOR UPDATE: em InnoDB (REPEATABLE READ) lê do retrato da
     * transação, não do que está gravado. Quando a reconciliação com o
     * calendário materializa (ReconcileLessonsWithCalendarExceptions), o
     * retrato foi tirado muito antes — e um rascunho de faltas gravado depois
     * dele numa aula fora do intervalo preso não se via: a aula era apagada e
     * `cascadeOnDelete` levava o rascunho. Lidas aqui uma a uma e com FOR
     * UPDATE (a aula já está presa: a mesma ordem aula → filhos de
     * SaveLessonSummary), veem o que foi gravado.
     *
     * Sem concorrência dá a mesma resposta que `carriesContent()`; custa três
     * leituras por aula a apagar, e as aulas apagadas aqui são raras.
     */
    private function carriesContentNow(Lesson $lesson): bool
    {
        return $lesson->summary()->select('id')->lockForUpdate()->first() !== null
            || $lesson->plan()->select('id')->lockForUpdate()->first() !== null
            || $lesson->attendances()->select('id')->lockForUpdate()->first() !== null;
    }
}
