<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\AssessmentProfile;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\User;
use App\Services\Assessment\SelfAssessmentCriteria;
use App\Services\Assessment\SelfAssessmentRecorder;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\SelfAssessmentRenewalException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-assessment (§15): the student reflects by domain, proposes an overall
 * level of their own and writes about the period — in an interview with the
 * teacher, or alone through a signed link. The comparison with the calculated
 * result happens afterwards, in Resultados, so that the two readings are made
 * independently. Nothing here feeds the engine.
 */
class SelfAssessmentController extends Controller
{
    /** How long a self-fill link stays usable once generated — long enough to
     *  cover a homework-style task shared via Teams, short enough not to linger. */
    protected const LINK_LIFETIME_DAYS = 7;

    public function __construct(
        protected SelfAssessmentRecorder $recorder,
        protected SelfAssessmentTemplateProvider $templates,
        protected SelfAssessmentCriteria $criteria,
        protected AuditLog $audit,
    ) {}

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

        // Asking a student who has left the class to self-assess would be asking
        // the wrong person; their earlier answers are untouched (§17).
        $enrollments = $class->activeEnrollments()->with('student.identity')->inClassOrder()->get();

        // Oldest round first, so keyBy() keeps the student's current
        // self-assessment: the row reads the status of the most recent round.
        $filled = $selected !== null
            ? SelfAssessment::query()
                ->where('academic_period_id', $selected->id)
                ->whereIn('enrollment_id', $enrollments->pluck('id'))
                ->oldestRoundFirst()
                ->get()->keyBy('enrollment_id')
            : collect();

        // Opening the screen derives the template the way opening a form does —
        // it follows the class unless answers keep it where it was — so what is
        // said about the criteria is what the form will ask.
        $template = $this->templates->forClass($class);

        $withEarlierRound = $selected !== null
            ? $this->recorder->enrollmentsWithEarlierRound($class, $selected, $template, $enrollments->pluck('id'))
            : [];

        return Inertia::render('self-assessments/Show', [
            'configuration' => $this->criteria->forTeacher($class, $template),
            'reflectionQuestions' => $this->recorder->reflectionQuestions($template),
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'periods' => $periods->map(fn (AcademicPeriod $academicPeriod) => [
                'ulid' => $academicPeriod->ulid,
                'label' => $academicPeriod->label,
                'selected' => $selected !== null && $academicPeriod->id === $selected->id,
            ]),
            'rows' => $enrollments->map(function (Enrollment $enrollment) use ($filled, $withEarlierRound) {
                /** @var SelfAssessment|null $selfAssessment */
                $selfAssessment = $filled->get($enrollment->id);

                return [
                    'enrollment_ulid' => $enrollment->ulid,
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                    'class_number' => $enrollment->class_number,
                    'status' => $selfAssessment?->status->value,
                    'status_label' => $selfAssessment?->status->label(),
                    'has_earlier_round' => isset($withEarlierRound[$enrollment->id]),
                ];
            }),
        ]);
    }

    public function edit(SchoolClass $class, string $period, Enrollment $enrollment): Response
    {
        Gate::authorize('view', $class);

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        $this->ensureEnrolledIn($class, $enrollment);

        $form = $this->recorder->formProps($class, $selected, $enrollment);

        return Inertia::render('self-assessments/Edit', [
            ...$form,
            // What the class is ACTUALLY assessed by, and what THIS form was
            // built from — which are not always the same thing. Only for the
            // teacher: the student's signed-link form gets the questions, each
            // naming the scale it is asked on, and nothing about the profile
            // or its versions.
            'configuration' => $this->criteria->forTeacher($class, $this->templates->forClass($class)),
        ]);
    }

    /**
     * Start a new self-assessment with the class's current criteria, when the
     * one in use stayed behind because it already holds answers. Nothing that
     * was answered is changed or removed.
     */
    public function renew(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate(['period' => ['nullable', 'string', 'max:26']]);

        try {
            $template = $this->templates->renew($class, $this->user());
        } catch (SelfAssessmentRenewalException $exception) {
            return back()->withErrors(['renew' => $exception->getMessage()]);
        }

        $template->loadMissing('profileVersion.scale');
        $version = $template->profileVersion;
        $profile = $version === null ? null : AssessmentProfile::withTrashed()->whereKey($version->assessment_profile_id)->first();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Nova autoavaliação iniciada com os critérios atuais da turma: :criteria. As respostas anteriores ficam guardadas.', [
            'criteria' => "{$profile?->name} · versão {$version?->version_number} · {$version?->scale?->name}",
        ])]);

        return redirect()->route('self-assessments.show', array_filter([
            'class' => $class->ulid,
            'period' => $validated['period'] ?? null,
        ]));
    }

    /**
     * The wording of the reflection questions — the only part of a
     * self-assessment a teacher authors. Domains, weights and scale are the
     * class's criteria and are not edited here.
     */
    public function updateQuestions(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate([
            'prompts' => ['required', 'array'],
            'prompts.*' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->templates->rewordReflections($class, $validated['prompts'], $this->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Perguntas de reflexão atualizadas.')]);

        return back();
    }

    public function store(Request $request, SchoolClass $class, string $period, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $class);

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        $this->ensureEnrolledIn($class, $enrollment);

        $validated = $request->validate([
            // Scale answers by question, written answers by question. Every one
            // of them optional: an unanswered question stays unanswered (§12).
            'answers' => ['array'],
            'answers.*' => ['nullable', 'integer'],
            'texts' => ['array'],
            'texts.*' => ['nullable', 'string', 'max:5000'],
            // A number typed on a scale without levels (0–20, percentage, a
            // scale of the organization's own), as text: it is validated
            // against the scale of ITS question, not here.
            'numbers' => ['array'],
            'numbers.*' => ['nullable', 'string', 'max:32'],
            // The template the form was built from, and the wording it showed.
            'template' => ['nullable', 'string', 'max:26'],
            'revision' => ['nullable', 'string', 'max:64'],
        ]);

        $this->recorder->save($class, $selected, $enrollment, $validated, SelfAssessmentFilledBy::TeacherInterview);

        $this->audit->record(
            'self_assessment.recorded',
            $enrollment,
            $this->user(),
            "Autoavaliação registada — {$class->label}.",
            ['class_id' => $class->id, 'academic_period_id' => $selected->id, 'enrollment_id' => $enrollment->id],
        );

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

        // Asking a student who has left the class to self-assess would be asking
        // the wrong person; their earlier answers are untouched (§17).
        $enrollments = $class->activeEnrollments()->with('student.identity')->inClassOrder()->get();

        $filled = SelfAssessment::query()
            ->where('academic_period_id', $selected->id)
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->oldestRoundFirst()
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
                    'status' => $selfAssessment?->status->value,
                    'link' => URL::temporarySignedRoute('self-assessments.public.edit', $expiresAt, [
                        'classUlid' => $class->ulid,
                        'periodUlid' => $selected->ulid,
                        'enrollmentUlid' => $enrollment->ulid,
                    ]),
                ];
            }),
        ]);
    }

    /**
     * The student must be one of THIS class's. The enrollment in the URL is
     * resolved inside the organization, not inside the class: without this, a
     * teacher of one class could open — and save — the self-assessment of a
     * student of another class of the same organization, under this class's
     * template and period.
     */
    protected function ensureEnrolledIn(SchoolClass $class, Enrollment $enrollment): void
    {
        abort_unless((int) $enrollment->class_id === (int) $class->id, 404);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
