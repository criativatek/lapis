<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\LinkSplitLessonSlot;
use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Actions\Lessons\ReconcileLessonsWithSlotValidity;
use App\Actions\Lessons\ReviseRecurringLessonSlot;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\RecurringLessonSlotRequest;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\ScheduleChangeNotice;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LessonScheduleController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected MaterializeLessonsForRange $materializeLessonsForRange,
        protected ReviseRecurringLessonSlot $reviseRecurringLessonSlot,
        protected LinkSplitLessonSlot $linkSplitLessonSlot,
        protected ReconcileLessonsWithSlotValidity $reconcileLessons,
        protected LessonNumbering $numbering,
        protected CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function store(RecurringLessonSlotRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        // `effective_from` is never a column on the model — it only exists to
        // drive the revision below, and a create() has no route-bound slot for
        // it to apply to. Stripped here rather than left for
        // preventSilentlyDiscardingAttributes() to reject.
        DB::transaction(function () use ($request): void {
            $slot = RecurringLessonSlot::create($request->safe()->except('effective_from', 'same_lesson_as'));
            $this->linkIfRequested($request, $slot);
        });

        return back();
    }

    /**
     * «Mesma lição que…»: só quando o pedido traz o campo. Um cliente que não o
     * envia não mexe no vínculo que o tempo já tinha.
     */
    private function linkIfRequested(RecurringLessonSlotRequest $request, RecurringLessonSlot $slot): void
    {
        if ($request->has('same_lesson_as')) {
            $this->linkSplitLessonSlot->execute($slot, $request->sameLessonAsSlot());
        }
    }

    /**
     * A slot that does not yet require versioning (§ RecurringLessonSlot::
     * requiresVersioning() — not-yet-started, or started exactly today but
     * still Lesson-free) is still edited in place: nothing has happened
     * under it yet, so there is no history to protect. Once it does require
     * versioning, an in-place edit would silently rewrite what actually
     * happened, so the change is versioned instead: ReviseRecurringLessonSlot
     * closes the current row and opens a new one, and `lessons` is never
     * touched by either branch.
     */
    public function update(
        RecurringLessonSlotRequest $request,
        RecurringLessonSlot $recurringLessonSlot,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        $timezone = $this->currentOrganization->get()->timezone;

        $validated = $request->safe();

        // A VIGÊNCIA MANDA TAMBÉM NAS AULAS JÁ MATERIALIZADAS (0.146.2): a
        // edição e a reconciliação correm na mesma transação, e se a
        // reconciliação tiver de recusar (renumeraria histórico lecionado) a
        // edição reverte com ela.
        return DB::transaction(function () use ($request, $recurringLessonSlot, $timezone, $validated): RedirectResponse {
            $originClassId = (int) $recurringLessonSlot->class_id;
            $targetClassId = (int) $validated['class_id'];

            // Turma primeiro, tempo depois: uma materialização da mesma turma
            // espera por esta edição em vez de ler a vigência a meio. Quando o
            // tempo muda de turma bloqueiam-se as DUAS, sempre por ordem de id,
            // para que duas edições cruzadas não se bloqueiem uma à outra.
            SchoolClass::query()
                ->whereKey(array_values(array_unique([$originClassId, $targetClassId])))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $preserved = $this->applyUpdate($request, $recurringLessonSlot, $timezone, $validated);

            if ($targetClassId !== $originClassId) {
                // As aulas vazias da turma de destino que ficaram fora da
                // vigência acertam-se aqui; a regeneração faz-se ao abrir a
                // semana.
                $this->reconcileLessons->execute($targetClassId);
            }

            return $this->withNotice(
                $this->reconciled(back(), $originClassId),
                ScheduleChangeNotice::describe($preserved, __('Horário guardado.'), restFollowNewSchedule: true),
            );
        });
    }

    /**
     * A explicação PERSISTENTE do que ficou como estava (0.168.0): vai na flash
     * do Inertia, ao lado da mensagem habitual, e o ecrã mostra-a num aviso que
     * só desaparece quando o professor o fecha. Sem nada preservado, não há
     * aviso e a mensagem habitual segue sozinha.
     */
    private function withNotice(RedirectResponse $response, ?string $message): RedirectResponse
    {
        if ($message !== null) {
            Inertia::flash('scheduleNotice', ['message' => $message]);
        }

        return $response;
    }

    /**
     * @return Collection<int, Lesson> as aulas com conteúdo que ficaram nas horas ou na turma anteriores
     */
    private function applyUpdate(
        RecurringLessonSlotRequest $request,
        RecurringLessonSlot $recurringLessonSlot,
        string $timezone,
        ValidatedInput $validated,
    ): Collection {
        /** @var RecurringLessonSlot $locked */
        $locked = RecurringLessonSlot::query()
            ->whereKey($recurringLessonSlot->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $targetClassId = (int) $validated['class_id'];
        $changesClass = $targetClassId !== (int) $locked->class_id;
        $retimes = (int) $validated['day_of_week'] !== (int) $locked->day_of_week
            || (string) $validated['starts_at'] !== substr((string) $locked->starts_at, 0, 5)
            || (string) $validated['ends_at'] !== substr((string) $locked->ends_at, 0, 5);

        // A REVERIFICAÇÃO SOB BLOQUEIO. `effective_from` é exigido por
        // `rules()`, que corre antes; entre esse instante e este, o tempo pode
        // ter adquirido histórico (uma aula gravada noutro separador) e passado
        // a exigir versionamento sem que o pedido traga a data. Sem esta guarda
        // o que o professor via era um TypeError em vez da pergunta que falta
        // responder.
        //
        // MUDAR DE TURMA TAMBÉM VERSIONA quando alguma aula do tempo carrega
        // conteúdo: a aula com sumário, plano, faltas ou lecionada fica na
        // turma original, e a turma nova só recebe aulas a partir da data.
        $mustRevise = $locked->requiresVersioning($timezone)
            || ($changesClass && $locked->hasLessonsCarryingContent());

        if ($mustRevise) {
            $effectiveFrom = $validated['effective_from'] ?? null;

            if (! is_string($effectiveFrom) || trim($effectiveFrom) === '') {
                throw ValidationException::withMessages([
                    'effective_from' => __(
                        'Este tempo adquiriu histórico durante a gravação; indique a partir de quando a alteração passa a vigorar.',
                    ),
                ]);
            }

            $revised = $this->reviseRecurringLessonSlot->execute($locked, $effectiveFrom, [
                'class_id' => $targetClassId,
                // `?? null` e não a chave a seco: um cliente antigo — ou o
                // formulário de uma turma sem grupos, que nem desenha o campo —
                // não envia `class_group_id` de todo, e a ausência quer dizer
                // exatamente o mesmo que NULL: turma inteira.
                'class_group_id' => $validated['class_group_id'] ?? null,
                'day_of_week' => $validated['day_of_week'],
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'ends_on' => $validated['ends_on'],
            ]);
            $this->linkIfRequested($request, $revised);

            // As aulas com conteúdo de DEPOIS da data de aplicação ficam no
            // tempo antigo — na turma e nas horas anteriores. Diz-se.
            return $changesClass || $retimes
                ? $locked->lessonsCarryingContent()
                    ->where('starts_at', '>=', $effectiveFrom.' 00:00:00')
                    ->with('schoolClass')
                    ->get()
                : new Collection;
        }

        $attributes = $validated->except('effective_from', 'same_lesson_as');

        if ($changesClass) {
            // O grupo e a chave «mesma lição» eram da turma antiga; na nova
            // começam limpos (a validação já recusou um grupo de outra turma).
            $attributes['class_group_id'] = $validated['class_group_id'] ?? null;
            $attributes['split_lesson_key'] = null;
        }

        $originClassId = (int) $locked->class_id;
        $locked->update($attributes);
        $this->linkIfRequested($request, $locked);

        // EDITAR NO LUGAR É RETROATIVO (o tempo não tem histórico a proteger),
        // por isso as aulas VAZIAS que já não batem com o padrão novo saem —
        // ao mudar de turma, todas; ao mudar só o dia ou as horas, as que ficaram
        // desencontradas — e a materialização volta a gerá-las à hora certa ao
        // abrir a semana. Uma aula com qualquer conteúdo nunca é tocada.
        // A numeração da turma de origem fecha.
        if ($this->removeEmptyLessons($locked, onlyMismatched: ! $changesClass) > 0) {
            $this->numbering->resequence($originClassId);
        }

        // O que sobrou desencontrado do tempo novo carrega conteúdo (só
        // assiduidade, por exemplo) e ficou às horas anteriores. Diz-se.
        $preserved = $retimes
            ? $locked->lessons()->with('schoolClass')->get()
                ->reject(fn (Lesson $lesson): bool => $this->matchesPattern($lesson, $locked))
                ->values()
            : new Collection;

        if (! $changesClass) {
            $locked->lessons()
                ->where('status', LessonStatus::Preparation)
                ->whereDoesntHave('summary')
                ->whereDoesntHave('plan')
                ->update(['class_group_id' => $locked->class_group_id]);
        }

        return $preserved;
    }

    /**
     * A aula está no dia e às horas atuais do tempo? (`starts_at` e `ends_at`
     * das aulas são hora local.)
     */
    private function matchesPattern(Lesson $lesson, RecurringLessonSlot $slot): bool
    {
        return $lesson->starts_at->dayOfWeekIso === (int) $slot->day_of_week
            && $lesson->starts_at->format('H:i') === substr((string) $slot->starts_at, 0, 5)
            && $lesson->ends_at->format('H:i') === substr((string) $slot->ends_at, 0, 5);
    }

    /**
     * Apaga as aulas do tempo que NÃO carregam nada: preparação, sem resultado,
     * sem assiduidade (nem rascunho), sem sumário e sem plano — o mesmo critério
     * de `ReconcileLessonsWithSlotValidity`. Com `$onlyMismatched`, só as que já
     * não correspondem ao dia e às horas atuais do tempo (as `starts_at` e
     * `ends_at` das aulas são hora local). Devolve quantas saíram.
     */
    private function removeEmptyLessons(RecurringLessonSlot $slot, bool $onlyMismatched = false): int
    {
        $removed = 0;

        $slot->lessons()
            ->where('status', LessonStatus::Preparation)
            ->whereNull('outcome')
            ->whereNull('attendance_recorded_at')
            ->whereDoesntHave('summary')
            ->whereDoesntHave('plan')
            ->whereDoesntHave('attendances')
            ->lockForUpdate()
            ->get()
            ->each(function (Lesson $lesson) use (&$removed, $onlyMismatched, $slot): void {
                if ($onlyMismatched && $this->matchesPattern($lesson, $slot)) {
                    return;
                }

                $lesson->delete();
                $removed++;
            });

        return $removed;
    }

    /**
     * Retira as aulas abertas e vazias que ficaram fora da vigência e diz-o ao
     * professor, sem detalhes técnicos. Corre dentro da transação de quem
     * edita, com a turma bloqueada antes — a mesma disciplina da materialização
     * e da eliminação.
     */
    private function reconciled(RedirectResponse $response, int $classId): RedirectResponse
    {
        SchoolClass::query()->whereKey($classId)->lockForUpdate()->firstOrFail();

        $result = $this->reconcileLessons->execute($classId);

        if ($result['removed'] > 0 && $result['preserved'] > 0) {
            return $response->with('success', 'Horário atualizado. As aulas futuras fora da nova vigência foram ajustadas; as que já têm registos foram mantidas.');
        }

        if ($result['removed'] > 0) {
            return $response->with('success', 'Horário atualizado. As aulas futuras fora da nova vigência foram ajustadas.');
        }

        if ($result['preserved'] > 0) {
            return $response->with('success', 'Horário atualizado. As aulas fora da nova vigência que já têm registos foram mantidas.');
        }

        return $response;
    }

    /**
     * A slot that does not require versioning (§ RecurringLessonSlot::
     * requiresVersioning() — not-yet-started, or started exactly today but
     * still Lesson-free) and never produced a Lesson is still a true delete.
     * Every other case preserves the row instead — closing it with `ends_on`
     * rather than removing it — because either it already governed real
     * Lessons (which must keep a slot row to point their FK at) or removing
     * it would be exactly the history-rewrite this whole feature exists to
     * prevent.
     */
    public function destroy(Request $request, RecurringLessonSlot $recurringLessonSlot): RedirectResponse
    {
        Gate::authorize('delete', $recurringLessonSlot);
        $this->refuseDuringImpersonation($request);

        $timezone = $this->currentOrganization->get()->timezone;

        // «DEIXA DE VIGORAR A PARTIR DE» (0.168.0, opcional). Sem data, tudo
        // fica como sempre foi (apaga o que nunca produziu nada; fecha-se na
        // véspera o que já produziu). Com data, o tempo fecha-se na véspera
        // dela — e as aulas vazias que ficam fora da vigência saem, as que têm
        // conteúdo ficam —, tenha ou não histórico: o professor disse quando.
        $effectiveFrom = $this->stopEffectiveFrom($request, $recurringLessonSlot, $timezone);

        if ($effectiveFrom !== null) {
            return DB::transaction(function () use ($recurringLessonSlot, $effectiveFrom): RedirectResponse {
                SchoolClass::query()->whereKey($recurringLessonSlot->class_id)->lockForUpdate()->firstOrFail();

                /** @var RecurringLessonSlot $locked */
                $locked = RecurringLessonSlot::query()
                    ->whereKey($recurringLessonSlot->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $closesOn = CarbonImmutable::parse($effectiveFrom)->subDay()->toDateString();

                // Clamp, NUNCA estender: um `ends_on` já anterior fica como está.
                if ($locked->ends_on === null || $locked->ends_on->toDateString() > $closesOn) {
                    $locked->update(['ends_on' => $closesOn]);
                }

                return $this->withNotice(
                    $this->reconciled(back(), $locked->class_id),
                    $this->stopNotice($locked, $effectiveFrom),
                );
            });
        }

        if (! $recurringLessonSlot->requiresVersioning($timezone)) {
            // `$hasLessons` NUNCA é lido fora da transação. Lido cedo e sem
            // bloqueio, uma materialização concorrente da mesma turma — outro
            // separador em "Aulas e Sumários", que materializa sozinha ao
            // abrir a semana (LessonWeekController::index()) — podia criar a
            // aula DEPOIS dessa leitura e ANTES do hard delete: o slot
            // desaparecia do Horário mas a FK `nullOnDelete` deixava a aula
            // recém-nascida órfã (`recurring_lesson_slot_id = NULL`),
            // invisível a esta mesma ação daí em diante e presa para sempre
            // em "Aulas e Sumários" (caso real: "AE 8.º F Experiência").
            // A leitura corre agora DENTRO da transação, DEPOIS do bloqueio
            // — turma primeiro, tempo depois, a mesma ordem já usada no resto
            // do controlador — para que nada consiga materializar por baixo
            // dela entre a leitura e o delete.
            return DB::transaction(function () use ($recurringLessonSlot, $timezone): RedirectResponse {
                SchoolClass::query()->whereKey($recurringLessonSlot->class_id)->lockForUpdate()->firstOrFail();

                /** @var RecurringLessonSlot $locked */
                $locked = RecurringLessonSlot::query()
                    ->whereKey($recurringLessonSlot->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->lessons()->exists()) {
                    $locked->delete();

                    return back();
                }

                // A slot that already has Lessons materialized under it — future
                // ones materialized ahead of time, or a not-yet-started slot with
                // no RELEVANT history but that already produced empty preparation
                // Lessons — never hard-deletes: that would either hit the FK's
                // nullOnDelete/restrict behaviour or, worse, silently orphan
                // materialized data. Close it instead.
                $locked->update(['ends_on' => $this->closesOnForHardStop($locked, $timezone)]);

                return $this->withNotice(
                    $this->reconciled(back(), $locked->class_id),
                    $this->stopNotice($locked),
                );
            });
        }

        // Requires versioning: never delete. Close it as of today, locked
        // the same way ReviseRecurringLessonSlot locks a revise, so a
        // concurrent edit and a concurrent removal cannot race. The class is
        // locked FIRST, matching update()'s order, to avoid the deadlock
        // above.
        return DB::transaction(function () use ($recurringLessonSlot, $timezone): RedirectResponse {
            SchoolClass::query()->whereKey($recurringLessonSlot->class_id)->lockForUpdate()->firstOrFail();

            /** @var RecurringLessonSlot $locked */
            $locked = RecurringLessonSlot::query()
                ->whereKey($recurringLessonSlot->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update(['ends_on' => $this->closesOnForHardStop($locked, $timezone)]);

            return $this->withNotice(
                $this->reconciled(back(), $locked->class_id),
                $this->stopNotice($locked),
            );
        });
    }

    /**
     * As aulas com conteúdo que ficam depois de o tempo deixar de vigorar —
     * `$from` (a data pedida) ou o dia a seguir ao `ends_on` — explicadas.
     */
    private function stopNotice(RecurringLessonSlot $slot, ?string $from = null): ?string
    {
        $from ??= $slot->ends_on?->copy()->addDay()->toDateString();

        if ($from === null) {
            return null;
        }

        return ScheduleChangeNotice::describe(
            $slot->lessonsCarryingContent()
                ->where('starts_at', '>=', $from.' 00:00:00')
                ->with('schoolClass')
                ->get(),
            __('Horário terminado.'),
            restFollowNewSchedule: false,
        );
    }

    /**
     * A data opcional de «deixa de vigorar a partir de». Não anterior a hoje
     * (o passado não se reescreve) e posterior ao início do tempo (fechar na
     * véspera de um dia anterior ao próprio início violaria a CHECK da tabela).
     */
    private function stopEffectiveFrom(Request $request, RecurringLessonSlot $slot, string $timezone): ?string
    {
        $validated = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d']]);
        $effectiveFrom = $validated['effective_from'] ?? null;

        if (! is_string($effectiveFrom) || $effectiveFrom === '') {
            return null;
        }

        if ($effectiveFrom < CarbonImmutable::now($timezone)->startOfDay()->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => __('A data a partir da qual deixa de vigorar não pode ser anterior a hoje.'),
            ]);
        }

        if ($slot->starts_on !== null && $effectiveFrom <= $slot->starts_on->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => __('A data a partir da qual deixa de vigorar tem de ser posterior ao início deste horário (:date).', [
                    'date' => $slot->starts_on->toDateString(),
                ]),
            ]);
        }

        return $effectiveFrom;
    }

    /**
     * The `ends_on` that stops a slot from producing anything new from now
     * on, without ever moving it BEFORE its own `starts_on` (the table's
     * `ends_on >= starts_on` CHECK, and the same "clamp, never extend" spirit
     * as `ReviseRecurringLessonSlot`).
     *
     * - `starts_on` strictly in the future: closing exactly on `starts_on`
     *   is the earliest legal value and lets that single already-materialized
     *   day stand — nothing PAST it is ever produced.
     * - `starts_on` null (always in vigor) or already today/in the past:
     *   the slot is live RIGHT NOW, so `starts_on` itself cannot be the
     *   close date — that would either violate the CHECK (`starts_on` is
     *   null) or reopen a boundary that already produced real occurrences.
     *   "Today" (if it started exactly today) or "yesterday" — the same
     *   closesOn the requires-versioning branch already uses — stops future
     *   production while leaving what already happened alone.
     */
    private function closesOnForHardStop(RecurringLessonSlot $slot, string $timezone): string
    {
        if ($slot->starts_on !== null && ! $slot->isAlreadyInVigor($timezone)) {
            return $slot->starts_on->toDateString();
        }

        return $slot->startedExactlyToday($timezone)
            ? CarbonImmutable::now($timezone)->startOfDay()->toDateString()
            : CarbonImmutable::now($timezone)->startOfDay()->subDay()->toDateString();
    }

    public function materialize(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('create', [Lesson::class, $class]);
        $this->refuseDuringImpersonation($request);

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $this->materializeLessonsForRange->execute(
            $class,
            CarbonImmutable::parse($validated['from'], 'Europe/Lisbon'),
            CarbonImmutable::parse($validated['to'], 'Europe/Lisbon'),
            $this->user($request),
        );

        return back();
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
