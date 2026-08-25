<?php

namespace App\Http\Controllers;

use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Horário do Professor» — the teacher's whole week, in one place.
 *
 * A STANDALONE DESTINATION, NOT A REPLACEMENT. `classes.schedule-setup` (the
 * picker reached from Turmas' own header) and the turma's own schedule editor
 * are untouched and still reached exactly where they always were: a turma
 * keeps its contextual shortcut to its own horário. What did not exist until
 * now is the answer to «como é a minha semana?» — every recurring block of
 * every turma, read together instead of one turma at a time.
 *
 * THIS PAGE WRITES NOTHING. It reads RecurringLessonSlot rows that already
 * exist and points at the two flows that create them (the PDF import, the
 * per-turma editor). It never materializes a Lesson: unlike the weekly
 * «Aulas e Sumários» view, which deliberately creates the occurrences of the
 * week it is showing, a horário is the RULE and not its occurrences, so there
 * is nothing here to bring into being. Being GET-only and side-effect free, it
 * needs no impersonation refusal — a support session may look at the week it
 * is being asked about, because looking changes nothing.
 */
class TeacherTimetableController extends Controller implements HasMiddleware
{
    public function __construct(protected CurrentOrganization $currentOrganization) {}

    /**
     * Gated by module:lessons, like classes.schedule-setup and the
     * timetable-imports.* routes it links to: RecurringLessonSlot is the same
     * capability, so this reuses that entitlement rather than inventing one.
     *
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        $teacher = $this->user($request);

        // The turmas this teacher teaches, asked ONCE. «Whose turma» comes
        // from SchoolClass::scopeTaughtBy — the same source of truth
        // ClassController::teacherClasses() uses — and the tenancy from the
        // model's own organization scope. Both halves of this page are built
        // from this one list, so the week and the manual list below it can
        // never disagree about which turmas they are about.
        $schoolClasses = SchoolClass::query()
            ->taughtBy($teacher)
            ->with('subject')
            ->orderBy('label')
            ->get();

        $timezone = $this->currentOrganization->get()->timezone;
        $today = CarbonImmutable::now($timezone)->toDateString();

        // Every recurring block of those turmas, and of no others. Narrowed by
        // class_id over the list already in hand, the same way
        // GenerateDataExport already reaches a teacher's dependent rows.
        //
        // Only the currently-active-or-future slot per schedule line: a
        // revision (ReviseRecurringLessonSlot) leaves the old, now-closed row
        // in place for Lessons already materialized from it to keep pointing
        // at, and without this filter it would show up here alongside the
        // version that replaced it.
        $slots = RecurringLessonSlot::query()
            ->whereIn('class_id', $schoolClasses->modelKeys())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->with('schoolClass.subject')
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (RecurringLessonSlot $slot) => [
                'ulid' => $slot->ulid,
                'day_of_week' => $slot->day_of_week,
                // The column is a `time`, so Eloquent hands back «HH:MM:SS».
                // Trimmed exactly as ClassController::show() already trims it
                // for the turma's own editor, so both read «09:30».
                'starts_at' => substr($slot->starts_at, 0, 5),
                'ends_at' => substr($slot->ends_at, 0, 5),
                'starts_on' => $slot->starts_on?->toDateString(),
                'ends_on' => $slot->ends_on?->toDateString(),
                'already_in_vigor' => $slot->isAlreadyInVigor($timezone),
                'school_class' => [
                    'ulid' => $slot->schoolClass->ulid,
                    'label' => $slot->schoolClass->label,
                ],
                'subject' => $slot->schoolClass->subject->name,
            ])
            ->values();

        // The manual path's list, identical to the one classes.schedule-setup
        // offers because it is the same query, not a second copy of it.
        $classes = $schoolClasses
            ->map(fn (SchoolClass $schoolClass) => [
                'ulid' => $schoolClass->ulid,
                'label' => $schoolClass->label,
                'subject' => $schoolClass->subject->name,
            ])
            ->values();

        return Inertia::render('timetable/Index', [
            'slots' => $slots,
            'classes' => $classes,
        ]);
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
