<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AssessmentProfile;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use App\Support\Assessment\ImprovementPrompt;
use App\Support\Assessment\SelfAssessmentAnswerScale;
use App\Support\Assessment\SelfAssessmentWrites;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 *
 * HOW A QUESTION IS ANSWERED COMES FROM ITS SCALE (SelfAssessmentAnswerScale):
 * a level chosen from a closed list, or a number inside the scale's limits.
 * The form shows it and the server enforces it from the same description, and
 * a form that cannot be validated is refused whole — never half saved.
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
        $template->questions->load(['domain', 'scale']);
        $template->loadMissing('profileVersion.scale');

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
            // The template the form was built from. It comes back with the
            // answers, so that a form opened before the questions changed is
            // recognised for what it is, even if every id in it still exists.
            'template' => $template->ulid,
            // …and the wording it showed. A reflection the teacher rewords
            // while a form is open keeps its id: without this, an answer
            // written to the old sentence would be stored under the new one.
            'revision' => $this->revisionOf($template),
            // The criteria THIS template was derived from — which are not
            // necessarily the class's today, once the class has moved on and
            // answers kept the template where it was.
            'criteria' => $this->criteriaOf($template),
            // Whether those criteria are the class's today. «previous» is a
            // self-assessment the teacher has not yet restarted after the class
            // moved on: the student is told it keeps the criteria it began
            // with, and never that they are the class's.
            'criteriaState' => $this->criteriaState($template, $class),
            // The improvement question is worded for the calendar this class
            // actually has — «no próximo semestre», «no próximo período», or
            // nothing of the sort in the last moment of the year. Contextualised
            // here, on the way to the screen: the stored question, its role and
            // every answer given to it are untouched.
            'questions' => $this->questionRows($template, $responses, ImprovementPrompt::nextAfter($period)),
            // What an older self-assessment wrote in the single generic box that
            // §15 replaced. Shown as it was, and never edited into a question it
            // was not an answer to.
            'earlierReflection' => $existing?->reflection,
            'status' => $existing?->status->value,
            'earlierRounds' => $this->earlierRounds($class, $period, $enrollment, $template),
            // Read-only mode: the screens show what was saved and offer no save.
            'readOnly' => SelfAssessmentWrites::readOnly(),
        ];
    }

    /**
     * The wording of every question of the template, as one fingerprint: what
     * a form was built from. Ids alone do not say it — a reworded reflection
     * keeps its id.
     */
    public function revisionOf(SelfAssessmentTemplate $template): string
    {
        return sha1($template->questions
            ->sortBy('id')
            ->map(fn (SelfAssessmentQuestion $question): string => $question->id.':'.$question->prompt)
            ->implode("\n"));
    }

    /**
     * Whether this student already has a self-assessment for the period under
     * criteria that are no longer the template in use.
     */
    public function hasEarlierRound(SchoolClass $class, AcademicPeriod $period, Enrollment $enrollment, SelfAssessmentTemplate $active): bool
    {
        return $this->earlierRoundsQuery($class, $period, $active)
            ->where('enrollment_id', $enrollment->id)
            ->exists();
    }

    /**
     * The enrollments (of these) that have an earlier round for the period.
     *
     * @param  iterable<int>  $enrollmentIds
     * @return array<int, true>
     */
    public function enrollmentsWithEarlierRound(SchoolClass $class, AcademicPeriod $period, SelfAssessmentTemplate $active, iterable $enrollmentIds): array
    {
        $ids = $this->earlierRoundsQuery($class, $period, $active)
            ->whereIn('enrollment_id', $enrollmentIds)
            ->pluck('enrollment_id');

        $enrollmentsWith = [];

        foreach ($ids as $id) {
            $enrollmentsWith[(int) $id] = true;
        }

        return $enrollmentsWith;
    }

    /**
     * The written questions of the template, in the order the form asks them,
     * with their stored wording and whether anyone has already answered —
     * what the teacher needs to reword them.
     *
     * @return list<array{id: int, role: string, role_label: string, prompt: string, answered: bool}>
     */
    public function reflectionQuestions(SelfAssessmentTemplate $template): array
    {
        $written = $template->questions
            ->filter(fn (SelfAssessmentQuestion $question): bool => $question->role !== null && $question->answer_kind === 'text')
            ->sort(fn (SelfAssessmentQuestion $first, SelfAssessmentQuestion $second): int => $this->sortKey($first) <=> $this->sortKey($second));

        if ($written->isEmpty()) {
            return [];
        }

        $answered = SelfAssessmentResponse::query()
            ->whereIn('self_assessment_question_id', $written->modelKeys())
            ->whereNotNull('text_value')
            ->distinct()
            ->pluck('self_assessment_question_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $rows = [];

        foreach ($written as $question) {
            $rows[] = [
                'id' => (int) $question->id,
                'role' => (string) $question->role?->value,
                'role_label' => (string) $question->role?->label(),
                'prompt' => (string) $question->prompt,
                'answered' => in_array((int) $question->id, $answered, true),
            ];
        }

        return $rows;
    }

    /**
     * @param  array{answers?: array<int|string, mixed>, texts?: array<int|string, mixed>, numbers?: array<int|string, mixed>, template?: string|null, revision?: string|null}  $validated
     */
    public function save(
        SchoolClass $class,
        AcademicPeriod $period,
        Enrollment $enrollment,
        array $validated,
        SelfAssessmentFilledBy $filledBy,
    ): void {
        // Read-only mode (a containment for recovery): refused before anything
        // is locked or written, to the teacher and to the student link alike.
        if (SelfAssessmentWrites::readOnly()) {
            throw ValidationException::withMessages(['answers' => SelfAssessmentWrites::refusal()]);
        }

        DB::transaction(function () use ($class, $validated, $period, $enrollment, $filledBy): void {
            // Under the class's lock, the one the template provider derives and
            // retires under: the template cannot change between this check and
            // the write below.
            $template = $this->templates->answerableFor($this->templates->lockClass($class));

            $answers = $validated['answers'] ?? [];
            $texts = $validated['texts'] ?? [];
            $numbers = $validated['numbers'] ?? [];

            // A form built from questions that are no longer the class's — the
            // class moved to another profile version, or a new self-assessment
            // was started, while it was open — is refused WHOLE, out loud.
            // Saving it against the new template would drop every answer it
            // carries (they answer questions the new one does not have) and
            // still record a submitted, empty self-assessment. The template the
            // form says it was built from is checked as well: the ids of a new
            // template are new, but a form that names the wrong one must not be
            // saved even if, by some route, its ids happened to exist.
            $submitted = array_map('intval', [...array_keys($answers), ...array_keys($texts), ...array_keys($numbers)]);

            if ($template === null
                || (isset($validated['template']) && $validated['template'] !== $template->ulid)
                || (isset($validated['revision']) && ! hash_equals($this->revisionOf($template), (string) $validated['revision']))
                || array_diff($submitted, $template->questions->modelKeys()) !== []) {
                throw ValidationException::withMessages([
                    'answers' => __('As perguntas desta autoavaliação mudaram depois de o formulário ter sido aberto. Nada foi guardado: o formulário foi atualizado — responda de novo e guarde.'),
                ]);
            }

            /** @var Collection<int, SelfAssessmentQuestion> $questions */
            $questions = $template->questions->keyBy('id');
            $questions->each(fn (SelfAssessmentQuestion $question) => $question->loadMissing('scale'));
            $template->loadMissing('profileVersion');
            $decimals = (int) ($template->profileVersion->rounding_scale ?? 0);

            // EVERYTHING IS CHECKED BEFORE ANYTHING IS WRITTEN. A form with one
            // answer that cannot be accepted saves nothing at all — not even
            // the self-assessment that would hold the others.
            $scaleWrites = $this->validatedScaleAnswers($questions, $answers, $numbers, $decimals);

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

            foreach ($scaleWrites as $questionId => $write) {
                // A level OR a number, never both: the one that is not chosen is
                // cleared, so an answer can only ever be read one way.
                $selfAssessment->responses()->updateOrCreate(
                    ['self_assessment_question_id' => $questionId],
                    ['scale_level_id' => $write['level'], 'numeric_value' => $write['number']],
                );
            }

            foreach ($texts as $questionId => $text) {
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
     * Each scale answer, checked against the scale of its own question.
     *
     * @param  Collection<int, SelfAssessmentQuestion>  $questions
     * @param  array<int|string, mixed>  $answers  question id => level id
     * @param  array<int|string, mixed>  $numbers  question id => number as typed
     * @return array<int, array{level: int|null, number: string|null}> by question id
     *
     * @throws ValidationException
     */
    protected function validatedScaleAnswers(Collection $questions, array $answers, array $numbers, int $decimals): array
    {
        $errors = [];
        $writes = [];

        $questionIds = array_unique(array_map('intval', [...array_keys($answers), ...array_keys($numbers)]));

        foreach ($questionIds as $questionId) {
            $question = $questions->get($questionId);

            // Only real questions of this template, and only ones that take a
            // scale answer.
            if ($question === null || $question->answer_kind !== 'scale') {
                continue;
            }

            $answerScale = SelfAssessmentAnswerScale::for($question->scale, $decimals);
            $level = $answers[$questionId] ?? null;
            $typed = $numbers[$questionId] ?? null;
            $typedFilled = is_scalar($typed) && trim((string) $typed) !== '';

            switch ($answerScale->mode()) {
                case SelfAssessmentAnswerScale::MODE_LEVELS:
                    // A level of THIS question's own scale — never an arbitrary
                    // id, and never one belonging to some other scale. It used
                    // to be skipped in silence; an answer that is not saved
                    // must be said, or the form looks saved when it is not.
                    $allowed = array_column($answerScale->levels(), 'id');

                    if ($level !== null && ! in_array((int) $level, $allowed, true)) {
                        $errors["answers.{$questionId}"] = __('Escolhe um dos níveis da escala «:name».', ['name' => $answerScale->scaleName()]);
                    }

                    if ($typedFilled) {
                        $errors["numbers.{$questionId}"] = __('Esta pergunta responde-se escolhendo um nível.');
                    }

                    $writes[$questionId] = ['level' => $level === null ? null : (int) $level, 'number' => null];

                    break;

                case SelfAssessmentAnswerScale::MODE_NUMBER:
                    $problem = $answerScale->validate($typed);

                    if ($problem !== null) {
                        $errors["numbers.{$questionId}"] = $problem;
                    }

                    if ($level !== null) {
                        $errors["answers.{$questionId}"] = __('Esta pergunta responde-se com um valor numérico.');
                    }

                    $writes[$questionId] = ['level' => null, 'number' => $answerScale->normalise($typed)];

                    break;

                default:
                    // A scale that cannot be answered accepts nothing: an empty
                    // answer is the only one that is valid, and it is not written.
                    $message = __('Esta pergunta não pode ser respondida: :problem', ['problem' => $answerScale->teacherProblem()]);

                    if ($level !== null) {
                        $errors["answers.{$questionId}"] = $message;
                    }

                    if ($typedFilled) {
                        $errors["numbers.{$questionId}"] = $message;
                    }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $writes;
    }

    /**
     * The template's questions, in the order the form asks them.
     *
     * @param  Collection<int, SelfAssessmentResponse>  $responses
     * @return list<array<string, mixed>>
     */
    protected function questionRows(SelfAssessmentTemplate $template, Collection $responses, ?AcademicPeriod $nextPeriod = null): array
    {
        $decimals = (int) ($template->profileVersion->rounding_scale ?? 0);

        $ordered = $template->questions->sort(
            fn (SelfAssessmentQuestion $first, SelfAssessmentQuestion $second): int => $this->sortKey($first) <=> $this->sortKey($second),
        );

        $rows = [];

        foreach ($ordered as $question) {
            $response = $responses->get($question->id);
            $isScale = $question->answer_kind === 'scale';
            $answerScale = $isScale ? SelfAssessmentAnswerScale::for($question->scale, $decimals) : null;

            $rows[] = [
                'id' => (int) $question->id,
                'block' => $this->blockOf($question),
                'role' => $question->role?->value,
                // The domain this question is about, named — the other way a
                // question is identified, and the only one the per-domain
                // questions use.
                'domain' => $question->domain?->name,
                'prompt' => $question->role === SelfAssessmentQuestionRole::Improvement
                    ? ImprovementPrompt::forPeriod((string) $question->prompt, $nextPeriod)
                    : (string) $question->prompt,
                'answer_kind' => (string) $question->answer_kind,
                // Kept for the screens that only need the closed list.
                'levels' => $answerScale?->levels() ?? [],
                // How this question is answered — a level, a number, or not at
                // all — and the sentence that says so, from the scale itself.
                'answer' => $answerScale?->toPayload(),
                // Named, so that the form says which scale it is asked on.
                'scale' => $isScale ? $question->scale?->name : null,
                'answer_level_id' => $response?->scale_level_id,
                'answer_number' => $response?->numeric_value === null
                    ? null
                    : SelfAssessmentAnswerScale::trimZeros((string) $response->numeric_value),
                'answer_text' => $response?->text_value,
            ];
        }

        return $rows;
    }

    /**
     * What the template asks about and on which scale — read from the template's
     * own profile version, never from the class.
     *
     * @return array{scale: string|null, domains: list<array{name: string, weight: string}>}|null
     */
    protected function criteriaOf(SelfAssessmentTemplate $template): ?array
    {
        $version = $template->profileVersion;

        if ($version === null) {
            return null;
        }

        $domains = [];

        foreach ($version->domains()->with('domain')->get() as $profileDomain) {
            $domains[] = [
                'name' => (string) $profileDomain->domain->name,
                'weight' => SelfAssessmentAnswerScale::trimZeros((string) $profileDomain->weight_percent),
            ];
        }

        return ['scale' => $version->scale?->name, 'domains' => $domains];
    }

    /**
     * «current» when the template speaks in the criteria the class is assessed
     * by (or the class has none yet), «previous» when it was derived from
     * another version.
     */
    protected function criteriaState(SelfAssessmentTemplate $template, SchoolClass $class): string
    {
        if ($class->assessment_profile_version_id === null) {
            return 'current';
        }

        return $template->assessment_profile_version_id === $class->assessment_profile_version_id ? 'current' : 'previous';
    }

    /**
     * @return Builder<SelfAssessment>
     */
    protected function earlierRoundsQuery(SchoolClass $class, AcademicPeriod $period, SelfAssessmentTemplate $active): Builder
    {
        $query = SelfAssessment::query()
            ->where('academic_period_id', $period->id)
            ->whereHas('template', fn ($inner) => $inner->where('class_id', $class->id));

        // A template that is not saved (read-only mode, class without one) is
        // no round of its own: every saved self-assessment of the class is an
        // earlier one.
        return $active->exists
            ? $query->where('self_assessment_template_id', '!=', $active->id)
            : $query;
    }

    /**
     * What this student answered, for the same period, under OTHER templates of
     * the class — kept, read-only, with the criteria they were filled in with.
     * Teacher side only: the student's link says THAT there is one, not what it
     * held.
     *
     * @return list<array{submitted_at: string|null, criteria: string, answers: list<array{prompt: string, value: string|null}>}>
     */
    protected function earlierRounds(SchoolClass $class, AcademicPeriod $period, Enrollment $enrollment, SelfAssessmentTemplate $active): array
    {
        $earlier = $this->earlierRoundsQuery($class, $period, $active)
            ->where('enrollment_id', $enrollment->id)
            ->with(['template.questions.scale', 'template.profileVersion.scale', 'responses.scaleLevel'])
            ->orderByDesc('self_assessment_template_id')
            ->orderByDesc('id')
            ->get();

        $next = ImprovementPrompt::nextAfter($period);
        $rounds = [];

        foreach ($earlier as $selfAssessment) {
            $template = $selfAssessment->template;

            if ($template === null) {
                continue;
            }

            $version = $template->profileVersion;
            $profile = $version === null ? null : AssessmentProfile::withTrashed()->whereKey($version->assessment_profile_id)->first();

            /** @var Collection<int, SelfAssessmentResponse> $responses */
            $responses = $selfAssessment->responses->keyBy('self_assessment_question_id');
            $answers = [];

            $ordered = $template->questions->sort(
                fn (SelfAssessmentQuestion $first, SelfAssessmentQuestion $second): int => $this->sortKey($first) <=> $this->sortKey($second),
            );

            foreach ($ordered as $question) {
                $value = $this->readableValue($question, $responses->get($question->id));

                if ($value === null) {
                    continue;
                }

                $answers[] = [
                    'prompt' => $question->role === SelfAssessmentQuestionRole::Improvement
                        ? ImprovementPrompt::forPeriod((string) $question->prompt, $next)
                        : (string) $question->prompt,
                    'value' => $value,
                ];
            }

            $rounds[] = [
                'submitted_at' => $selfAssessment->submitted_at?->toIso8601String(),
                'criteria' => $version === null || $profile === null
                    ? __('Sem perfil')
                    : "{$profile->name} · versão {$version->version_number} · ".($version->scale->name ?? __('Sem escala')),
                'answers' => $answers,
            ];
        }

        return $rounds;
    }

    /**
     * One stored answer as text: «4 — Bom», «16», «80%», or what was written.
     */
    protected function readableValue(SelfAssessmentQuestion $question, ?SelfAssessmentResponse $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $level = $response->scaleLevel;

        if ($level !== null) {
            return (string) $level->label !== '' && (string) $level->label !== (string) $level->code
                ? $level->code.' — '.$level->label
                : (string) $level->code;
        }

        if ($response->numeric_value !== null) {
            $scale = SelfAssessmentAnswerScale::for($question->scale, 0);
            $readable = $scale->readable((string) $response->numeric_value);

            return $scale->isPercentage() ? $readable['label'] : $readable['code'];
        }

        $text = $response->text_value === null ? '' : trim($response->text_value);

        return $text === '' ? null : $text;
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
}
