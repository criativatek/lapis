<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentStatus;
use App\Models\User;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-assessment (§15): the student reflects by domain, in an interview with the
 * teacher, and the answers are shown beside the calculated result — compared,
 * never summed (§15). Nothing here feeds the engine.
 */
class SelfAssessmentController extends Controller
{
    public function __construct(
        protected SelfAssessmentTemplateProvider $templates,
        protected ClassResultsCalculator $calculator,
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
                    'name' => $enrollment->student->identity->display_name,
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

        $template = $this->templates->forClass($class);
        $levels = Scale::where('name', 'Escala 1 a 5')->first()?->levels()->orderBy('sequence')->get(['id', 'code', 'label']) ?? collect();

        $existing = SelfAssessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('academic_period_id', $selected->id)
            ->where('self_assessment_template_id', $template->id)
            ->with('responses')
            ->first();

        $answers = $existing === null
            ? collect()
            : $existing->responses->pluck('scale_level_id', 'self_assessment_question_id');

        // The calculated domain result for this student — shown beside the self
        // rating for comparison (§15), never merged into it.
        $calculated = collect($this->calculator->forPeriod($class, $selected))
            ->firstWhere(fn ($row) => $row['enrollment']->id === $enrollment->id);
        $domainResults = $calculated === null
            ? collect()
            : collect($calculated['outcome']->domains)->pluck('normalizedValue', 'domainId');

        return Inertia::render('self-assessments/Edit', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label],
            'period' => ['ulid' => $selected->ulid, 'label' => $selected->label],
            'student' => $enrollment->student->identity->display_name,
            'enrollmentUlid' => $enrollment->ulid,
            'levels' => $levels,
            'questions' => $template->questions->map(fn ($question) => [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'answer_level_id' => $answers->get($question->id),
                'calculated' => $question->domain_id === null ? null : $domainResults->get($question->domain_id),
            ]),
            'reflection' => $existing?->reflection,
            'status' => $existing?->status->value,
        ]);
    }

    public function store(Request $request, SchoolClass $class, string $period, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $class);

        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)->firstOrFail();

        $template = $this->templates->forClass($class);
        $questionIds = $template->questions->pluck('id');
        $levelIds = Scale::where('name', 'Escala 1 a 5')->first()?->levels()->pluck('id') ?? collect();

        $validated = $request->validate([
            'reflection' => ['nullable', 'string', 'max:5000'],
            'answers' => ['array'],
            'answers.*' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($validated, $selected, $enrollment, $template, $questionIds, $levelIds): void {
            $selfAssessment = SelfAssessment::updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $selected->id,
                    'self_assessment_template_id' => $template->id,
                ],
                [
                    'status' => SelfAssessmentStatus::Submitted,
                    'filled_by' => SelfAssessmentFilledBy::TeacherInterview,
                    'reflection' => $validated['reflection'] ?? null,
                    'submitted_at' => now(),
                ],
            );

            foreach ($validated['answers'] ?? [] as $questionId => $levelId) {
                // Only real questions of this template, and only levels of the
                // referenced scale — never an arbitrary id.
                if (! $questionIds->contains((int) $questionId) || ($levelId !== null && ! $levelIds->contains((int) $levelId))) {
                    continue;
                }

                $selfAssessment->responses()->updateOrCreate(
                    ['self_assessment_question_id' => (int) $questionId],
                    ['scale_level_id' => $levelId === null ? null : (int) $levelId],
                );
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Autoavaliação guardada.')]);

        return redirect()->route('self-assessments.show', ['class' => $class->ulid, 'period' => $selected->ulid]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
