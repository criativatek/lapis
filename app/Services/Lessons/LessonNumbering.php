<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\RecurringLessonSlot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Lição 1, Lição 2, Lição 3» — a numeração sequencial das aulas, garantida no
 * servidor e em mais lado nenhum.
 *
 * UMA SÓ FUNÇÃO, CHAMADA DEPOIS DE TUDO. Em vez de cada caminho de escrita
 * calcular o seu próximo número — a materialização, a inserção intermédia, a
 * eliminação, o deslocamento —, todos eles mexem no que têm de mexer e depois
 * pedem aqui uma renumeração da turma. A ordem numérica é recalculada da ordem
 * cronológica real, e não mantida em paralelo com ela.
 *
 * O ÂMBITO É A TURMA (0.145.2). T1 e T2 não são turmas: são desdobramentos da
 * mesma turma. Uma turma de apoio é uma SchoolClass própria e tem, por isso,
 * sequência própria.
 *
 * A UNIDADE DE LIÇÃO É EXPLÍCITA, NUNCA INFERIDA DA DATA:
 *  - uma aula da turma inteira é, sozinha, uma unidade;
 *  - uma aula de grupo cujo tempo do horário não tem `split_lesson_key` é,
 *    sozinha, uma unidade;
 *  - aulas de grupo com o mesmo `lesson_unit_key` são a mesma lição e partilham
 *    o número.
 *
 * A LIGAÇÃO É GRAVADA UMA VEZ E NUNCA RECALCULADA. Uma aula de grupo ainda sem
 * `lesson_unit_key`, vinda de um tempo com `split_lesson_key` K, junta-se à
 * lição mais antiga de K a que falte o seu grupo (e que não seja anterior ao
 * início desse tempo); se não houver, abre uma lição nova. É uma fila por grupo
 * dentro dos tempos ligados: se T2 perde uma aula por feriado, a T2 seguinte
 * continua a ser a lição que ficou por dar, e não a da semana em que acontece.
 * Uma aula já ligada nunca muda de lição — nem ao reabrir a semana, nem ao
 * reexecutar a renumeração.
 *
 * A ORDEM DAS UNIDADES é a da sua primeira aula (`starts_at`, depois `id`).
 *
 * O HISTÓRICO LECIONADO É INTOCÁVEL (§14) no funcionamento normal: se uma
 * operação exigisse mudar o número de uma aula lecionada, é recusada antes de
 * escrever. A única exceção é `rebuild()`, a correção histórica controlada que
 * só `lapis:renumber-lessons --apply` chama.
 */
final class LessonNumbering
{
    /**
     * Liga e renumera a turma pela ordem cronológica real.
     *
     * SEMPRE DENTRO DE UMA TRANSAÇÃO DE QUEM CHAMA.
     *
     * @return array<int, int> lesson_id => número novo, só das aulas que mudaram
     *
     * @throws ValidationException quando fechar a sequência exigiria mudar o
     *                             número de uma aula já lecionada.
     */
    public function resequence(int $classId): array
    {
        $lessons = $this->sequence($classId);
        $links = $this->link($lessons, $this->slotKeys($classId));
        $preview = $this->changesFor($lessons);

        // A recusa acontece antes de qualquer escrita, ligações incluídas.
        $this->guardTaughtHistory($preview);

        $changes = array_map(fn (array $change): int => $change['to'], $preview);
        $this->writeLinks($links);
        $this->writeNumbers($changes);

        return $changes;
    }

    /**
     * A mesma recusa de `resequence()`, calculada sobre um estado HIPOTÉTICO da
     * turma e sem escrever nada. É o que a pré-visualização de «Inserir aula»
     * usa: as aulas deslocadas chegam como cópias em memória com a data nova, e
     * a aula a inserir como um modelo nunca gravado.
     *
     * @param  iterable<Lesson>  $lessons  todas as aulas da turma, no estado hipotético
     * @return array<int, int> lesson_id => número novo, só das aulas gravadas que mudariam
     *
     * @throws ValidationException
     */
    public function previewHypotheticalSequence(int $classId, iterable $lessons): array
    {
        $sorted = new Collection(collect($lessons)
            ->sort(function (Lesson $left, Lesson $right): int {
                $byTime = $left->starts_at->getTimestamp() <=> $right->starts_at->getTimestamp();

                // Uma aula ainda sem id é a mais recente a nascer.
                return $byTime !== 0 ? $byTime : ($left->getKey() ?? PHP_INT_MAX) <=> ($right->getKey() ?? PHP_INT_MAX);
            })
            ->values()
            ->all());

        $this->link($sorted, $this->slotKeys($classId));
        $changes = $this->changesFor($sorted);
        $this->guardTaughtHistory($changes);

        return array_map(fn (array $change): int => $change['to'], array_filter(
            $changes,
            fn (array $change): bool => $change['lesson']->exists,
        ));
    }

    /**
     * A correção histórica: liga e renumera TUDO, lecionadas incluídas. Nunca é
     * chamada por um caminho normal da aplicação.
     *
     * `$slotKeys` permite simular chaves de tempos ainda não gravadas (a
     * simulação do comando). Idempotente: as ligações gravadas não mudam e os
     * números são função delas e da cronologia.
     *
     * @param  array<int, string>  $slotKeyOverrides  slot_id => split_lesson_key
     * @return array{numbers: array<int, array{lesson: Lesson, from: int|null, to: int}>, links: array<int, string>}
     */
    public function rebuild(int $classId, bool $write = true, array $slotKeyOverrides = []): array
    {
        $lessons = $this->sequence($classId);
        $slotKeys = $this->slotKeys($classId);

        foreach ($slotKeyOverrides as $slotId => $key) {
            if (isset($slotKeys[$slotId])) {
                $slotKeys[$slotId]['key'] = $key;
            }
        }

        $links = $this->link($lessons, $slotKeys);
        $numbers = $this->changesFor($lessons);

        if ($write) {
            $this->writeLinks($links);
            $this->writeNumbers(array_map(fn (array $change): int => $change['to'], $numbers));
        }

        return ['numbers' => $numbers, 'links' => $links];
    }

    /**
     * A numeração no caminho automático: a materialização, que corre sozinha
     * sempre que alguém abre a semana, e por isso não pode falhar.
     *
     * Tenta primeiro a renumeração normal. Se ela colidir com o histórico, cai
     * no plano B: as aulas novas são ligadas na mesma, e cada aula ainda sem
     * número recebe o número da sua lição se outra aula dela já o tiver, ou o
     * número seguinte ao maior já atribuído. Nenhum número já escrito muda, e o
     * desvio fica registado.
     *
     * @return array<int, int>
     */
    public function numberMaterializedLessons(int $classId): array
    {
        try {
            return $this->resequence($classId);
        } catch (ValidationException) {
            // Plano B, descrito acima.
        }

        $lessons = $this->sequence($classId);
        $this->writeLinks($this->link($lessons, $this->slotKeys($classId)));

        $next = (int) $lessons->max('lesson_number');
        $changes = [];

        foreach ($this->units($lessons) as $unit) {
            $unnumbered = array_filter($unit, fn (Lesson $lesson): bool => $lesson->lesson_number === null);

            if ($unnumbered === []) {
                continue;
            }

            $numbered = array_values(array_filter($unit, fn (Lesson $lesson): bool => $lesson->lesson_number !== null));
            $number = $numbered === [] ? ++$next : (int) $numbered[0]->lesson_number;

            foreach ($unnumbered as $lesson) {
                $changes[(int) $lesson->getKey()] = $number;
            }
        }

        $this->writeNumbers($changes);

        // NÃO SILENCIOSO: estas aulas ficam com números fora de ordem face às
        // lecionadas. Só ids e números, nunca dados de alunos.
        if ($changes !== []) {
            Log::warning('lessons.numbering.out_of_order_fallback', [
                'class_id' => $classId,
                'assigned' => $changes,
            ]);
        }

        return $changes;
    }

    /**
     * O que a renumeração FARIA, sem escrever nada (§20).
     *
     * @return array<int, array{lesson: Lesson, from: int|null, to: int}>
     */
    public function previewResequence(int $classId): array
    {
        $lessons = $this->sequence($classId);
        $this->link($lessons, $this->slotKeys($classId));

        return $this->changesFor($lessons);
    }

    /**
     * Liga, EM MEMÓRIA, as aulas de grupo ainda sem lição. Devolve as ligações
     * novas das aulas gravadas, para quem as quiser escrever.
     *
     * @param  Collection<int, Lesson>  $lessons  ordenadas por starts_at, id
     * @param  array<int, array{key: string|null, from: int|null}>  $slotKeys
     * @return array<int, string> lesson_id => lesson_unit_key
     */
    private function link(Collection $lessons, array $slotKeys): array
    {
        // Por vínculo, as lições pela ordem em que apareceram; por lição, os
        // grupos que já têm aula nela e o instante da sua primeira aula.
        /** @var array<string, list<string>> $unitsBySplitKey */
        $unitsBySplitKey = [];
        /** @var array<string, array<int, true>> $groupsByUnit */
        $groupsByUnit = [];
        /** @var array<string, int> $firstByUnit */
        $firstByUnit = [];
        $links = [];

        foreach ($lessons as $lesson) {
            if ($lesson->class_group_id === null) {
                continue;
            }

            $slot = $lesson->recurring_lesson_slot_id === null ? null : ($slotKeys[$lesson->recurring_lesson_slot_id] ?? null);
            $splitKey = $slot['key'] ?? null;
            $groupId = $lesson->class_group_id;

            if ($lesson->lesson_unit_key !== null) {
                $unitKey = $lesson->lesson_unit_key;

                if (isset($groupsByUnit[$unitKey])) {
                    $groupsByUnit[$unitKey][$groupId] = true;
                } elseif ($splitKey !== null) {
                    $unitsBySplitKey[$splitKey][] = $unitKey;
                    $groupsByUnit[$unitKey] = [$groupId => true];
                    $firstByUnit[$unitKey] = $lesson->starts_at->getTimestamp();
                }

                continue;
            }

            if ($slot === null || $splitKey === null) {
                continue;
            }

            $unitKey = null;

            foreach ($unitsBySplitKey[$splitKey] ?? [] as $candidate) {
                if (isset($groupsByUnit[$candidate][$groupId])) {
                    continue;
                }

                // Uma lição anterior ao início do tempo desta aula não é dela:
                // um T2 criado em novembro não fica com a lição de setembro.
                if ($slot['from'] !== null && $firstByUnit[$candidate] < $slot['from']) {
                    continue;
                }

                $unitKey = $candidate;

                break;
            }

            if ($unitKey === null) {
                $unitKey = (string) Str::ulid();
                $unitsBySplitKey[$splitKey][] = $unitKey;
                $groupsByUnit[$unitKey] = [];
                $firstByUnit[$unitKey] = $lesson->starts_at->getTimestamp();
            }

            $groupsByUnit[$unitKey][$groupId] = true;
            $lesson->lesson_unit_key = $unitKey;

            if ($lesson->exists) {
                $links[(int) $lesson->getKey()] = $unitKey;
            }
        }

        return $links;
    }

    /**
     * @return array<int, array{key: string|null, from: int|null}>
     */
    private function slotKeys(int $classId): array
    {
        $keys = [];

        foreach (RecurringLessonSlot::query()->where('class_id', $classId)->get() as $slot) {
            $from = $slot->starts_on ?? $slot->created_at;

            $keys[(int) $slot->getKey()] = [
                'key' => $slot->split_lesson_key,
                'from' => $from?->copy()->setTimezone('Europe/Lisbon')->startOfDay()->getTimestamp(),
            ];
        }

        return $keys;
    }

    /**
     * @param  array<int, array{lesson: Lesson, from: int|null, to: int}>  $changes
     *
     * @throws ValidationException
     */
    private function guardTaughtHistory(array $changes): void
    {
        foreach ($changes as $change) {
            $lesson = $change['lesson'];

            // Uma aula lecionada ainda sem número está a receber o primeiro,
            // não a mudar de número.
            if ($lesson->status === LessonStatus::Taught && $lesson->lesson_number !== null) {
                throw ValidationException::withMessages([
                    'lesson_number' => __(
                        'Esta operação mudaria o número da aula de :date, que já foi lecionada (Lição :from → Lição :to). O histórico não é renumerado.',
                        [
                            'date' => $lesson->starts_at->setTimezone('Europe/Lisbon')->format('d/m/Y'),
                            'from' => (string) $lesson->lesson_number,
                            'to' => (string) $change['to'],
                        ],
                    ),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, Lesson>  $lessons  ordenadas por starts_at, id
     * @return array<int, array{lesson: Lesson, from: int|null, to: int}>
     */
    private function changesFor(Collection $lessons): array
    {
        $preview = [];
        $number = 0;

        foreach ($this->units($lessons) as $unit) {
            $number++;

            foreach ($unit as $lesson) {
                if ($lesson->exists && $lesson->lesson_number !== $number) {
                    $preview[(int) $lesson->getKey()] = [
                        'lesson' => $lesson,
                        'from' => $lesson->lesson_number,
                        'to' => $number,
                    ];
                }
            }
        }

        return $preview;
    }

    /**
     * As unidades de lição, pela ordem da sua primeira aula.
     *
     * @param  Collection<int, Lesson>  $lessons  ordenadas por starts_at, id
     * @return list<list<Lesson>>
     */
    private function units(Collection $lessons): array
    {
        /** @var array<string, list<Lesson>> $units */
        $units = [];

        foreach ($lessons as $lesson) {
            $key = $lesson->class_group_id !== null && $lesson->lesson_unit_key !== null
                ? 'unit:'.$lesson->lesson_unit_key
                : 'lesson:'.($lesson->getKey() ?? 'new-'.spl_object_id($lesson));

            // Um array PHP preserva a ordem de inserção: a unidade ocupa o seu
            // lugar quando aparece a PRIMEIRA aula dela.
            $units[$key][] = $lesson;
        }

        return array_values($units);
    }

    /**
     * @param  array<int, string>  $links
     */
    private function writeLinks(array $links): void
    {
        foreach ($links as $lessonId => $unitKey) {
            DB::table('lessons')->where('id', $lessonId)->whereNull('lesson_unit_key')->update(['lesson_unit_key' => $unitKey]);
        }
    }

    /**
     * @param  array<int, int>  $changes
     */
    private function writeNumbers(array $changes): void
    {
        foreach ($changes as $lessonId => $assigned) {
            DB::table('lessons')->where('id', $lessonId)->update(['lesson_number' => $assigned]);
        }
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function sequence(int $classId): Collection
    {
        return Lesson::query()
            ->where('class_id', $classId)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }
}
