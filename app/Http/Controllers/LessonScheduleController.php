<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Actions\Lessons\ReviseRecurringLessonSlot;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\RecurringLessonSlotRequest;
use App\Models\Lesson;
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

class LessonScheduleController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected MaterializeLessonsForRange $materializeLessonsForRange,
        protected ReviseRecurringLessonSlot $reviseRecurringLessonSlot,
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
        RecurringLessonSlot::create($request->safe()->except('effective_from'));

        return back();
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

        if (! $recurringLessonSlot->requiresVersioning($timezone)) {
            $recurringLessonSlot->update($request->safe()->except('class_id', 'effective_from'));

            return back();
        }

        $validated = $request->safe();

        $this->reviseRecurringLessonSlot->execute($recurringLessonSlot, $validated['effective_from'], [
            'day_of_week' => $validated['day_of_week'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'ends_on' => $validated['ends_on'],
        ]);

        return back();
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

            // A future slot that already has Lessons materialized ahead of
            // time (materialize() called for a future range): hard-deleting
            // it would either hit the FK's nullOnDelete/restrict behaviour or,
            // worse, silently orphan materialized data. Close it at its own
            // starts_on instead — the earliest value that still satisfies the
            // table's `ends_on >= starts_on` check, since today < starts_on
            // here rules out today - 1.
            $recurringLessonSlot->update(['ends_on' => $recurringLessonSlot->starts_on]);

            return back();
        }

        // Requires versioning: never delete. Close it as of today, locked
        // the same way ReviseRecurringLessonSlot locks a revise, so a
        // concurrent edit and a concurrent removal cannot race.
        return DB::transaction(function () use ($recurringLessonSlot, $timezone): RedirectResponse {
            /** @var RecurringLessonSlot $locked */
            $locked = RecurringLessonSlot::query()
                ->whereKey($recurringLessonSlot->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // The only way to reach this branch with starts_on exactly today
            // is with at least one Lesson already under it (requiresVersioning()
            // routed the Lesson-free case to the hard-delete branch above) —
            // so closing "today" itself is always correct here, and always
            // satisfies ends_on >= starts_on since the two are equal. Every
            // other in-vigor starts_on is strictly in the past, where
            // "yesterday" is the usual close.
            $closesOn = $locked->startedExactlyToday($timezone)
                ? CarbonImmutable::now($timezone)->startOfDay()->toDateString()
                : CarbonImmutable::now($timezone)->startOfDay()->subDay()->toDateString();

            $locked->update(['ends_on' => $closesOn]);

            return back();
        });
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
