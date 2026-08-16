<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The self-assessment fill-in form (§15) and its save logic, shared between the
 * teacher's authenticated flow (SelfAssessmentController) and the student's
 * signed-link flow (PublicSelfAssessmentController) — same questions, same
 * validation, same "never enters the calculation" guarantee either way. Only
 * who is recorded as having filled it in differs between the two callers.
 *
 * NOTHING CALCULATED TRAVELS TO THIS FORM. What is being collected is the
 * student's own reading of the period, and a percentage shown beside the
 * question is an answer to copy: it turns a judgement into agreement with one.
 * The comparison is the point, and it is made afterwards, in Resultados, where
 * the two readings sit side by side and neither was written knowing the other.
 */
class SelfAssessmentRecorder
{
    /**
     * The order the form is read in. The first three are the blocks of §15; the
     * fourth is not a block at all — it holds any question identified neither by
     * a domain nor by a role, so that one never silently disappears from a
     * template somebody authored.
     *
     * @var array<string, int>
     */
    protected const BLOCK_ORDER = ['performance' => 1, 'reflection' => 2, 'work' => 3, 'other' => 4];

    public function __construct(protected SelfAssessmentTemplateProvider $templates) {}

    /**
     * @return array<string, mixed>
     */
    public function formProps(SchoolClass $class, AcademicPeriod $period, Enrollment $enrollment): array
    {
        $template = $this->templates->forClass($class);
        $template->questions->load('domain');

        $existing = SelfAssessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('academic_period_id', $period->id)
            ->where('self_assessment_template_id', $template->id)
            ->with('responses')
            ->first();

        /** @var Collection<int, SelfAssessmentResponse> $responses */
        $responses = $existing === null
            ? collect()
            : $existing->responses->keyBy('self_assessment_question_id');

        return [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label],
            'period' => ['ulid' => $period->ulid, 'label' => $period->label],
            'student' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
            'enrollmentUlid' => $enrollment->ulid,
            'questions' => $this->questionRows($template, $responses),
            // What an older self-assessment wrote in the single generic box that
            // §15 replaced. Shown as it was, and never edited into a question it
            // was not an answer to.
            'earlierReflection' => $existing?->reflection,
            'status' => $existing?->status->value,
        ];
    }

    /**
     * @param  array{answers?: array<int|string, mixed>, texts?: array<int|string, mixed>}  $validated
     */
    public function save(
        SchoolClass $class,
        AcademicPeriod $period,
        Enrollment $enrollment,
        array $validated,
        SelfAssessmentFilledBy $filledBy,
    ): void {
        $template = $this->templates->forClass($class);

        /** @var Collection<int, SelfAssessmentQuestion> $questions */
        $questions = $template->questions->keyBy('id');
        $levelIds = $this->levelIdsByScale($template);

        DB::transaction(function () use ($validated, $period, $enrollment, $template, $questions, $levelIds, $filledBy): void {
            $selfAssessment = SelfAssessment::updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $period->id,
                    'self_assessment_template_id' => $template->id,
                ],
                [
                    'status' => SelfAssessmentStatus::Submitted,
                    'filled_by' => $filledBy,
                    'submitted_at' => now(),
                ],
            );
            // `reflection` is deliberately not written here. The single generic
            // box became questions of its own, each stored as its own answer —
            // and what an older self-assessment left in that column stays there,
            // untouched, rather than being overwritten with a null.

            foreach ($validated['answers'] ?? [] as $questionId => $levelId) {
                $question = $questions->get((int) $questionId);

                // Only real questions of this template, and only ones that take
                // a scale answer.
                if ($question === null || $question->answer_kind !== 'scale') {
                    continue;
                }

                // A level of THIS question's own scale — never an arbitrary id,
                // and never one belonging to some other scale.
                $allowed = $question->scale_id === null ? [] : ($levelIds[$question->scale_id] ?? []);

                if ($levelId !== null && ! in_array((int) $levelId, $allowed, true)) {
                    continue;
                }

                $selfAssessment->responses()->updateOrCreate(
                    ['self_assessment_question_id' => $question->id],
                    ['scale_level_id' => $levelId === null ? null : (int) $levelId],
                );
            }

            foreach ($validated['texts'] ?? [] as $questionId => $text) {
                $question = $questions->get((int) $questionId);

                if ($question === null || $question->answer_kind !== 'text') {
                    continue;
                }

                $written = is_string($text) ? trim($text) : '';

                // Each written answer is its own row against its own question.
                // Two of them merged into one field would be one answer to two
                // questions, and neither could be read back (§10).
                $selfAssessment->responses()->updateOrCreate(
                    ['self_assessment_question_id' => $question->id],
                    ['text_value' => $written === '' ? null : $written],
                );
            }
        });
    }

    /**
     * The template's questions, in the order the form asks them.
     *
     * @param  Collection<int, SelfAssessmentResponse>  $responses
     * @return list<array<string, mixed>>
     */
    protected function questionRows(SelfAssessmentTemplate $template, Collection $responses): array
    {
        $levels = $this->levelsByScale($template);

        $ordered = $template->questions->sort(
            fn (SelfAssessmentQuestion $first, SelfAssessmentQuestion $second): int => $this->sortKey($first) <=> $this->sortKey($second),
        );

        $rows = [];

        foreach ($ordered as $question) {
            $response = $responses->get($question->id);
            $scaleId = $question->scale_id;

            $rows[] = [
                'id' => (int) $question->id,
                'block' => $this->blockOf($question),
                'role' => $question->role?->value,
                // The domain this question is about, named — the other way a
                // question is identified, and the only one the per-domain
                // questions use.
                'domain' => $question->domain?->name,
                'prompt' => (string) $question->prompt,
                'answer_kind' => (string) $question->answer_kind,
                'levels' => $question->answer_kind === 'scale' && $scaleId !== null ? ($levels[$scaleId] ?? []) : [],
                'answer_level_id' => $response?->scale_level_id,
                'answer_text' => $response?->text_value,
            ];
        }

        return $rows;
    }

    /**
     * @return array{int, int, int}
     */
    protected function sortKey(SelfAssessmentQuestion $question): array
    {
        return [
            self::BLOCK_ORDER[$this->blockOf($question)],
            // Zero for a per-domain question, which has no role: the domains
            // open their block and the overall judgement closes it.
            $question->role?->positionInBlock() ?? 0,
            (int) $question->sequence,
        ];
    }

    protected function blockOf(SelfAssessmentQuestion $question): string
    {
        if ($question->role !== null) {
            return $question->role->block();
        }

        // No role means it is a domain's question, which is the other way of
        // being identified. One that is neither is not read by anything here,
        // and goes last rather than off the screen.
        return $question->domain_id !== null ? 'performance' : 'other';
    }

    /**
     * Every level of every scale this template speaks in, ready for a select.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    protected function levelsByScale(SelfAssessmentTemplate $template): array
    {
        $scaleIds = $template->questions->pluck('scale_id')->filter()->unique()->values();

        if ($scaleIds->isEmpty()) {
            return [];
        }

        $byScale = [];

        foreach (ScaleLevel::whereIn('scale_id', $scaleIds)->orderBy('sequence')->get() as $level) {
            $byScale[(int) $level->scale_id][] = [
                'id' => (int) $level->id,
                'code' => (string) $level->code,
                'label' => (string) $level->label,
            ];
        }

        return $byScale;
    }

    /**
     * @return array<int, list<int>>
     */
    protected function levelIdsByScale(SelfAssessmentTemplate $template): array
    {
        $byScale = [];

        foreach ($this->levelsByScale($template) as $scaleId => $levels) {
            $ids = [];

            foreach ($levels as $level) {
                $ids[] = (int) $level['id'];
            }

            $byScale[$scaleId] = $ids;
        }

        return $byScale;
    }
}
