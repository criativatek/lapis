<?php

namespace App\Services\Progress\Ai;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Gateway\AiAsk;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Ai\Gateway\AiQuotaExceeded;
use App\Services\Ai\Gateway\AiUseCase;
use App\Services\Audit\AuditLog;
use App\Support\Privacy\AiPayloadSanitizer;

/**
 * «Síntese de acompanhamento (IA)» — a reading of one student's Evolução.
 *
 * THE ONE READING IN THE PRODUCT THAT IS ABOUT A PERSON, and everything about
 * this class follows from that. The context is tighter than any other
 * (`FollowupContext` lists what is excluded and why); the prompt is longer
 * (`FollowupSynthesisPrompt` says what may not be said about a child); the
 * parser refuses an answer with nothing positive in it
 * (`FollowupSynthesisParser`); and the audit trail records that a synthesis was
 * requested about this enrolment, which is a thing a school may legitimately
 * need to be able to look up.
 *
 * IT SITS ON THE FACTS, NOT BESIDE THEM. Evolução do Aluno already computes
 * `factualAlerts` and `strengths` deterministically on every request, and those
 * are handed to this reading as the facts to work from. So the panel shows what
 * the system counted, and then — separately, labelled, and in the language of
 * possibility — what a model made of it. A teacher who distrusts the second can
 * still read the first, which is the whole of «distinguir facto de
 * interpretação» (§8).
 *
 * A READING, NEVER A WRITE. This class has no relationship to any model it
 * could change. It takes arrays and returns six blocks of text. There is no
 * endpoint that accepts a `FollowupSynthesis` back, and nothing on it that
 * would mean anything to one if there were. The strategy suggester next to it
 * on the same page is the same shape: a proposal a human either uses or
 * ignores, through a form that already exists.
 *
 * EVERYTHING ELSE BELONGS TO `AiGateway` (ADR-0007): the entitlement on
 * `ai_followup`, the provider, the rate limit, the quotas, the institutional
 * pool, the privacy assertion and the `ai_usage_events` row.
 */
class StudentFollowupSynthesist
{
    public function __construct(
        protected AiGateway $gateway,
        protected AiPayloadSanitizer $sanitizer,
        protected AuditLog $audit,
    ) {}

    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable(AiCapability::Followup);
    }

    /**
     * `plan`, or one of the provider slugs (`off`, `credential_missing`,
     * `model_missing`, `endpoint_missing`, `unknown_driver`,
     * `fake_in_production`). Null when available.
     */
    public function unavailableReason(): ?string
    {
        return $this->gateway->unavailableReason(AiCapability::Followup);
    }

    /**
     * Whether this student's record has enough in it to be worth reading.
     *
     * A RESULT OR A REGISTO OR AN INTERVENÇÃO — any one of the three is enough,
     * because any one of them is something to say. An enrolment with none of
     * them is a student who arrived last week, and a confident synthesis of an
     * empty record is the worst thing this feature could produce.
     *
     * @param  array<string, mixed>  $progress  exactly what `BuildStudentProgress::for()` returned.
     */
    public function hasEnoughEvidence(array $progress): bool
    {
        $headline = is_array($progress['headline'] ?? null) ? $progress['headline'] : [];

        if (($headline['value'] ?? null) !== null && $headline['value'] !== '') {
            return true;
        }

        $records = is_array($progress['records'] ?? null) ? $progress['records'] : [];
        $interventions = is_array($progress['interventions'] ?? null) ? $progress['interventions'] : [];

        return (int) ($records['total'] ?? 0) > 0 || (int) ($interventions['total'] ?? 0) > 0;
    }

    /**
     * @param  array<string, mixed>  $progress  exactly what `BuildStudentProgress::for()` returned.
     * @param  list<array<string, mixed>>  $factualAlerts  the panel's own deterministic attention sentences.
     * @param  list<array<string, mixed>>  $strengths  the panel's own deterministic progress sentences.
     *
     * @throws AiUnavailable when the plan does not include it, or nothing is configured
     * @throws AiQuotaExceeded when a ceiling has been reached
     * @throws AiRequestFailed when there is too little to read, or when the engine
     *                         refuses, errors, times out, or answers with nothing usable
     */
    public function synthesise(
        SchoolClass $class,
        Enrollment $enrollment,
        array $progress,
        array $factualAlerts,
        array $strengths,
        User $author,
    ): FollowupSynthesis {
        if (! $this->hasEnoughEvidence($progress)) {
            throw AiRequestFailed::unusableAnswer('this enrolment has no result, record or intervention yet');
        }

        // The one place a class becomes a context, and the only place the
        // subject and grade level are read off a model. Nothing else about the
        // class or the enrolment — labels, ulids, class numbers — is looked at.
        $context = FollowupContext::build(
            $progress,
            $factualAlerts,
            $strengths,
            $class->subject->name,
            $class->grade_level === null ? null : (string) $class->grade_level,
        );

        $payload = $context->toPayload($this->sanitizer);

        $answer = $this->gateway->ask(new AiAsk(
            useCase: AiUseCase::FollowupSynthesis,
            instruction: FollowupSynthesisPrompt::text(),
            content: $payload,
            promptVersion: FollowupSynthesisPrompt::VERSION,
            // Which enrolment this reading is OF, as a hash — so two syntheses
            // of the same student are recognisable in `ai_usage_events` without
            // the events carrying anything about the student.
            subjectHash: hash('sha256', $enrollment->ulid),
        ), $author);

        $synthesis = FollowupSynthesisParser::parse($answer->text);

        $this->record(
            $enrollment,
            $author,
            count($context->fields()),
            $payload->wasPseudonymised(),
            $synthesis !== null,
            $answer->provider,
            $answer->model,
        );

        if ($synthesis === null) {
            throw AiRequestFailed::unparsableAnswer('no synthesis could be parsed from the answer');
        }

        return $synthesis;
    }

    /**
     * The business trail — distinct from, and never a substitute for, the
     * gateway's own `ai_usage_events` row (contract §7).
     *
     * THE SUBJECT IS THE ENROLMENT, and this is the one AI trail in the product
     * that names a student at all. It is deliberate: a school asked «foi pedida
     * uma síntese de IA sobre o meu filho?» is entitled to an answer, and an
     * audit row with no subject cannot give one. The `audit_events` table is
     * already tenant-scoped and already holds subjects of exactly this kind.
     *
     * NEITHER THE CONTEXT NOR THE SYNTHESIS IS STORED — only their dimensions.
     * A trail that kept the text would become a second copy of an
     * interpretation about a child, living under a different retention rule.
     */
    protected function record(
        Enrollment $enrollment,
        User $author,
        int $fieldCount,
        bool $pseudonymised,
        bool $parsed,
        string $provider,
        string $model,
    ): void {
        $this->audit->record(
            'student-progress.ai_synthesis_requested',
            $enrollment,
            $author,
            summary: 'Síntese de acompanhamento com IA.',
            properties: [
                'provider' => $provider,
                'model' => $model,
                'field_count' => $fieldCount,
                'parsed' => $parsed,
                'prompt_version' => FollowupSynthesisPrompt::VERSION,
                // Whether the central pseudonymiser actually replaced something
                // on the way out. Recorded because it is the claim this feature
                // makes to the teacher, and a trail that records the claim is
                // what makes it auditable.
                'pseudonymised' => $pseudonymised,
            ],
        );
    }
}
