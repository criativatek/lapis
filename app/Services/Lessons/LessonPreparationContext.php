<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * «O que veio antes desta aula» — o contexto de quem a está a preparar.
 *
 * FONTE ÚNICA. É daqui que leem o painel «Antes desta aula» da página da
 * aula, a secção compacta do editor no cartão, o «Basear no sumário anterior»
 * (`LessonController::previousSummary`) e qualquer preparação assistida que
 * venha a existir (hoje não existe nenhuma; escolher um provedor ou enviar
 * dados fora da aplicação é uma decisão à parte). Quem consumir isto recebe,
 * em cada entrada, um ESTADO EXPLÍCITO — não basta o texto: uma aula
 * lecionada é o que se deu; uma aula só preparada é o que se planeou dar, e
 * confundir as duas faz um professor (ou um assistente) tratar um plano como
 * matéria dada.
 *
 * QUE AULAS ENTRAM. As ANTERIORES à aula de destino, da mesma turma e do
 * MESMO público (o mesmo `class_group_id`; `NULL` é a turma inteira) — T1 e
 * T2 avançam a ritmos diferentes e o contexto de um grupo nunca é o de outro:
 *  - lecionadas (`Lesson::isTaught()`), com ou sem texto → «Lecionada»;
 *  - preparadas e ainda abertas (sem resultado e não lecionadas) com algum
 *    conteúdo — sumário, recursos ou TPC → «Preparada — por lecionar».
 * Ficam de fora a própria aula, as posteriores, as fechadas sem serem
 * lecionadas (professor ausente, atividade da turma: o planeamento delas já
 * desceu para a seguinte) e as abertas vazias.
 *
 * ORDEM. «Anterior» é estrito por `(starts_at, id)`: duas aulas no mesmo dia
 * separam-se pela hora, e à mesma hora pelo id. Selecionam-se as N MAIS
 * RECENTES e apresentam-se por ordem cronológica — assim um limite nunca
 * esconde as preparações mais recentes, que são, por definição, as últimas
 * antes do destino.
 *
 * Sem notas privadas: não são «conteúdo da aula» e podem ser de outro
 * coprofessor. SÓ LEITURA: não cria nem altera aulas.
 */
final class LessonPreparationContext
{
    public const DEFAULT_LIMIT = 5;

    public const MAX_LIMIT = 20;

    public const STATE_TAUGHT = 'taught';

    public const STATE_PREPARED = 'prepared';

    public const LABELS = [
        self::STATE_TAUGHT => 'Lecionada',
        self::STATE_PREPARED => 'Preparada — por lecionar',
    ];

    /** Tamanho de cada leitura em lote ao procurar entradas que realmente tenham conteúdo. */
    private const CHUNK = 50;

    /**
     * @return array{lessons: list<array<string, mixed>>, has_more: bool}
     */
    public function for(Lesson $target, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        /** @var list<array<string, mixed>> $entries mais recentes primeiro */
        $entries = [];

        foreach ($this->candidates($target)->lazy(self::CHUNK) as $lesson) {
            $entry = $this->entry($lesson);

            if ($entry === null) {
                continue;
            }

            $entries[] = $entry;

            // Uma a mais que o limite, só para saber se há mais.
            if (count($entries) > $limit) {
                break;
            }
        }

        $hasMore = count($entries) > $limit;

        return [
            'lessons' => array_reverse(array_slice($entries, 0, $limit)),
            'has_more' => $hasMore,
        ];
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
                    ->whereHas('summary', fn ($summary) => $summary->where(fn ($any) => $any
                        ->whereRaw("TRIM(content) <> ''")
                        ->orWhereRaw("TRIM(COALESCE(resources, '')) <> ''")
                        ->orWhereRaw("TRIM(COALESCE(homework, '')) <> ''")))))
            ->with(['schoolClass', 'classGroup', 'summary'])
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

        if ($lesson->isTaught()) {
            $state = self::STATE_TAUGHT;
        } elseif ($lesson->outcome === null
            && (trim($content) !== '' || trim((string) $resources) !== '' || trim((string) $homework) !== '')) {
            $state = self::STATE_PREPARED;
        } else {
            return null;
        }

        return [
            'ulid' => $lesson->ulid,
            'starts_at' => $lesson->starts_at->toIso8601String(),
            'ends_at' => $lesson->ends_at?->toIso8601String(),
            'lesson_number' => $lesson->lesson_number,
            'context_label' => $lesson->contextLabel(),
            'state' => $state,
            'state_label' => self::LABELS[$state],
            'content' => $content,
            'resources' => $resources,
            'homework' => $homework,
        ];
    }
}
