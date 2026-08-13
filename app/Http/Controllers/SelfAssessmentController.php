<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\User;
use App\Services\Assessment\SelfAssessmentRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-assessment (§15): the student reflects by domain, in an interview with the
 * teacher, and the answers are shown beside the calculated result — compared,
 * never summed (§15). Nothing here feeds the engine.
 */
class SelfAssessmentController extends Controller
{
    /** How long a self-fill link stays usable once generated — long enough to
     *  cover a homework-style task shared via Teams, short enough not to linger. */
    protected const LINK_LIFETIME_DAYS = 7;

    public function __construct(protected SelfAssessmentRecorder $recorder) {}

    public function index(): Response
    {
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
            ]);

        return Inertia::render('self-assessments/Index', ['classes' => $classes]);
    }

    public function show(SchoolClass $class, ?string $period = null): Response
    {
        Gate::authorize('view', $class);

        $periods = AcademicPeriod::where('academic_year_id', $class->academic_year_id)->orderBy('sequence')->get();
        $selected = $period !== null ? $periods->firstWhere('ulid', $period) : $periods->first();

        $enrollments = $class->enrollments()->with('student.identity')->orderBy('class_number')->get();

        $filled = $selected !== null
            ? SelfAssessment::query()
                ->where('academic_period_id', $selected->id)
                ->whereIn('enrollment_id', $enrollments->pluck('id'))
                ->get()->keyBy('enrollment_id')
            : collect();

        return Inertia::render('self-assessments/Show', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'periods' => $periods->map(fn (AcademicPeriod $academicPeriod) => [
                'ulid' => $academicPeriod->ulid,
                'label' => $academicPeriod->label,
                'selected' => $selected !== null && $academicPeriod->id === $selected->id,
            ]),
            'rows' => $enrollments->map(function (Enrollment $enrollment) use ($filled) {
                /** @var SelfAssessment|null $selfAssessment */
                $selfAssessment = $filled->get($enrollment->id);

                return [
                    'enrollment_ulid' => $enrollment->ulid,
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                    'class_number' => $enrollment->class_number,
                    'status' => $selfAssessment?->status->value,
                    'status_label' => $selfAssessment?->status->label(),
                ];
            }),
        ]);
    }

    public function edit(SchoolClass $class, string $period, Enrollment $enrollment): Response
    {
        Gate::authorize('view', $class);

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        return Inertia::render('self-assessments/Edit', $this->recorder->formProps($class, $selected, $enrollment));
    }

    public function store(Request $request, SchoolClass $class, string $period, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $class);

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        $validated = $request->validate([
            'reflection' => ['nullable', 'string', 'max:5000'],
            'answers' => ['array'],
            'answers.*' => ['nullable', 'integer'],
        ]);

        $this->recorder->save($class, $selected, $enrollment, $validated, SelfAssessmentFilledBy::TeacherInterview);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Autoavaliação guardada.')]);

        return redirect()->route('self-assessments.show', ['class' => $class->ulid, 'period' => $selected->ulid]);
    }

    /**
     * A page meant to be projected or copied into a chat — one signed, no-login
     * link per student, so each can fill in their own self-assessment on their
     * own device without the teacher handing over theirs (§15). The signature
     * is the only access control: no password, no student account created.
     */
    public function links(SchoolClass $class, string $period): Response
    {
        Gate::authorize('view', $class);

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        $enrollments = $class->enrollments()->with('student.identity')->orderBy('class_number')->get();

        $filled = SelfAssessment::query()
            ->where('academic_period_id', $selected->id)
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get()->keyBy('enrollment_id');

        $expiresAt = now()->addDays(self::LINK_LIFETIME_DAYS);

        return Inertia::render('self-assessments/Links', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'period' => ['ulid' => $selected->ulid, 'label' => $selected->label],
            'expiresAt' => $expiresAt->toIso8601String(),
            'rows' => $enrollments->map(function (Enrollment $enrollment) use ($filled, $class, $selected, $expiresAt) {
                /** @var SelfAssessment|null $selfAssessment */
                $selfAssessment = $filled->get($enrollment->id);

                return [
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                    'status_label' => $selfAssessment?->status->label(),
                    'link' => URL::temporarySignedRoute('self-assessments.public.edit', $expiresAt, [
                        'classUlid' => $class->ulid,
                        'periodUlid' => $selected->ulid,
                        'enrollmentUlid' => $enrollment->ulid,
                    ]),
                ];
            }),
        ]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
