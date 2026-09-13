<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\LessonConflicts;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\ScheduleOccurrences;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Falta-me uma aula antes desta» — inserir uma aula no meio de uma sequência
 * já preparada e empurrar as seguintes para a frente.
 *
 * NÃO É «+1 DIA». A aula de segunda-feira não passa para terça, porque na terça
 * a turma não tem aula nenhuma: passa para a PRÓXIMA OCORRÊNCIA VÁLIDA do
 * horário real (ScheduleOccurrences) — a quarta-feira, se for quarta o tempo
 * seguinte —, saltando feriados e interrupções letivas pela mesma regra que a
 * materialização já usa. A ordem pedagógica é o que se preserva; as datas são
 * consequência dela.
 *
 * O QUE NUNCA É MOVIDO: uma aula já lecionada. E se o deslocamento tivesse de
 * atravessar uma, a operação INTEIRA é recusada antes de escrever o que quer
 * que seja (§18) — nunca metade das aulas movidas e a outra metade no sítio.
 * É também por isso que tudo isto vive numa transação só.
 *
 * A SEQUÊNCIA É (turma, grupo). Inserir uma aula em T1 desloca aulas de T1 e
 * ocupa tempos de T1. A sequência de T2 e a da turma inteira não são olhadas.
 */
class InsertLessonIntoSequence
{
    /** Quantas ocorrências para a frente vale a pena procurar de uma vez. */
    private const HORIZON_DAYS = 400;

    public function __construct(
        protected AuditLog $audit,
        protected LessonNumbering $numbering,
        protected ScheduleOccurrences $occurrences,
        protected LessonConflicts $conflicts,
    ) {}

    /**
     * O que a inserção FARIA, sem escrever nada — o «isto vai deslocar 4 aulas
     * preparadas» que o professor vê antes de confirmar (§20).
     *
     * Calculado pelo MESMO caminho que a execução usa (`plan()`), e não por uma
     * segunda estimativa parecida: uma pré-visualização que não seja exatamente
     * a operação é pior do que nenhuma, porque promete o que não vai acontecer.
     *
     * @return array{
     *     moves: list<array{ulid: string, context_label: string, from: string, to: string,
     *                       lesson_number: int|null}>,
     *     inserted_at: string,
     *     shifted_count: int,
     * }
     */
    public function preview(
        SchoolClass $class,
        ?int $classGroupId,
        CarbonImmutable $insertAt,
    ): array {
        $plan = $this->plan($class, $classGroupId, $insertAt);

        return [
            'inserted_at' => $plan['insertion']['starts_at']->toIso8601String(),
            'shifted_count' => count($plan['moves']),
            'moves' => array_map(
                fn (array $move): array => [
                    'ulid' => $move['lesson']->ulid,
                    'context_label' => $move['lesson']->contextLabel(),
                    'from' => $move['lesson']->starts_at->setTimezone('Europe/Lisbon')->toIso8601String(),
                    'to' => $move['occurrence']['starts_at']->toIso8601String(),
                    'lesson_number' => $move['lesson']->lesson_number,
                ],
                $plan['moves'],
            ),
        ];
    }

    /**
     * @return array{lesson: Lesson, moved: int, renumbered: array<int, int>}
     */
    public function execute(
        SchoolClass $class,
        ?int $classGroupId,
        CarbonImmutable $insertAt,
        User $actor,
    ): array {
        return DB::transaction(function () use ($actor, $class, $classGroupId, $insertAt): array {
            // O mesmo bloqueio de turma que a materialização usa: duas
            // inserções simultâneas na mesma sequência calculariam os dois o
            // mesmo plano a partir do mesmo estado e escreveriam por cima uma
            // da outra.
            /** @var SchoolClass $lockedClass */
            $lockedClass = SchoolClass::query()
                ->whereKey($class->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $plan = $this->plan($lockedClass, $classGroupId, $insertAt);

            // DE TRÁS PARA A FRENTE. A aula que vai para a última ocorrência
            // move-se primeiro; só depois a que vai ocupar o lugar que ela
            // deixou. Pela ordem inversa, cada UPDATE tentaria pousar numa
            // ocorrência ainda ocupada pela aula seguinte, e a chave
            // `lessons_class_slot_start_unique` recusaria a meio da operação.
            foreach (array_reverse($plan['moves']) as $move) {
                /** @var Lesson $lesson */
                $lesson = $move['lesson'];
                // `Carbon::instance()` e não o CarbonImmutable directo: os casts
                // de `Lesson` declaram `Illuminate\Support\Carbon`, e é essa a
                // classe que o modelo devolve a quem ler o atributo a seguir.
                $lesson->starts_at = Carbon::instance($move['occurrence']['starts_at']);
                $lesson->ends_at = Carbon::instance($move['occurrence']['ends_at']);
                $lesson->recurring_lesson_slot_id = $move['occurrence']['slot_id'];
                $lesson->save();
            }

            $inserted = Lesson::query()->create([
                'class_id' => $lockedClass->getKey(),
                'class_group_id' => $classGroupId,
                'recurring_lesson_slot_id' => $plan['insertion']['slot_id'],
                'starts_at' => $plan['insertion']['starts_at'],
                'ends_at' => $plan['insertion']['ends_at'],
                'status' => LessonStatus::Preparation,
                'created_by' => $actor->getKey(),
            ]);

            $renumbered = $this->numbering->resequence($lockedClass->getKey(), $classGroupId);

            $this->audit->record(
                'lesson.inserted',
                $inserted,
                $actor,
                'Aula inserida na sequência.',
                [
                    'starts_at' => $plan['insertion']['starts_at']->toIso8601String(),
                    'shifted_lessons' => count($plan['moves']),
                    'renumbered_lessons' => count($renumbered),
                ],
            );

            return [
                'lesson' => $inserted,
                'moved' => count($plan['moves']),
                'renumbered' => $renumbered,
            ];
        });
    }

    /**
     * O plano: que ocorrência recebe a aula nova, e para que ocorrência vai
     * cada uma das que lá estavam.
     *
     * Todas as recusas acontecem AQUI, antes de qualquer escrita — é isto que
     * torna a operação atómica na prática e não só por ter uma transação à
     * volta: quando `execute()` começa a gravar, já se sabe que o plano inteiro
     * é executável.
     *
     * @return array{
     *     insertion: array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, slot_id: int},
     *     moves: list<array{lesson: Lesson, occurrence: array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, slot_id: int}}>,
     * }
     */
    private function plan(SchoolClass $class, ?int $classGroupId, CarbonImmutable $insertAt): array
    {
        $insertAt = $insertAt->setTimezone('Europe/Lisbon');

        $occurrences = $this->occurrences->between(
            $class,
            $insertAt->startOfDay(),
            $insertAt->startOfDay()->addDays(self::HORIZON_DAYS),
            $classGroupId,
        );

        // A ocorrência escolhida é a primeira do horário que não é anterior ao
        // instante pedido. O professor aponta um dia; o sistema põe a aula num
        // tempo em que a turma existe, e não num buraco do horário.
        $slotIndex = null;

        foreach ($occurrences as $index => $occurrence) {
            if (! $occurrence['starts_at']->lessThan($insertAt)) {
                $slotIndex = $index;

                break;
            }
        }

        if ($slotIndex === null) {
            throw ValidationException::withMessages([
                'insert_at' => __(
                    'O horário desta turma não tem nenhuma aula a partir desta data até ao fim do ano letivo.',
                ),
            ]);
        }

        $insertion = $occurrences[$slotIndex];
        $toShift = $this->lessonsFrom($class, $classGroupId, $insertion['starts_at']);

        $taught = $toShift->first(fn (Lesson $lesson): bool => $lesson->status === LessonStatus::Taught);

        if ($taught !== null) {
            throw ValidationException::withMessages([
                'insert_at' => __(
                    'Não é possível deslocar esta sequência porque contém uma aula já lecionada em :date.',
                    ['date' => $taught->starts_at->setTimezone('Europe/Lisbon')->format('d/m')],
                ),
            ]);
        }

        $available = array_slice($occurrences, $slotIndex + 1);

        if (count($available) < $toShift->count()) {
            throw ValidationException::withMessages([
                'insert_at' => __(
                    'O horário desta turma não tem ocorrências suficientes até ao fim do ano letivo para deslocar as :count aulas seguintes.',
                    ['count' => (string) $toShift->count()],
                ),
            ]);
        }

        $moves = [];

        foreach ($toShift->values() as $position => $lesson) {
            $moves[] = ['lesson' => $lesson, 'occurrence' => $available[$position]];
        }

        // Nada disto pode aterrar em cima de uma aula de outra sequência que
        // partilhe o mesmo público — a turma inteira e um seu grupo, por
        // exemplo. As aulas que estão a ser movidas são ignoradas na verificação
        // porque estão, por definição, a libertar o sítio onde estavam.
        $movingIds = $toShift->modelKeys();

        foreach ([['occurrence' => $insertion], ...$moves] as $move) {
            $conflict = $this->conflicts->conflictingLesson(
                (int) $class->getKey(),
                $classGroupId,
                $move['occurrence']['starts_at'],
                $move['occurrence']['ends_at'],
            );

            if ($conflict !== null && ! in_array($conflict->getKey(), $movingIds, true)) {
                throw ValidationException::withMessages([
                    'insert_at' => $this->conflicts->conflictMessage($conflict),
                ]);
            }
        }

        return ['insertion' => $insertion, 'moves' => $moves];
    }

    /**
     * As aulas desta sequência a partir de um instante, inclusive — as que a
     * inserção empurra para a frente.
     *
     * @return Collection<int, Lesson>
     */
    private function lessonsFrom(SchoolClass $class, ?int $classGroupId, CarbonImmutable $from): Collection
    {
        return Lesson::query()
            ->where('class_id', $class->getKey())
            ->where(fn ($query) => $classGroupId === null
                ? $query->whereNull('class_group_id')
                : $query->where('class_group_id', $classGroupId))
            ->where('starts_at', '>=', $from)
            ->with(['schoolClass', 'classGroup'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }
}
