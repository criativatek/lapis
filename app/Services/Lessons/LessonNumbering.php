<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * mesma turma, e a aula de T1 e a de T2 que correspondem ao mesmo momento
 * curricular partilham UM número. Até à 0.145.1 o âmbito era (turma, grupo), e
 * uma turma com Lições 1–3 via T1 e T2 recomeçarem cada um em «Lição 1».
 * Uma turma de apoio é uma SchoolClass própria e tem, por isso, sequência própria.
 *
 * A UNIDADE DE LIÇÃO. Uma aula da turma inteira é, sozinha, uma unidade. As
 * aulas de grupos agrupam-se assim: dentro da mesma semana letiva (segunda a
 * domingo, Europe/Lisbon), a k-ésima aula de T1 e a k-ésima aula de T2 são a
 * mesma unidade. O horário é semanal (RecurringLessonSlot::day_of_week) e não
 * existe nenhum vínculo gravado entre um tempo de T1 e um de T2 — a posição na
 * semana é a única origem comum que o modelo tem. Funciona com T1 e T2 à mesma
 * hora e com T1 à segunda e T2 à quarta; um feriado que só apanhe um dos grupos
 * desalinha apenas essa semana, nunca o resto do ano. Nada em código conhece
 * «T1»: todos os grupos de uma turma são desdobramentos dela (ClassGroup não tem
 * outro tipo).
 *
 * A ORDEM DAS UNIDADES é a da sua primeira aula (`starts_at`, depois `id`), e
 * nunca `created_at`. O `id` é só desempate estável, para que duas passagens
 * seguidas nunca produzam numerações diferentes.
 *
 * O HISTÓRICO LECIONADO É INTOCÁVEL (§14) no funcionamento normal: se uma
 * operação exigisse mudar o número de uma aula lecionada, é recusada antes de
 * escrever. A única exceção é `rebuild()`, a correção histórica controlada que
 * só `lapis:renumber-lessons --apply` chama.
 */
final class LessonNumbering
{
    /**
     * Renumera a turma pela ordem cronológica real.
     *
     * Devolve o mapa `lesson_id => numero_novo` apenas das aulas que mudaram,
     * para que quem chama possa mostrar o impacto antes de confirmar (§20).
     *
     * SEMPRE DENTRO DE UMA TRANSAÇÃO DE QUEM CHAMA: a renumeração é a última
     * metade de operações como «inserir e deslocar».
     *
     * @return array<int, int>
     *
     * @throws ValidationException quando fechar a sequência exigiria mudar o
     *                             número de uma aula já lecionada.
     */
    public function resequence(int $classId): array
    {
        $preview = $this->previewResequence($classId);
        $this->guardTaughtHistory($preview);

        $changes = array_map(fn (array $change): int => $change['to'], $preview);
        $this->write($changes);

        return $changes;
    }

    /**
     * A mesma recusa de `resequence()`, calculada sobre um estado HIPOTÉTICO da
     * turma e sem escrever nada — nem sequer dentro de uma transação desfeita.
     *
     * É o que a pré-visualização de «Inserir aula» usa: as aulas deslocadas
     * chegam aqui como cópias em memória com a data nova, e a aula a inserir
     * como um modelo nunca gravado. Nenhum evento, id ou registo nasce disto.
     *
     * @param  iterable<Lesson>  $lessons  todas as aulas da turma, no estado hipotético
     * @return array<int, int> lesson_id => número novo, só das aulas gravadas que mudariam
     *
     * @throws ValidationException
     */
    public function previewHypotheticalSequence(iterable $lessons): array
    {
        $sorted = collect($lessons)
            ->sort(function (Lesson $left, Lesson $right): int {
                $byTime = $left->starts_at->getTimestamp() <=> $right->starts_at->getTimestamp();

                // Uma aula ainda sem id é a mais recente a nascer: fica depois
                // das do mesmo instante, como ficaria depois de gravada.
                return $byTime !== 0 ? $byTime : ($left->getKey() ?? PHP_INT_MAX) <=> ($right->getKey() ?? PHP_INT_MAX);
            })
            ->values();

        $changes = $this->changesFor(new Collection($sorted->all()));
        $this->guardTaughtHistory($changes);

        return array_map(fn (array $change): int => $change['to'], array_filter(
            $changes,
            fn (array $change): bool => $change['lesson']->exists,
        ));
    }

    /**
     * A correção histórica: renumera TUDO, lecionadas incluídas, pela regra das
     * unidades. Nunca é chamada por um caminho normal da aplicação.
     *
     * Idempotente por construção: os números são uma função pura da cronologia
     * e dos grupos, e uma segunda passagem não encontra nada para mudar. Só
     * `lesson_number` é escrito — datas, estado, sumários, faltas e grupos não.
     *
     * @return array<int, array{lesson: Lesson, from: int|null, to: int}>
     */
    public function rebuild(int $classId, bool $write = true): array
    {
        $changes = $this->previewResequence($classId);

        if ($write) {
            $this->write(array_map(fn (array $change): int => $change['to'], $changes));
        }

        return $changes;
    }

    /**
     * A numeração no caminho automático: a materialização, que corre sozinha
     * sempre que alguém abre a semana, e por isso não pode falhar.
     *
     * Tenta primeiro a renumeração normal. Se ela colidir com o histórico, cai
     * no plano B: cada aula ainda sem número recebe o número da sua unidade se
     * outra aula dessa unidade já o tiver (o par T1/T2 mantém-se), ou o número
     * seguinte ao maior já atribuído. Nenhum número já escrito muda.
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

        $this->write($changes);

        // NÃO SILENCIOSO. Chegar aqui quer dizer que a ordem cronológica não
        // pôde ser respeitada sem renumerar histórico lecionado: estas aulas
        // ficam com números fora de ordem face às lecionadas. Não há corrupção
        // (números únicos por unidade, nada lecionado mudou), mas fica
        // registado — só ids e números, nunca dados de alunos — para que se
        // possa corrigir com `lapis:renumber-lessons` se o professor quiser.
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
        return $this->changesFor($this->sequence($classId));
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
                if ($lesson->lesson_number !== $number) {
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
        /** @var array<string, int> $seen "semana:grupo" => aulas já vistas */
        $seen = [];

        foreach ($lessons as $lesson) {
            if ($lesson->class_group_id === null) {
                $units['whole:'.($lesson->getKey() ?? 'new-'.spl_object_id($lesson))] = [$lesson];

                continue;
            }

            $week = CarbonImmutable::instance($lesson->starts_at)
                ->setTimezone('Europe/Lisbon')
                ->startOfWeek()
                ->format('Y-m-d');
            $groupKey = $week.':'.$lesson->class_group_id;
            $seen[$groupKey] = ($seen[$groupKey] ?? 0) + 1;

            // Um array PHP preserva a ordem de inserção: a unidade ocupa o seu
            // lugar quando aparece a PRIMEIRA aula dela, que é a mais antiga.
            $units['split:'.$week.':'.$seen[$groupKey]][] = $lesson;
        }

        return array_values($units);
    }

    /**
     * @param  array<int, int>  $changes
     */
    private function write(array $changes): void
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
