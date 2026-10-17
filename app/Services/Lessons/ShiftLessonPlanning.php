<?php

namespace App\Services\Lessons;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * «O que estava planeado para esta aula passa para a seguinte» (0.146.0).
 *
 * Quando uma ocorrência fecha sem desenvolver a disciplina (professor ausente,
 * turma noutra atividade letiva), o planeamento que ela levava — o sumário
 * previsto, os recursos, o TPC e as notas — não se perde nem se duplica: desce
 * UMA posição na sequência daquele público (turma inteira, ou aquele grupo).
 *
 *   A (fechada) → B → C → …
 *
 * O planeamento de A vai para B, o de B para C, e assim sucessivamente até à
 * primeira ocorrência seguinte que não tinha planeamento nenhum, onde a cadeia
 * pára. Cada planeamento existe no fim exatamente uma vez.
 *
 * SÓ OCORRÊNCIAS ABERTAS recebem planeamento. Quem chama garante que não há
 * nenhuma ocorrência fechada depois de A no mesmo público (RecordLessonOutcome
 * recusa antes), porque deslocar através de histórico reescreveria o que já
 * aconteceu.
 *
 * SEM PRÓXIMA AULA GRAVADA, pede-se ao horário a próxima ocorrência válida até
 * ao fim do ano letivo e materializa-se pela via única de sempre
 * (MaterializeLessonsForRange). Só quando o horário também não tem nenhuma o
 * último planeamento fica como PENDÊNCIA em `lesson_plans.planned_summary` da
 * última aula da cadeia — a tabela que existe precisamente para «o que estava
 * planeado para esta aula», única por aula, e que até aqui só o backup usava.
 *
 * SEMPRE DENTRO DA TRANSAÇÃO DE QUEM CHAMA, com a turma bloqueada.
 */
final class ShiftLessonPlanning
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * O rótulo com que `keepAsPending` escreve as notas privadas no plano
     * pendente. Partilhado com `LessonPreparationContext`, que corta o plano a
     * partir daí para nunca mostrar notas privadas a quem prepara a aula
     * seguinte: se o rótulo mudar aqui, muda lá.
     */
    public const PRIVATE_NOTES_LABEL = 'Notas privadas';

    private const FIELDS = ['content', 'resources', 'homework', 'private_notes'];

    /**
     * De onde veio o conteúdo (ApplyLessonSequence). Desce com ele: o texto
     * chega intacto à aula seguinte, por isso a impressão digital continua a
     * bater, e uma reaplicação da sequência reconhece o elemento na aula nova
     * em vez de o colocar uma segunda vez.
     */
    private const PROVENANCE = ['lesson_sequence_id', 'lesson_sequence_item_id', 'sequence_content_hash'];

    public function __construct(
        private readonly ScheduleOccurrences $occurrences,
        private readonly MaterializeLessonsForRange $materialize,
    ) {}

    /**
     * `moves` e `pending_on`/`pending_values` são o TRAÇO do que foi escrito
     * onde (0.171.0) — para a pré-visualização de «Registar e adiar a
     * preparação» dizer o destino de cada planeamento. Não mudam o que se
     * escreve: as chaves `shifted`, `materialized` e `pending` são as de sempre.
     *
     * @return array{
     *     shifted: int,
     *     materialized: bool,
     *     pending: bool,
     *     moves: list<array{from: Lesson, to: Lesson, materialized: bool, values: array<string, string|int|null>}>,
     *     pending_on: Lesson|null,
     *     pending_values: array<string, string|int|null>|null,
     * }
     */
    public function from(Lesson $closed, User $actor): array
    {
        $carry = $this->takeFrom($closed);

        if ($carry === null) {
            return ['shifted' => 0, 'materialized' => false, 'pending' => false, 'moves' => [], 'pending_on' => null, 'pending_values' => null];
        }

        $shifted = 0;
        $last = $closed;
        $moves = [];

        foreach ($this->openLessonsAfter($closed) as $lesson) {
            $displaced = $this->takeFrom($lesson);
            $this->writeInto($lesson, $carry);
            $moves[] = ['from' => $last, 'to' => $lesson, 'materialized' => false, 'values' => $carry];
            $shifted++;
            $last = $lesson;

            if ($displaced === null) {
                return ['shifted' => $shifted, 'materialized' => false, 'pending' => false, 'moves' => $moves, 'pending_on' => null, 'pending_values' => null];
            }

            $carry = $displaced;
        }

        $next = $this->materializeNextOccurrence($closed, $last, $actor);

        if ($next !== null) {
            $this->writeInto($next, $carry);
            $moves[] = ['from' => $last, 'to' => $next, 'materialized' => true, 'values' => $carry];

            return ['shifted' => $shifted + 1, 'materialized' => true, 'pending' => false, 'moves' => $moves, 'pending_on' => null, 'pending_values' => null];
        }

        $this->keepAsPending($last, $carry, $actor);

        return ['shifted' => $shifted, 'materialized' => false, 'pending' => true, 'moves' => $moves, 'pending_on' => $last, 'pending_values' => $carry];
    }

    /**
     * Para onde foi o planeamento, numa frase para o toast — a frase que o
     * professor lê depois de registar o resultado (0.171.0): o destino REAL, e
     * não «passou para a aula seguinte» às cegas.
     *
     * @param  array<string, mixed>  $trace  o retorno de `from()`
     */
    public static function destinationSentence(array $trace, Lesson $closed): string
    {
        /** @var list<array{to: Lesson}> $moves */
        $moves = $trace['moves'] ?? [];

        if ($moves !== []) {
            return __('A preparação passou para a aula de :date.', ['date' => self::dayLabel($moves[0]['to'])]);
        }

        /** @var Lesson|null $pendingOn */
        $pendingOn = $trace['pending_on'] ?? null;

        if ($pendingOn !== null) {
            return $pendingOn->getKey() === $closed->getKey()
                ? __('A preparação ficou como planeamento pendente nesta aula.')
                : __('A preparação ficou como planeamento pendente na aula de :date.', ['date' => self::dayLabel($pendingOn)]);
        }

        return __('Esta aula não tinha preparação.');
    }

    /** «ter., 27/10» — o dia local da aula, com o dia da semana abreviado. */
    private static function dayLabel(Lesson $lesson): string
    {
        $local = $lesson->starts_at->clone()->setTimezone(self::TIMEZONE);
        $weekdays = ['dom.', 'seg.', 'ter.', 'qua.', 'qui.', 'sex.', 'sáb.'];

        return $weekdays[$local->dayOfWeek].', '.$local->format('d/m');
    }

    /**
     * @return Collection<int, Lesson>
     */
    public function openLessonsAfter(Lesson $lesson): Collection
    {
        return $this->sameAudience($lesson)
            ->where(fn ($query) => $query
                ->where('starts_at', '>', $lesson->starts_at)
                ->orWhere(fn ($same) => $same->where('starts_at', $lesson->starts_at)->where('id', '>', $lesson->getKey())))
            ->whereNull('outcome')
            ->where('status', '!=', LessonStatus::Taught)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * A primeira ocorrência FECHADA depois desta, no mesmo público — a que
     * impede o deslocamento.
     */
    public function closedLessonAfter(Lesson $lesson): ?Lesson
    {
        return $this->sameAudience($lesson)
            ->where(fn ($query) => $query
                ->where('starts_at', '>', $lesson->starts_at)
                ->orWhere(fn ($same) => $same->where('starts_at', $lesson->starts_at)->where('id', '>', $lesson->getKey())))
            ->where(fn ($query) => $query->whereNotNull('outcome')->orWhere('status', LessonStatus::Taught))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return Builder<Lesson>
     */
    private function sameAudience(Lesson $lesson)
    {
        return Lesson::query()
            ->where('class_id', $lesson->class_id)
            ->where(fn ($query) => $lesson->class_group_id === null
                ? $query->whereNull('class_group_id')
                : $query->where('class_group_id', $lesson->class_group_id));
    }

    /**
     * Retira o planeamento da aula e devolve-o. NULL quando não havia nada.
     *
     * @return array<string, string|int|null>|null
     */
    private function takeFrom(Lesson $lesson): ?array
    {
        /** @var LessonSummary|null $summary */
        $summary = $lesson->summary()->first();

        if ($summary === null) {
            return null;
        }

        $values = [];

        foreach (self::FIELDS as $field) {
            $value = $summary->getAttribute($field);
            $values[$field] = is_string($value) && trim($value) !== '' ? $value : null;
        }

        if (array_filter($values) === []) {
            return null;
        }

        foreach (self::PROVENANCE as $field) {
            $values[$field] = $summary->getAttribute($field);
        }

        // A linha sai inteira: o sumário de uma ocorrência que não aconteceu
        // não fica a dizer que aconteceu, e a seguinte recebe-o sem cópia.
        $summary->delete();

        return $values;
    }

    /**
     * @param  array<string, string|int|null>  $values
     */
    private function writeInto(Lesson $lesson, array $values): void
    {
        // A proveniência é escrita SEMPRE, também quando é nula: uma linha
        // pré-existente na aula de destino não pode ficar com a origem antiga
        // a descrever um conteúdo que já não é o dela.
        $provenance = [];

        foreach (self::PROVENANCE as $field) {
            $provenance[$field] = $values[$field] ?? null;
        }

        LessonSummary::query()->updateOrCreate(
            ['lesson_id' => $lesson->getKey()],
            ['content' => $values['content'] ?? ''] + array_intersect_key($values, array_flip(['resources', 'homework', 'private_notes'])) + $provenance,
        );

        if ($lesson->status === LessonStatus::Preparation) {
            $lesson->status = LessonStatus::Prepared;
            $lesson->save();
        }
    }

    private function materializeNextOccurrence(Lesson $closed, Lesson $last, User $actor): ?Lesson
    {
        /** @var SchoolClass $class */
        $class = SchoolClass::query()->findOrFail($closed->class_id);
        $academicYear = $class->academicYear()->firstOrFail();
        $yearEndsOn = CarbonImmutable::parse($academicYear->ends_on, self::TIMEZONE)->startOfDay();
        $after = CarbonImmutable::instance($last->starts_at)->setTimezone(self::TIMEZONE);

        if ($after->startOfDay()->greaterThan($yearEndsOn)) {
            return null;
        }

        $candidates = $this->occurrences->between($class, $after->startOfDay(), $yearEndsOn, $closed->class_group_id);

        foreach ($candidates as $occurrence) {
            if (! $occurrence['starts_at']->greaterThan($after)) {
                continue;
            }

            $cancelled = CancelledLessonOccurrence::query()
                ->where('class_id', $class->getKey())
                ->where('recurring_lesson_slot_id', $occurrence['slot_id'])
                ->where('occurs_at', $occurrence['starts_at'])
                ->exists();

            if ($cancelled) {
                continue;
            }

            $day = $occurrence['starts_at']->startOfDay();
            $this->materialize->execute($class, $day, $day, $actor);

            $lesson = Lesson::query()
                ->where('class_id', $class->getKey())
                ->where('recurring_lesson_slot_id', $occurrence['slot_id'])
                ->where('starts_at', $occurrence['starts_at'])
                ->lockForUpdate()
                ->first();

            // Pode não nascer (conflito de horário) ou já estar fechada: nesse
            // caso não é destino, e procura-se a seguinte.
            if ($lesson === null || $lesson->isClosed()) {
                continue;
            }

            if ($lesson->summary()->exists()) {
                // Não deveria acontecer — uma aula que só agora nasceu não tem
                // planeamento —, mas se tiver não pode ser apagado em silêncio.
                continue;
            }

            return $lesson;
        }

        return null;
    }

    /**
     * @param  array<string, string|int|null>  $values
     */
    private function keepAsPending(Lesson $lesson, array $values, User $actor): void
    {
        $labels = [
            'content' => null,
            'resources' => 'Recursos',
            'homework' => 'Trabalho de casa',
            'private_notes' => self::PRIVATE_NOTES_LABEL,
        ];

        $parts = [];

        foreach ($labels as $field => $label) {
            if (($values[$field] ?? null) !== null) {
                $parts[] = $label === null ? $values[$field] : $label.': '.$values[$field];
            }
        }

        $text = implode("\n\n", $parts);

        /** @var LessonPlan|null $plan */
        $plan = LessonPlan::query()->where('lesson_id', $lesson->getKey())->lockForUpdate()->first();

        if ($plan === null) {
            LessonPlan::query()->create([
                'lesson_id' => $lesson->getKey(),
                'planned_summary' => $text,
                'created_by' => $actor->getKey(),
            ]);

            return;
        }

        // Nunca por cima: uma segunda pendência junta-se à que já lá estava.
        $plan->planned_summary = trim($plan->planned_summary."\n\n".$text);
        $plan->save();
    }
}
