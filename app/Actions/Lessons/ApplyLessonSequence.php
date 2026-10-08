<?php

namespace App\Actions\Lessons;

use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonSequence;
use App\Models\LessonSequenceItem;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Classes\ClassArchivalWindow;
use App\Services\Lessons\LessonSequencePlan;
use App\Services\Lessons\LessonSequencePlanStep;
use App\Services\Lessons\LessonSequenceStepKind;
use App\Support\Lessons\LessonSequenceApplicationException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Aplica uma sequência ao calendário de uma turma, a partir de uma data.
 *
 * O ERRO QUE ESTA CLASSE JÁ TEVE: emparelhava o elemento i com a aula i das N
 * primeiras aulas futuras. Uma aula já preparada «consumia» o elemento sem o
 * receber — o elemento A desaparecia, B e C deslocavam-se e o último ficava sem
 * aula. Agora um horário ocupado é SALTADO SEM CONSUMIR ninguém: a fila de
 * elementos só avança quando um elemento é de facto colocado (ou reconhecido
 * como já colocado).
 *
 * UM SÓ PLANO. `preview()` e `execute()` chamam o mesmo `plan()` — a
 * pré-visualização que não seja exatamente a operação promete o que não vai
 * acontecer (o mesmo princípio de InsertLessonIntoSequence). A pré-visualização
 * corre-o dentro de uma transação que é SEMPRE revertida: para calcular o plano
 * é preciso materializar o horário futuro pela via única de sempre, e isso não
 * pode ficar gravado só por o professor ter espreitado ou cancelado.
 *
 * REAPLICAÇÃO. Cada sumário escrito guarda de que elemento veio e a impressão
 * digital do que lá pôs (`lesson_summaries.lesson_sequence_*`). É só isso que
 * permite reaplicar depois de editar a sequência sem duplicar nem deslocar:
 * uma aula que ainda tem o conteúdo da sequência é reescrita, uma que o
 * professor alterou fica como está, e nenhuma aula com conteúdo do professor
 * é substituída sem confirmação explícita. A sequência continua a não ser uma
 * ligação viva — guardá-la nunca toca numa aula.
 *
 * TUDO OU NADA. O plano completo é decidido antes de qualquer escrita, e as
 * escritas, a materialização e a auditoria vivem na mesma transação: uma falha a
 * meio não deixa metade da sequência aplicada.
 */
class ApplyLessonSequence
{
    private const TIMEZONE = 'Europe/Lisbon';

    /** Quantos dias de horário se materializam de cada vez, até haver aulas para todos os elementos. */
    private const MATERIALIZE_BLOCK_DAYS = 28;

    public function __construct(
        protected SaveLessonSummary $saveLessonSummary,
        protected MaterializeLessonsForRange $materialize,
        protected ClassArchivalWindow $archivalWindow,
        protected AuditLog $audit,
    ) {}

    /**
     * O que a aplicação FARIA, sem gravar nada.
     *
     * @param  list<string>  $replaceLessonUlids
     * @return array<string, mixed>
     */
    public function preview(
        LessonSequence $sequence,
        SchoolClass $class,
        ?int $classGroupId,
        CarbonImmutable $from,
        LessonCopyOptions $options,
        array $replaceLessonUlids,
        User $actor,
    ): array {
        $this->guard($sequence, $class, $classGroupId, $from, $actor);

        DB::beginTransaction();

        try {
            $plan = $this->plan($sequence, $class, $classGroupId, $from, $options, $replaceLessonUlids, $actor);

            // Montado ANTES da reversão: depois dela, as aulas que o plano
            // acabou de materializar já não existem.
            return $this->present($plan, $classGroupId);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  list<string>  $replaceLessonUlids
     */
    public function execute(
        LessonSequence $sequence,
        SchoolClass $class,
        ?int $classGroupId,
        CarbonImmutable $from,
        LessonCopyOptions $options,
        array $replaceLessonUlids,
        bool $confirmReplace,
        string $planToken,
        User $actor,
    ): ApplyLessonSequenceResult {
        // A porta toda corre — e pode recusar — antes de qualquer aula ser
        // tocada, incluindo antes de a transação abrir.
        $this->guard($sequence, $class, $classGroupId, $from, $actor);

        return DB::transaction(function () use ($sequence, $class, $classGroupId, $from, $options, $replaceLessonUlids, $confirmReplace, $planToken, $actor): ApplyLessonSequenceResult {
            $plan = $this->plan($sequence, $class, $classGroupId, $from, $options, $replaceLessonUlids, $actor);

            // Todas as recusas AQUI, antes da primeira escrita de conteúdo.
            // Lançadas de dentro da transação: a materialização que o plano
            // acabou de fazer desfaz-se com ela.
            if (! $plan->complete()) {
                throw ValidationException::withMessages(['from' => $this->incompleteMessage($plan)]);
            }

            if ($plan->replaceCount() > 0 && ! $confirmReplace) {
                throw ValidationException::withMessages([
                    'confirm_replace' => __('Confirma que queres substituir as aulas já preparadas antes de aplicar.'),
                ]);
            }

            if (! hash_equals($plan->token(), $planToken)) {
                throw ValidationException::withMessages([
                    'plan_token' => __('As aulas ou a sequência mudaram desde a pré-visualização. Revê a pré-visualização e confirma de novo.'),
                ]);
            }

            $appliedLessonUlids = [];

            foreach ($plan->steps as $step) {
                switch ($step->kind) {
                    case LessonSequenceStepKind::Fill:
                    case LessonSequenceStepKind::Update:
                    case LessonSequenceStepKind::Replace:
                        $appliedLessonUlids[] = $this->write($sequence, $step, $actor);
                        break;
                    case LessonSequenceStepKind::Unchanged:
                        $this->repairProvenance($step);
                        break;
                    case LessonSequenceStepKind::Release:
                        $this->release($step, $actor);
                        break;
                    default:
                        // keep, preserve, closed, nothing_to_copy: nenhuma escrita.
                        break;
                }
            }

            $counts = $plan->counts();
            $applied = $counts['fill'] + $counts['update'] + $counts['replace'];

            // `items_applied` / `items_skipped` mantêm-se, com o significado
            // novo: aplicados = aulas que receberam conteúdo; saltados =
            // elementos por colocar, que numa execução é sempre 0 (um plano
            // incompleto recusa-se acima).
            $this->audit->record(
                'lesson_sequence.applied',
                $sequence,
                $actor,
                'Sequência de aulas aplicada a uma turma.',
                [
                    'class_id' => $class->getKey(),
                    'class_group_id' => $classGroupId,
                    'from' => $plan->from->format('Y-m-d'),
                    'items_applied' => $applied,
                    'items_skipped' => $counts['unplaced'],
                    'counts' => $counts,
                    'copy_options' => $options->toArray(),
                ],
            );

            return new ApplyLessonSequenceResult(
                $applied,
                $counts['preserve'],
                $counts['unplaced'],
                $appliedLessonUlids,
                $counts,
                $plan->from,
            );
        });
    }

    /**
     * Before anything is written: same organization (defence in depth — the
     * tenancy scope already guarantees it, but this is reachable from user
     * input, not only trusted internal callers), same subject, and — only
     * when the sequence itself declares one — the same grade_level. A
     * sequence with no grade_level is unrestricted by grade, which is what
     * makes it usable in higher education. The actor must teach the class —
     * the exact same check LessonPolicy::create() already makes, reused
     * rather than reimplemented. Then the request itself: a sequence with
     * items, a chosen day that is not in the past, a group of THIS class.
     */
    protected function guard(LessonSequence $sequence, SchoolClass $class, ?int $classGroupId, CarbonImmutable $from, User $actor): void
    {
        $this->guardCompatibility($sequence, $class, $actor);

        if ($class->isArchived()) {
            throw LessonSequenceApplicationException::archivedClass();
        }

        if (! $sequence->items()->exists()) {
            throw LessonSequenceApplicationException::emptySequence();
        }

        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        if ($from->setTimezone(self::TIMEZONE)->startOfDay()->lessThan($today)) {
            throw ValidationException::withMessages([
                'from' => __('Escolhe hoje ou uma data posterior — as aulas anteriores nunca são alteradas.'),
            ]);
        }

        if ($classGroupId !== null) {
            $groupBelongs = ClassGroup::query()
                ->active()
                ->where('class_id', $class->getKey())
                ->whereKey($classGroupId)
                ->exists();

            if (! $groupBelongs) {
                throw ValidationException::withMessages([
                    'class_group_id' => __('O grupo escolhido não pertence a esta turma ou já não está ativo.'),
                ]);
            }
        }
    }

    protected function guardCompatibility(LessonSequence $sequence, SchoolClass $class, User $actor): void
    {
        if ($class->organization_id !== $sequence->organization_id) {
            throw LessonSequenceApplicationException::organizationMismatch();
        }

        if ($class->subject_id !== $sequence->subject_id) {
            throw LessonSequenceApplicationException::subjectMismatch();
        }

        if ($sequence->grade_level !== null && $class->grade_level !== $sequence->grade_level) {
            throw LessonSequenceApplicationException::gradeLevelMismatch();
        }

        Gate::forUser($actor)->authorize('create', [Lesson::class, $class]);
    }

    /**
     * O plano. Corre sempre DENTRO de uma transação (a da execução, ou a que a
     * pré-visualização reverte), com a turma bloqueada: duas aplicações em
     * simultâneo calculariam o mesmo plano a partir do mesmo estado.
     *
     * @param  list<string>  $replaceLessonUlids
     */
    private function plan(
        LessonSequence $sequence,
        SchoolClass $class,
        ?int $classGroupId,
        CarbonImmutable $from,
        LessonCopyOptions $options,
        array $replaceLessonUlids,
        User $actor,
    ): LessonSequencePlan {
        /** @var SchoolClass $lockedClass */
        $lockedClass = SchoolClass::query()->whereKey($class->getKey())->lockForUpdate()->firstOrFail();
        $academicYear = $lockedClass->academicYear()->firstOrFail();
        $yearStart = CarbonImmutable::parse($academicYear->starts_on, self::TIMEZONE)->startOfDay();
        $yearEnd = CarbonImmutable::parse($academicYear->ends_on, self::TIMEZONE)->startOfDay();

        $from = $from->setTimezone(self::TIMEZONE)->startOfDay();

        if ($from->greaterThan($yearEnd)) {
            throw ValidationException::withMessages([
                'from' => __('A data escolhida é posterior ao fim do ano letivo da turma.'),
            ]);
        }

        $from = $from->lessThan($yearStart) ? $yearStart : $from;

        // Uma turma arquivada já não tem horário a partir do dia do
        // arquivamento: a materialização corta aí, e aqui não vale a pena
        // pedir-lhe blocos que ela devolveria vazios.
        $lastScheduledDay = $this->archivalWindow->lastScheduledDay($lockedClass);
        $limit = $lastScheduledDay !== null && $lastScheduledDay->lessThan($yearEnd) ? $lastScheduledDay : $yearEnd;

        /** @var list<LessonSequenceItem> $items */
        $items = $sequence->items()->orderBy('position')->get()->values()->all();

        // As aulas que já existiam: as outras nascem durante este plano, e na
        // pré-visualização são revertidas — por isso o ecrã não lhes mostra ULID.
        $existingIds = array_flip(Lesson::query()
            ->where('class_id', $lockedClass->getKey())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());

        // SEMPRE em blocos contíguos a partir da data escolhida (também numa
        // reaplicação — materializar é idempotente). Usar só as aulas que já
        // existiam saltava as ocorrências de uma semana que o professor nunca
        // abriu, entre duas que abriu. A «fronteira» é o último dia
        // materializado: o plano só vê aulas até lá, e estende-se enquanto
        // estiver incompleto e houver ano letivo.
        $cursor = $from;
        $boundary = $from->subDay();
        $plan = null;

        do {
            if ($cursor->lessThanOrEqualTo($limit)) {
                $blockEnd = $cursor->addDays(self::MATERIALIZE_BLOCK_DAYS - 1);
                $blockEnd = $blockEnd->greaterThan($limit) ? $limit : $blockEnd;

                $this->materialize->execute($lockedClass, $cursor, $blockEnd, $actor);

                $boundary = $blockEnd;
                $cursor = $blockEnd->addDay();
            }

            $plan = $this->buildPlan($sequence, $lockedClass, $classGroupId, $from, $boundary, $options, $replaceLessonUlids, $items, $existingIds);
        } while (! $plan->complete() && $cursor->lessThanOrEqualTo($limit));

        return $plan;
    }

    /**
     * A caminhada pelas aulas candidatas, em memória, sobre o estado atual da
     * base de dados.
     *
     * @param  list<string>  $replaceLessonUlids
     * @param  list<LessonSequenceItem>  $items
     * @param  array<int, int>  $existingIds
     */
    private function buildPlan(
        LessonSequence $sequence,
        SchoolClass $class,
        ?int $classGroupId,
        CarbonImmutable $from,
        CarbonImmutable $boundary,
        LessonCopyOptions $options,
        array $replaceLessonUlids,
        array $items,
        array $existingIds,
    ): LessonSequencePlan {
        // Uma aula de hoje que já começou pode ter sido dada sem estar
        // marcada: o início efetivo nunca é anterior a agora.
        $now = CarbonImmutable::now(self::TIMEZONE);
        $effectiveStart = $from->greaterThan($now) ? $from : $now;
        $effectiveStartKey = $effectiveStart->format('Y-m-d H:i:s');

        $candidates = $this->sameAudience(Lesson::query()->where('class_id', $class->getKey()), $classGroupId)
            ->where('starts_at', '>=', $effectiveStartKey)
            // Só o que está dentro da fronteira materializada; a exceção são as
            // aulas com proveniência DESTA sequência, que podem ter de sair
            // (`release`) ou ser reescritas mesmo para lá dela.
            ->where(fn (Builder $query) => $query
                ->where('starts_at', '<=', $boundary->endOfDay()->format('Y-m-d H:i:s'))
                ->orWhereHas('summary', fn (Builder $summary) => $summary->where('lesson_sequence_id', $sequence->getKey())))
            ->with('summary')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // Os elementos JÁ ASSENTES não voltam a ser colocados: os que estão
        // numa aula anterior ao início efetivo (a que nunca se toca) e os que
        // estão numa aula fechada, em qualquer data.
        $itemsById = [];

        foreach ($items as $item) {
            $itemsById[(int) $item->getKey()] = $item;
        }

        $placed = [];

        $provenance = LessonSummary::query()
            ->where('lesson_sequence_id', $sequence->getKey())
            ->whereNotNull('lesson_sequence_item_id')
            ->whereHas('lesson', fn (Builder $query) => $query
                ->where('class_id', $class->getKey())
                ->when(
                    $classGroupId === null,
                    fn (Builder $audience) => $audience->whereNull('class_group_id'),
                    fn (Builder $audience) => $audience->where('class_group_id', $classGroupId),
                ))
            ->with('lesson')
            ->get()
            ->sortBy(fn (LessonSummary $summary): string => $summary->lesson->starts_at->format('Y-m-d H:i:s').'|'.str_pad((string) $summary->lesson->getKey(), 12, '0', STR_PAD_LEFT));

        foreach ($provenance as $summary) {
            $itemId = (int) $summary->lesson_sequence_item_id;
            $lesson = $summary->lesson;

            if (! isset($itemsById[$itemId]) || isset($placed[$itemId])) {
                continue;
            }

            if ($lesson->isClosed() || $lesson->starts_at->format('Y-m-d H:i:s') < $effectiveStartKey) {
                $placed[$itemId] = ['item' => $itemsById[$itemId], 'lesson' => $lesson];
            }
        }

        $queue = array_values(array_filter($items, fn (LessonSequenceItem $item): bool => ! isset($placed[(int) $item->getKey()])));
        $next = 0;
        $steps = [];

        $boundaryKey = $boundary->endOfDay()->format('Y-m-d H:i:s');

        foreach ($candidates as $lesson) {
            $remaining = $next < count($queue);
            $isNew = ! isset($existingIds[(int) $lesson->getKey()]);

            // Para lá da fronteira só se entra com a fila ESGOTADA (para
            // libertar o que a sequência lá deixou). Com elementos por colocar,
            // a caminhada pára aqui: entre a fronteira e esta aula pode haver
            // ocorrências ainda por materializar, e colocar um elemento nesta
            // saltá-las-ia. O plano fica incompleto e `plan()` estende a
            // fronteira.
            if ($remaining && $lesson->starts_at->format('Y-m-d H:i:s') > $boundaryKey) {
                break;
            }

            if ($lesson->isClosed()) {
                // Nunca escrita e não consome. Só se mostra enquanto ainda há
                // elementos por colocar — depois disso é ruído.
                if ($remaining) {
                    $steps[] = new LessonSequencePlanStep(LessonSequenceStepKind::Closed, $lesson, $isNew, null);
                }

                continue;
            }

            $summary = $lesson->summary;
            $isBlank = $this->summaryIsBlank($summary);
            $isOwn = $summary !== null && (int) $summary->lesson_sequence_id === (int) $sequence->getKey();
            $isOwnUnchanged = $summary !== null && $isOwn && $summary->sequence_content_hash === $summary->contentFingerprint();

            if (! $remaining) {
                // Fila esgotada. O que a sequência pôs numa aula e o professor
                // não tocou, e que já não tem elemento (passou para outra aula
                // ou foi removido da sequência), sai — senão ficava duplicado.
                if ($isOwnUnchanged) {
                    $steps[] = new LessonSequencePlanStep(LessonSequenceStepKind::Release, $lesson, $isNew, null);
                }

                continue;
            }

            $item = $queue[$next];

            if ($isBlank) {
                $details = $this->fillDetails($item, $options, $summary);
                $steps[] = $details === []
                    ? new LessonSequencePlanStep(LessonSequenceStepKind::NothingToCopy, $lesson, $isNew, $item)
                    : new LessonSequencePlanStep(LessonSequenceStepKind::Fill, $lesson, $isNew, $item, $details);
                $next++;

                continue;
            }

            if ($summary !== null && $isOwnUnchanged) {
                $details = $this->updateDetails($item, $options);
                $kind = $this->sameValues($details, $summary)
                    ? LessonSequenceStepKind::Unchanged
                    : LessonSequenceStepKind::Update;

                $steps[] = new LessonSequencePlanStep($kind, $lesson, $isNew, $item, $details);
                $next++;

                continue;
            }

            if ($summary !== null && $isOwn && (int) $summary->lesson_sequence_item_id === (int) $item->getKey()) {
                // A versão do professor deste mesmo elemento: é a colocação
                // dele, fica como está e consome o elemento.
                $steps[] = new LessonSequencePlanStep(LessonSequenceStepKind::Keep, $lesson, $isNew, $item);
                $next++;

                continue;
            }

            // Conteúdo que não é desta sequência (ou é, mas de outro elemento,
            // e o professor alterou-o): preserva-se por omissão — o horário é
            // saltado sem consumir o elemento — e só se substitui por escolha.
            if (in_array($lesson->ulid, $replaceLessonUlids, true)) {
                $details = $this->replaceDetails($item, $options);
                $steps[] = $details === []
                    ? new LessonSequencePlanStep(LessonSequenceStepKind::NothingToCopy, $lesson, $isNew, $item)
                    : new LessonSequencePlanStep(LessonSequenceStepKind::Replace, $lesson, $isNew, $item, $details);
                $next++;

                continue;
            }

            $steps[] = new LessonSequencePlanStep(LessonSequenceStepKind::Preserve, $lesson, $isNew, null);
        }

        return new LessonSequencePlan(
            $from,
            $classGroupId,
            $options,
            $steps,
            array_values($placed),
            array_slice($queue, $next),
        );
    }

    /**
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    private function sameAudience(Builder $query, ?int $classGroupId): Builder
    {
        return $classGroupId === null
            ? $query->whereNull('class_group_id')
            : $query->where('class_group_id', $classGroupId);
    }

    private function summaryIsBlank(?LessonSummary $summary): bool
    {
        return $summary === null
            || (trim((string) $summary->content) === ''
                && trim((string) $summary->resources) === ''
                && trim((string) $summary->homework) === ''
                && trim((string) $summary->private_notes) === '');
    }

    /**
     * Aula VAZIA: só os campos selecionados e não vazios na origem. `content`
     * é NOT NULL na base de dados, por isso uma aula sem linha que fica com
     * outro campo para escrever leva `content = ''` como estrutura (nunca o
     * texto do elemento). Sem nada para escrever, a lista é vazia e a
     * disposição é `nothing_to_copy`.
     *
     * @return array<string, string|null>
     */
    private function fillDetails(LessonSequenceItem $item, LessonCopyOptions $options, ?LessonSummary $existing): array
    {
        $details = [];

        foreach ($this->fieldMap($item, $options) as $key => [$selected, $source]) {
            if ($selected && $source !== null && trim($source) !== '') {
                $details[$key] = $source;
            }
        }

        if ($existing === null && $details !== [] && ! array_key_exists('content', $details)) {
            $details['content'] = '';
        }

        return $details;
    }

    /**
     * Aula PRÓPRIA INALTERADA: o que lá estava era da sequência e o professor
     * não lhe mexeu — reescrevem-se os quatro campos como num preenchimento de
     * aula vazia (os não selecionados ficam vazios). Ao contrário do
     * preenchimento, nunca fica vazio: se o elemento passou a não ter nada
     * para as opções escolhidas, a aula fica com o sumário em branco.
     *
     * @return array<string, string|null>
     */
    private function updateDetails(LessonSequenceItem $item, LessonCopyOptions $options): array
    {
        $details = ['content' => '', 'private_notes' => null, 'resources' => null, 'homework' => null];

        foreach ($this->fieldMap($item, $options) as $key => [$selected, $source]) {
            if ($selected && $source !== null && trim($source) !== '') {
                $details[$key] = $source;
            }
        }

        return $details;
    }

    /**
     * SUBSTITUIÇÃO confirmada: os campos selecionados passam a ter o valor do
     * elemento (vazio na origem ⇒ `null`, e `content` ⇒ `''`); os não
     * selecionados ficam como estão — as notas privadas do professor
     * sobrevivem por omissão.
     *
     * @return array<string, string|null>
     */
    private function replaceDetails(LessonSequenceItem $item, LessonCopyOptions $options): array
    {
        $details = [];

        foreach ($this->fieldMap($item, $options) as $key => [$selected, $source]) {
            if (! $selected) {
                continue;
            }

            $details[$key] = $source !== null && trim($source) !== '' ? $source : ($key === 'content' ? '' : null);
        }

        return $details;
    }

    /**
     * @return array<string, array{0: bool, 1: ?string}>
     */
    private function fieldMap(LessonSequenceItem $item, LessonCopyOptions $options): array
    {
        return [
            'content' => [$options->summary, $item->summary],
            'resources' => [$options->resources, $item->resources],
            'homework' => [$options->homework, $item->homework],
            'private_notes' => [$options->privateNotes, $item->private_notes],
        ];
    }

    /**
     * O resultado seria byte a byte o que lá está?
     *
     * @param  array<string, string|null>  $details
     */
    private function sameValues(array $details, LessonSummary $existing): bool
    {
        foreach ($details as $key => $value) {
            if ((string) $existing->getAttribute($key) !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Escreve o sumário pela via única (estado «Preparada», auditoria) e só
     * depois a proveniência — uma segunda escrita que não muda conteúdo, por
     * isso silenciosa (não sobe `summary_version` outra vez).
     */
    private function write(LessonSequence $sequence, LessonSequencePlanStep $step, User $actor): string
    {
        /** @var array{content?: string, private_notes?: string|null, resources?: string|null, homework?: string|null} $details */
        $details = $step->details;
        $summary = $this->saveLessonSummary->execute($step->lesson, $details, $actor)->refresh();

        $summary->forceFill([
            'lesson_sequence_id' => $sequence->getKey(),
            'lesson_sequence_item_id' => $step->item?->getKey(),
            'sequence_content_hash' => $summary->contentFingerprint(),
        ])->saveQuietly();

        return $step->lesson->ulid;
    }

    /**
     * O conteúdo já é o do elemento; se a aula só tinha a proveniência de um
     * elemento que entretanto mudou de identidade, corrige-se em silêncio.
     */
    private function repairProvenance(LessonSequencePlanStep $step): void
    {
        $summary = $step->lesson->summary;

        if ($summary === null || $step->item === null || (int) $summary->lesson_sequence_item_id === (int) $step->item->getKey()) {
            return;
        }

        $summary->forceFill(['lesson_sequence_item_id' => $step->item->getKey()])->saveQuietly();
    }

    /**
     * Apaga o que a sequência lá pôs — PELO MODELO (sobe `summary_version`),
     * e devolve a aula a «Por preparar» se era «Preparada», a mesma semântica
     * de ClearLessonSummary.
     */
    private function release(LessonSequencePlanStep $step, User $actor): void
    {
        /** @var Lesson $locked */
        $locked = Lesson::query()->lockForUpdate()->findOrFail($step->lesson->getKey());
        $summary = $locked->summary()->first();

        if ($summary === null) {
            return;
        }

        $summary->delete();

        if ($locked->status === LessonStatus::Prepared) {
            $locked->status = LessonStatus::Preparation;
            $locked->save();
        }

        $this->audit->record(
            'lesson.summary_released_by_sequence',
            $locked,
            $actor,
            'Sumário retirado: o elemento da sequência passou para outra aula.',
            ['to_status' => $locked->status->value],
        );
    }

    private function incompleteMessage(LessonSequencePlan $plan): string
    {
        return trans_choice(
            'Não há aulas suficientes no horário até ao fim do ano letivo para colocar :count elemento da sequência.|Não há aulas suficientes no horário até ao fim do ano letivo para colocar :count elementos da sequência.',
            count($plan->unplaced),
        );
    }

    /**
     * A resposta da pré-visualização.
     *
     * @return array<string, mixed>
     */
    private function present(LessonSequencePlan $plan, ?int $classGroupId): array
    {
        $label = __('Turma inteira');

        if ($classGroupId !== null) {
            $label = (string) ClassGroup::query()->whereKey($classGroupId)->value('label');
        }

        return [
            'from' => $plan->from->format('Y-m-d'),
            'audience' => ['class_group_id' => $classGroupId, 'label' => $label],
            'complete' => $plan->complete(),
            'plan_token' => $plan->token(),
            'steps' => array_map(fn (LessonSequencePlanStep $step): array => [
                'kind' => $step->kind->value,
                'lesson' => $this->presentLesson($step->lesson, $step->lessonIsNew),
                'item' => $step->item === null ? null : $this->presentItem($step->item),
            ], $plan->steps),
            'already_applied' => array_map(fn (array $entry): array => [
                'item' => $this->presentItem($entry['item']),
                'lesson' => [
                    'starts_at' => $entry['lesson']->starts_at->toIso8601String(),
                    'state_label' => $this->stateLabel($entry['lesson']),
                ],
            ], $plan->alreadyApplied),
            'unplaced' => array_map(fn (LessonSequenceItem $item): array => $this->presentItem($item), $plan->unplaced),
            'counts' => $plan->counts(),
            'requires_replace_confirmation' => $plan->replaceCount() > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLesson(Lesson $lesson, bool $isNew): array
    {
        $summary = $lesson->summary;
        $current = $summary === null ? null : trim((string) $summary->content);

        return [
            // Uma aula que o plano materializou não existirá depois da
            // pré-visualização: o ULID dela não serve de nada ao ecrã.
            'ulid' => $isNew ? null : $lesson->ulid,
            'starts_at' => $lesson->starts_at->toIso8601String(),
            'ends_at' => $lesson->ends_at?->toIso8601String(),
            'lesson_number' => $lesson->lesson_number,
            'state_label' => $this->stateLabel($lesson),
            'current_summary' => $current === '' ? null : $current,
        ];
    }

    /**
     * @return array{ulid: string, position: int, summary: string}
     */
    private function presentItem(LessonSequenceItem $item): array
    {
        return ['ulid' => $item->ulid, 'position' => $item->position, 'summary' => $item->summary];
    }

    private function stateLabel(Lesson $lesson): string
    {
        if ($lesson->outcome instanceof LessonOutcome) {
            return $lesson->outcome->label();
        }

        return match ($lesson->status) {
            LessonStatus::Preparation => __('Por preparar'),
            LessonStatus::Prepared => __('Preparada'),
            LessonStatus::Taught => __('Lecionada'),
        };
    }
}
