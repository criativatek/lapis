<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentStatus;
use Illuminate\Support\Facades\DB;

/**
 * The self-assessment fill-in form (§15) and its save logic, shared between the
 * teacher's authenticated flow (SelfAssessmentController) and the student's
 * signed-link flow (PublicSelfAssessmentController) — same questions, same
 * validation, same "never enters the calculation" guarantee either way. Only
 * who is recorded as having filled it in differs between the two callers.
 */
class SelfAssessmentRecorder
{
    public function __construct(
        protected SelfAssessmentTemplateProvider $templates,
        protected ClassResultsCalculator $calculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function formProps(SchoolClass $class, AcademicPeriod $period, Enrollment $enrollment): array
    {
        $template = $this->templates->forClass($class);
        $levels = Scale::where('name', 'Escala 1 a 5')->first()?->levels()->orderBy('sequence')->get(['id', 'code', 'label']) ?? collect();

        $existing = SelfAssessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('academic_period_id', $period->id)
            ->where('self_assessment_template_id', $template->id)
            ->with('responses')
            ->first();

        $answers = $existing === null
            ? collect()
            : $existing->responses->pluck('scale_level_id', 'self_assessment_question_id');

        // The calculated domain result for this student — shown beside the self
        // rating for comparison (§15), never merged into it.
        $calculated = collect($this->calculator->forPeriod($class, $period))
            ->firstWhere(fn ($row) => $row['enrollment']->id === $enrollment->id);
        $domainResults = $calculated === null
            ? collect()
            : collect($calculated['outcome']->domains)->pluck('normalizedValue', 'domainId');

        return [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label],
            'period' => ['ulid' => $period->ulid, 'label' => $period->label],
            'student' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
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
        ];
    }

    /**
     * @param  array{reflection?: string|null, answers?: array<int|string, mixed>}  $validated
     */
    public function save(
        SchoolClass $class,
        AcademicPeriod $period,
        Enrollment $enrollment,
        array $validated,
        SelfAssessmentFilledBy $filledBy,
    ): void {
        $template = $this->templates->forClass($class);
        $questionIds = $template->questions->pluck('id');
        $levelIds = Scale::where('name', 'Escala 1 a 5')->first()?->levels()->pluck('id') ?? collect();

        DB::transaction(function () use ($validated, $period, $enrollment, $template, $questionIds, $levelIds, $filledBy): void {
            $selfAssessment = SelfAssessment::updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $period->id,
                    'self_assessment_template_id' => $template->id,
                ],
                [
                    'status' => SelfAssessmentStatus::Submitted,
                    'filled_by' => $filledBy,
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
    }
}
