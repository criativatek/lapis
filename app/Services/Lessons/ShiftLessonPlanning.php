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
        return $this->shift($closed, $actor);
    }

    /**
     * O planeamento de uma aula ABERTA que vai ser RETIRADA por cair num dia não
     * letivo (reconciliação com o calendário): desce pela mesma cadeia de
     * `from()`, sem o pressuposto de que a origem está fechada.
     *
     * A CADEIA SALTA SÓ AS AULAS QUE A MESMA OPERAÇÃO VAI RETIRAR (`$retiring`,
     * os ids das A e B da classificação final): essas desaparecem a seguir e não
     * podem ser destino. Qualquer OUTRA aula aberta em dia não letivo continua
     * na cadeia — uma aula C num feriado que fica (com número indicado, criada
     * à mão, com faltas em rascunho…) não é saltada em silêncio, porque saltá-la
     * punha o planeamento de quem vem antes DEPOIS do dela. A classificação
     * (NonTeachingDayConflicts) já não deixa uma B atravessá-la
     * (`protected_lesson_after`), por isso aqui ela só aparece se o estado
     * mudou — e o token recusa.
     *
     * DIFERENÇA ÚNICA, E DE SEGURANÇA: a pendência de `from()` fica na última
     * aula da cadeia — e quando a cadeia é vazia essa aula é a própria origem,
     * que vai ser apagada, levando o texto atrás. Por isso, SEM nenhum destino
     * (nem aula aberta válida a seguir, nem ocorrência do horário por
     * materializar), esta variante NÃO TOCA EM NADA e devolve `stranded`: quem
     * chama deixa a aula onde está. Com pelo menos um destino, o comportamento é
     * o de `from()` — incluindo a pendência na última aula SOBREVIVENTE da
     * cadeia, quando o horário acaba antes do planeamento.
     *
     * A PENDÊNCIA JUNTA-SE À FRENTE. A reconciliação desce as B da ÚLTIMA para
     * a primeira: a pendência que já está na última aula veio de uma B POSTERIOR
     * a esta, por isso o texto desta vai antes dele (a pendência fica pela
     * ordem das aulas). `from()` continua a acrescentar no fim.
     *
     * A MATERIALIZAÇÃO COBRE O INTERVALO, NÃO SÓ O DIA (`$materializeFrom`): a
     * próxima ocorrência é materializada com todos os dias desde
     * `$materializeFrom` — e materializar um dia cria os tempos de TODOS os
     * públicos da turma. Só com o dia, o planeamento de outro público (um grupo
     * com tempos à segunda e à quarta) aterrava na quarta que nasceu aqui e
     * saltava a segunda anterior, ainda por materializar. Com o intervalo
     * inteiro, a próxima ocorrência válida de cada público já existe quando
     * chega a vez dele. `materialized_through` diz até que dia (Y-m-d) ficou
     * materializado, para quem chama não repetir o intervalo.
     *
     * @param  array<int, true>  $retiring  os ids das aulas que esta operação retira
     * @return array{
     *     shifted: int,
     *     materialized: bool,
     *     pending: bool,
     *     moves: list<array{from: Lesson, to: Lesson, materialized: bool, values: array<string, string|int|null>}>,
     *     pending_on: Lesson|null,
     *     pending_values: array<string, string|int|null>|null,
     *     stranded: bool,
     *     materialized_through: string|null,
     * }
     */
    public function fromRetiredLesson(Lesson $retired, User $actor, array $retiring = [], ?CarbonImmutable $materializeFrom = null): array
    {
        $following = $this->openLessonsAfter($retired, skip: $retiring);
        $firstNew = null;
        $through = null;

        if ($following->isEmpty()) {
            $firstNew = $this->materializeNextOccurrence($retired, $retired, $actor, $materializeFrom, $through);

            if ($firstNew === null) {
                return ['shifted' => 0, 'materialized' => false, 'pending' => false, 'moves' => [], 'pending_on' => null, 'pending_values' => null, 'stranded' => true, 'materialized_through' => $through];
            }
        }

        $trace = $this->shift($retired, $actor, $following, $firstNew, prependPending: true, materializeFrom: $materializeFrom, through: $through);

        return $trace + ['stranded' => false, 'materialized_through' => $through];
    }

    /**
     * @param  Collection<int, Lesson>|null  $following  as aulas abertas seguintes, já lidas (e bloqueadas)
     * @param  Lesson|null  $firstNew  o destino já materializado quando não há aula aberta seguinte
     * @param  string|null  $through  (saída) o último dia materializado, Y-m-d
     * @return array{shifted: int, materialized: bool, pending: bool, moves: list<array{from: Lesson, to: Lesson, materialized: bool, values: array<string, string|int|null>}>, pending_on: Lesson|null, pending_values: array<string, string|int|null>|null}
     */
    private function shift(
        Lesson $closed,
        User $actor,
        ?Collection $following = null,
        ?Lesson $firstNew = null,
        bool $prependPending = false,
        ?CarbonImmutable $materializeFrom = null,
        ?string &$through = null,
    ): array {
        $carry = $this->takeFrom($closed);

        if ($carry === null) {
            return ['shifted' => 0, 'materialized' => false, 'pending' => false, 'moves' => [], 'pending_on' => null, 'pending_values' => null];
        }

        $shifted = 0;
        $last = $closed;
        $moves = [];

        foreach (($following ?? $this->openLessonsAfter($closed)) as $lesson) {
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

        $next = $firstNew ?? $this->materializeNextOccurrence($closed, $last, $actor, $materializeFrom, $through);

        if ($next !== null) {
            $this->writeInto($next, $carry);
            $moves[] = ['from' => $last, 'to' => $next, 'materialized' => true, 'values' => $carry];

            return ['shifted' => $shifted + 1, 'materialized' => true, 'pending' => false, 'moves' => $moves, 'pending_on' => null, 'pending_values' => null];
        }

        $this->keepAsPending($last, $carry, $actor, $prependPending);

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
     * As aulas abertas seguintes do mesmo público, por ordem — o caminho por
     * onde o planeamento desce.
     *
     * POR OMISSÃO, A CADEIA CRUA — o comportamento de sempre, de que dependem
     * RecordLessonOutcome (o planeamento e a fila dos números de «professor
     * ausente») e PostponeLessonForCalendarActivity: uma aula num feriado por
     * regularizar continua a ser a aula seguinte — mudar isso em silêncio
     * trocava a ordem do planeamento de quem regista uma ausência.
     *
     * `$skip` (ids => true) deixa de fora AS AULAS QUE A MESMA OPERAÇÃO VAI
     * RETIRAR, e só a reconciliação com o calendário o pede (ver
     * `fromRetiredLesson` e a verificação da cadeia em NonTeachingDayConflicts).
     * Não salta «os dias não letivos»: uma aula num feriado que NÃO vai ser
     * retirada continua na cadeia.
     *
     * `$lock = false` lê sem FOR UPDATE: a classificação (só leitura).
     *
     * @param  array<int, true>  $skip
     * @return Collection<int, Lesson>
     */
    public function openLessonsAfter(Lesson $lesson, bool $lock = true, array $skip = []): Collection
    {
        $query = $this->sameAudience($lesson)
            ->where(fn ($query) => $query
                ->where('starts_at', '>', $lesson->starts_at)
                ->orWhere(fn ($same) => $same->where('starts_at', $lesson->starts_at)->where('id', '>', $lesson->getKey())))
            ->whereNull('outcome')
            ->where('status', '!=', LessonStatus::Taught)
            ->orderBy('starts_at')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $open = $query->get();

        if ($skip === [] || $open->isEmpty()) {
            return $open;
        }

        return $open->reject(fn (Lesson $candidate): bool => isset($skip[(int) $candidate->getKey()]))->values();
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
        // LIDA COM BLOQUEIO: uma leitura simples, dentro de uma transação
        // REPEATABLE READ, vem do retrato tirado no início — e levava o texto de
        // antes de uma edição que entretanto ficou gravada.
        /** @var LessonSummary|null $summary */
        $summary = $lesson->summary()->lockForUpdate()->first();

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

        $attributes = ['content' => $values['content'] ?? ''] + array_intersect_key($values, array_flip(['resources', 'homework', 'private_notes'])) + $provenance;

        // O mesmo que `updateOrCreate`, mas com a linha existente lida COM
        // BLOQUEIO (ver `takeFrom`).
        /** @var LessonSummary|null $existing */
        $existing = LessonSummary::query()->where('lesson_id', $lesson->getKey())->lockForUpdate()->first();

        if ($existing === null) {
            LessonSummary::query()->create(['lesson_id' => $lesson->getKey()] + $attributes);
        } else {
            $existing->fill($attributes)->save();
        }

        if ($lesson->status === LessonStatus::Preparation) {
            $lesson->status = LessonStatus::Prepared;
            $lesson->save();
        }
    }

    /**
     * @param  CarbonImmutable|null  $rangeFrom  materializar desde este dia (inclusive) até ao da ocorrência, e não só esse dia — ver `fromRetiredLesson`
     * @param  string|null  $through  (saída) o último dia materializado, Y-m-d
     */
    private function materializeNextOccurrence(Lesson $closed, Lesson $last, User $actor, ?CarbonImmutable $rangeFrom = null, ?string &$through = null): ?Lesson
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
        // O último dia que ESTA chamada já materializou: cada dia materializa-se
        // uma vez. Antes, cada candidata que não nascia (um conflito de horário,
        // uma aula de outro grupo) voltava a materializar o intervalo inteiro
        // desde `$rangeFrom` — quadrático no caminho de conflito.
        $done = null;

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

            $day = $occurrence['starts_at']->setTimezone(self::TIMEZONE)->startOfDay();

            if ($done === null || $day->greaterThan($done)) {
                $from = $rangeFrom !== null && $rangeFrom->setTimezone(self::TIMEZONE)->startOfDay()->lessThan($day)
                    ? $rangeFrom->setTimezone(self::TIMEZONE)->startOfDay()
                    : $day;

                if ($done !== null && ! $from->greaterThan($done)) {
                    $from = $done->addDay();
                }

                $this->materialize->execute($class, $from, $day, $actor);
                $done = $day;
            }

            if ($through === null || $day->toDateString() > $through) {
                $through = $day->toDateString();
            }

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

            // Uma aula que JÁ existia neste instante com OUTRO público (o grupo
            // do tempo mudou depois de ela nascer — o grupo é um instantâneo)
            // não é deste público: o planeamento nunca muda de grupo.
            if (($lesson->class_group_id === null ? null : (int) $lesson->class_group_id)
                !== ($closed->class_group_id === null ? null : (int) $closed->class_group_id)) {
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
    private function keepAsPending(Lesson $lesson, array $values, User $actor, bool $prepend = false): void
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

        // Nunca por cima: uma segunda pendência junta-se à que já lá estava —
        // à frente dela quando quem chama desce da última aula para a primeira
        // (ver `fromRetiredLesson`), para a pendência ficar pela ordem das aulas.
        $plan->planned_summary = trim($prepend ? $text."\n\n".$plan->planned_summary : $plan->planned_summary."\n\n".$text);
        $plan->save();
    }
}
