<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Aula anterior» — o contexto de quem prepara uma aula: UMA só aula, a mais
 * próxima antes da aula em edição que esteja preparada ou já lecionada.
 *
 * FONTE ÚNICA. É daqui que leem o bloco «Aula anterior» da página da aula, a
 * secção do editor no cartão, o «Basear no sumário anterior»
 * (`LessonController::previousSummary`) e qualquer preparação assistida que
 * venha a existir (hoje não existe nenhuma; escolher um provedor ou enviar
 * dados fora da aplicação é uma decisão à parte). A entrada leva um ESTADO
 * EXPLÍCITO — uma aula lecionada é o que se deu; uma aula só preparada é o que
 * se planeou dar, e confundir as duas faz um professor (ou um assistente)
 * tratar um plano como matéria dada.
 *
 * ONDE PROCURA. Na mesma turma (que fixa a disciplina e o ano letivo) e no
 * MESMO público (o mesmo `class_group_id`; `NULL` é a turma inteira) — T1 e T2
 * avançam a ritmos diferentes e o contexto de um grupo nunca é o de outro.
 *
 * O QUE CONTA COMO ELEGÍVEL (decisão documentada):
 *  - «Lecionada» (`Lesson::isTaught()`): conta MESMO sem texto — é o que se
 *    deu, e o cartão diz «Sem sumário registado».
 *  - «Preparada — por lecionar»: aula ainda aberta (sem resultado e não
 *    lecionada) com CONTEÚDO que se possa mostrar: sumário, recursos ou TPC
 *    (`lesson_summaries`) ou um plano (`lesson_plans.planned_summary`, por
 *    exemplo vindo das sequências ou da planificação que desceu). Uma aula com
 *    plano e sem sumário conta como preparada e mostra o plano. O estado
 *    `LessonStatus::Prepared` NÃO chega por si: é uma consequência de guardar
 *    um sumário (SaveLessonSummary) e pode existir só com notas privadas, que
 *    não são contexto; sem nada para mostrar, a aula é «vazia».
 *  - Ficam de fora a própria aula, as posteriores, as vazias, as fechadas sem
 *    serem lecionadas («Não houve aula»: professor ausente, atividade da
 *    turma — o planeamento delas já desceu para a seguinte) e as eliminadas
 *    (não têm linha).
 *
 * ORDEM. Estrita por `(starts_at, id)` — data e hora, depois o id —, NUNCA pelo
 * número da lição, que o professor pode ter alterado à mão
 * (`lesson_number_manual`). Duas aulas no mesmo dia separam-se pela hora, e à
 * mesma hora pelo id. Sem prioridade das lecionadas: ganha a mais recente das
 * elegíveis, seja lecionada ou preparada.
 *
 * Sem notas privadas: não são «conteúdo da aula» e podem ser de outro
 * coprofessor. SÓ LEITURA: não cria nem altera aulas.
 */
final class LessonPreparationContext
{
    public const STATE_TAUGHT = 'taught';

    public const STATE_PREPARED = 'prepared';

    public const LABELS = [
        self::STATE_TAUGHT => 'Lecionada',
        self::STATE_PREPARED => 'Preparada — por lecionar',
    ];

    /** Tamanho de cada leitura em lote ao procurar entradas que realmente tenham conteúdo. */
    private const CHUNK = 50;

    /**
     * A aula anterior elegível, ou `null` se não houver.
     *
     * @return array<string, mixed>|null
     */
    public function previous(Lesson $target): ?array
    {
        foreach ($this->candidates($target)->lazy(self::CHUNK) as $lesson) {
            $entry = $this->entry($lesson);

            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * A entrada mais recente com TEXTO de sumário — o que o «Basear…» copia.
     * Devolve a entrada completa (com o estado) e a aula de origem, para o
     * chamador poder ler o que o contexto não leva (as notas privadas).
     *
     * @return array{entry: array<string, mixed>, lesson: Lesson}|null
     */
    public function latestWithSummary(Lesson $target): ?array
    {
        foreach ($this->candidates($target)->lazy(self::CHUNK) as $lesson) {
            $entry = $this->entry($lesson);

            if ($entry !== null && trim((string) $entry['content']) !== '') {
                return ['entry' => $entry, 'lesson' => $lesson];
            }
        }

        return null;
    }

    /**
     * @return Builder<Lesson>
     */
    private function candidates(Lesson $target): Builder
    {
        /** @var CarbonInterface $startsAt */
        $startsAt = $target->starts_at;

        return Lesson::query()
            ->where('class_id', $target->class_id)
            ->where(fn ($query) => $target->class_group_id === null
                ? $query->whereNull('class_group_id')
                : $query->where('class_group_id', $target->class_group_id))
            ->where(fn ($query) => $query
                ->where('starts_at', '<', $startsAt)
                ->orWhere(fn ($same) => $same->where('starts_at', $startsAt)->where('id', '<', $target->getKey())))
            ->where(fn ($query) => $query
                ->where(fn ($taught) => Lesson::whereCountsAsTaughtWithAttendance($taught))
                ->orWhere(fn ($open) => $open
                    ->whereNull('outcome')
                    ->where('status', '<>', LessonStatus::Taught->value)
                    // O TRIM só poupa candidatas; a regra final é a de `entry()`.
                    ->where(fn ($content) => $content
                        ->whereHas('summary', fn ($summary) => $summary->where(fn ($any) => $any
                            ->whereRaw("TRIM(content) <> ''")
                            ->orWhereRaw("TRIM(COALESCE(resources, '')) <> ''")
                            ->orWhereRaw("TRIM(COALESCE(homework, '')) <> ''")))
                        ->orWhereHas('plan', fn ($plan) => $plan->whereRaw("TRIM(planned_summary) <> ''")))))
            ->with(['schoolClass', 'classGroup', 'summary', 'plan'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entry(Lesson $lesson): ?array
    {
        $content = (string) $lesson->summary?->content;
        $resources = $lesson->summary?->resources;
        $homework = $lesson->summary?->homework;
        $plan = trim((string) $lesson->plan?->planned_summary);

        if ($lesson->isTaught()) {
            $state = self::STATE_TAUGHT;
        } elseif ($lesson->outcome === null
            && (trim($content) !== '' || trim((string) $resources) !== '' || trim((string) $homework) !== '' || $plan !== '')) {
            $state = self::STATE_PREPARED;
        } else {
            return null;
        }

        return [
            'ulid' => $lesson->ulid,
            'class_ulid' => $lesson->schoolClass->ulid,
            'starts_at' => $lesson->starts_at->toIso8601String(),
            'ends_at' => $lesson->ends_at?->toIso8601String(),
            'lesson_number' => $lesson->lesson_number,
            'lesson_number_end' => $lesson->lesson_number_end,
            'context_label' => $lesson->contextLabel(),
            'state' => $state,
            'state_label' => self::LABELS[$state],
            'content' => $content,
            'plan' => $plan === '' ? null : $plan,
            'resources' => $resources,
            'homework' => $homework,
        ];
    }
}
