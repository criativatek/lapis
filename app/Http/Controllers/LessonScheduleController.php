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
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\ValidationException;

class LessonScheduleController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected MaterializeLessonsForRange $materializeLessonsForRange,
        protected ReviseRecurringLessonSlot $reviseRecurringLessonSlot,
        protected LinkSplitLessonSlot $linkSplitLessonSlot,
        protected ReconcileLessonsWithSlotValidity $reconcileLessons,
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
            // Turma primeiro, tempo depois: uma materialização da mesma turma
            // espera por esta edição em vez de ler a vigência a meio.
            SchoolClass::query()->whereKey($recurringLessonSlot->class_id)->lockForUpdate()->firstOrFail();

            $this->applyUpdate($request, $recurringLessonSlot, $timezone, $validated);

            return $this->reconciled(back(), $recurringLessonSlot->class_id);
        });
    }

    private function applyUpdate(
        RecurringLessonSlotRequest $request,
        RecurringLessonSlot $recurringLessonSlot,
        string $timezone,
        ValidatedInput $validated,
    ): void {
        if (! $recurringLessonSlot->requiresVersioning($timezone)) {
            DB::transaction(function () use (
                $recurringLessonSlot,
                $request,
                $timezone,
                $validated,
            ): void {
                /** @var RecurringLessonSlot $locked */
                $locked = RecurringLessonSlot::query()
                    ->whereKey($recurringLessonSlot->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->requiresVersioning($timezone)) {
                    $effectiveFrom = $validated['effective_from'] ?? null;

                    if (! is_string($effectiveFrom) || trim($effectiveFrom) === '') {
                        throw ValidationException::withMessages([
                            'effective_from' => __(
                                'Este tempo adquiriu histórico durante a gravação; indique a partir de quando a alteração passa a vigorar.',
                            ),
                        ]);
                    }

                    $revised = $this->reviseRecurringLessonSlot->execute($locked, $effectiveFrom, [
                        'class_group_id' => $validated['class_group_id'] ?? null,
                        'day_of_week' => $validated['day_of_week'],
                        'starts_at' => $validated['starts_at'],
                        'ends_at' => $validated['ends_at'],
                        'ends_on' => $validated['ends_on'],
                    ]);
                    $this->linkIfRequested($request, $revised);

                    return;
                }

                $locked->update($validated->except('class_id', 'effective_from', 'same_lesson_as'));
                $this->linkIfRequested($request, $locked);
                $locked->lessons()
                    ->where('status', LessonStatus::Preparation)
                    ->whereDoesntHave('summary')
                    ->whereDoesntHave('plan')
                    ->update(['class_group_id' => $locked->class_group_id]);
            });

            return;
        }

        // A MESMA REVERIFICAÇÃO QUE O RAMO COM BLOQUEIO JÁ FAZIA, e que faltava
        // aqui. `effective_from` é exigido por `rules()`, que corre antes das
        // regras `after()`; entre esse instante e este, o slot pode ter
        // adquirido histórico (uma aula gravada noutro separador) e passado a
        // exigir versionamento sem que o pedido traga a data. Sem esta guarda, o
        // que o professor via era um TypeError — «Argument #2 must be of type
        // string, null given» — em vez da pergunta que falta responder.
        $effectiveFrom = $validated['effective_from'] ?? null;

        if (! is_string($effectiveFrom) || trim($effectiveFrom) === '') {
            throw ValidationException::withMessages([
                'effective_from' => __(
                    'Este tempo adquiriu histórico durante a gravação; indique a partir de quando a alteração passa a vigorar.',
                ),
            ]);
        }

        $revised = $this->reviseRecurringLessonSlot->execute($recurringLessonSlot, $effectiveFrom, [
            // `?? null` e não a chave a seco: um cliente antigo — ou o
            // formulário de uma turma sem grupos, que nem desenha o campo — não
            // envia `class_group_id` de todo, e a ausência quer dizer
            // exatamente o mesmo que NULL: turma inteira.
            'class_group_id' => $validated['class_group_id'] ?? null,
            'day_of_week' => $validated['day_of_week'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'ends_on' => $validated['ends_on'],
        ]);
        $this->linkIfRequested($request, $revised);
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
        $hasLessons = $recurringLessonSlot->lessons()->exists();

        if (! $recurringLessonSlot->requiresVersioning($timezone)) {
            if (! $hasLessons) {
                $recurringLessonSlot->delete();

                return back();
            }

            // A slot that already has Lessons materialized under it — future
            // ones materialized ahead of time, or a not-yet-started slot with
            // no RELEVANT history but that already produced empty preparation
            // Lessons — never hard-deletes: that would either hit the FK's
            // nullOnDelete/restrict behaviour or, worse, silently orphan
            // materialized data. Close it instead, and LOCK THE CLASS FIRST
            // (same order `update()` and `reconciled()` already use) so a
            // concurrent edit of the same slot cannot form the opposite
            // waits-for edge and deadlock.
            return DB::transaction(function () use ($recurringLessonSlot, $timezone): RedirectResponse {
                SchoolClass::query()->whereKey($recurringLessonSlot->class_id)->lockForUpdate()->firstOrFail();

                /** @var RecurringLessonSlot $locked */
                $locked = RecurringLessonSlot::query()
                    ->whereKey($recurringLessonSlot->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->update(['ends_on' => $this->closesOnForHardStop($locked, $timezone)]);

                return $this->reconciled(back(), $locked->class_id);
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

            return $this->reconciled(back(), $locked->class_id);
        });
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
