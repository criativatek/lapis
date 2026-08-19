<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Services\Assessment\Progress\StudentProgressNarrative;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Evolução do Aluno: one student's year, read forwards.
 *
 * A VIEW, NOT A DOCUMENT (§3). Relatórios already produces the document — a
 * structured, editable, finalizable, exportable artifact with a snapshot. This
 * is the other thing entirely: a working screen a teacher opens on a Tuesday to
 * see how somebody is doing, changes the reading on, scrolls, and closes. It
 * feeds the report and never replaces it, which is why there is no export here
 * and no «guardar» anywhere on the page (§63).
 *
 * ONE CALL TO THE READ MODEL and nothing else, exactly as Estatística does.
 * Every academic figure was decided by the canonical services before this
 * controller ran; a number computed here, or in the browser, would be a second
 * opinion about a figure that already has one (§1).
 *
 * NO CAPABILITY OF ITS OWN, and no paywall inside it. `student_progress` has
 * been a BASE module in the entitlements seeder since it was written, and the
 * precedent this application already set for Estatística applies unchanged:
 * reading a class you already have results for is not a separate product from
 * having them. Splitting this view so that «desde o momento anterior» needed a
 * higher plan would be taking something away rather than adding it (§43, §44).
 *
 * THE STUDENT IS REACHED THROUGH THEIR ENROLMENT, never through a student id.
 * A result belongs to the (student, class) pair, and a student who has left
 * still has a year — reading them through the enrolment is what keeps that
 * history reachable instead of erasing it with today's roster (§58).
 */
class StudentProgressController extends Controller
{
    public function __construct(
        protected BuildStudentProgress $progress,
        protected StudentProgressNarrative $narrative,
    ) {}

    /**
     * Choose a class, then a student (§5).
     *
     * The classes offered are the ones this teacher already sees everywhere
     * else, through the same policy. Nothing here decides access; it asks.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        $classes = SchoolClass::query()
            ->with(['subject', 'academicYear'])
            ->withCount(['enrollments as active_enrollments_count' => fn ($query) => $query->active()])
            ->get()
            ->filter(fn (SchoolClass $class): bool => Gate::allows('view', $class))
            ->sortBy([['academic_year_id', 'desc'], ['label', 'asc']])
            ->values();

        return Inertia::render('student-progress/Index', [
            'classes' => $classes->map(fn (SchoolClass $class): array => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'students' => $class->active_enrollments_count,
                'has_profile' => $class->assessment_profile_version_id !== null,
            ])->all(),
        ]);
    }

    /**
     * The roll of one class, so a teacher can pick a name (§5).
     *
     * EVERYONE WHO WAS EVER IN IT, not only who is in it today. A student who
     * transferred out in March still has a year, and a picker built from the
     * active roster alone would make that year unreachable (§26, §58).
     */
    public function show(SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        $enrollments = Enrollment::query()
            ->where('class_id', $class->getKey())
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        return Inertia::render('student-progress/Class', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
            ],
            'students' => $enrollments->map(fn (Enrollment $enrollment): array => [
                'ulid' => $enrollment->ulid,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'class_number' => $enrollment->class_number,
                'is_current' => $enrollment->status->isCurrent(),
                'status_label' => $enrollment->status->label(),
                'is_late_entry' => (bool) $enrollment->is_late_entry,
            ])->all(),
        ]);
    }

    /**
     * One student's year.
     *
     * The reading is a QUERY, not a setting: choosing it changes what this page
     * shows and records nothing, exactly as «dados até» does on Estatística.
     */
    public function student(Request $request, SchoolClass $class, Enrollment $enrollment): Response
    {
        Gate::authorize('view', $class);

        // The enrolment has to belong to the class in the URL. Without this a
        // valid ulid from another class would be readable by anyone who can
        // read any class.
        abort_if((int) $enrollment->class_id !== (int) $class->getKey(), 404);

        $validated = $request->validate([
            'leitura' => ['nullable', 'in:continua,periodo'],
        ]);

        $reading = match ($validated['leitura'] ?? null) {
            'continua' => 'accumulated',
            'periodo' => 'period',
            default => null,
        };

        $progress = $this->progress->for($class, $enrollment, $reading);

        return Inertia::render('student-progress/Show', [
            ...$progress,
            // Deterministic, from the figures already in the payload. No AI is
            // involved in this view at all (§38, §82).
            'narrative' => $this->narrative->for($progress),
            // Where a teacher goes next, using the flows that already exist —
            // never a second form for the same thing (§64, §65, §66).
            'links' => [
                'records' => route('records.show', ['class' => $class->ulid]),
                'interventions' => route('interventions.index', ['turma' => $class->ulid]),
                'reports' => route('reports.index', ['type' => 'student']),
                'statistics' => route('results.statistics', ['class' => $class->ulid]),
            ],
        ]);
    }
}
