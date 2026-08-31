<?php

namespace App\Services\Assessment\Ai;

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
 * «Analisar com IA» — a reading of a class's Estatística, in words.
 *
 * IT INTERPRETS, IT DOES NOT COMPUTE. Everything this class sends was already
 * decided by `BuildClassStatistics`, which itself only aggregates what the
 * calculation engine produced. Lapispro remains the source of truth for every
 * grade, average, weight, classification and rule; what the engine adds is a
 * sentence about figures it was handed. `ClassAnalysisPrompt` says so at
 * length, and `ClassAnalysisContext` makes it structurally true by sending
 * only finished numbers.
 *
 * A READING, NEVER A WRITE. This class has no relationship to any model it
 * could change: it takes a statistics array, returns a `ClassAnalysis` of four
 * text blocks, and stops. There is no endpoint anywhere in this branch that
 * accepts an analysis back, and `ClassAnalysis` deliberately carries no id,
 * key or verb that one could be built around. A grade changes when a teacher
 * changes it, through the form that already exists.
 *
 * EVERYTHING ELSE BELONGS TO `AiGateway` (ADR-0007): the entitlement on
 * `ai_pedagogical_analysis`, the provider, the rate limit, the daily and
 * monthly quotas, the privacy assertion over the payload, and the
 * `ai_usage_events` row. None of it is repeated here.
 *
 * THE GATEWAY DOES NOT VALIDATE WHAT COMES BACK, and says so (contract §3).
 * `ClassAnalysisParser` is this feature's own guard: an answer that is not
 * four usable blocks is refused rather than shown under a heading that
 * promises them.
 */
class ClassAnalyst
{
    public function __construct(
        protected AiGateway $gateway,
        protected AiPayloadSanitizer $sanitizer,
        protected AuditLog $audit,
    ) {}

    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable(AiCapability::PedagogicalAnalysis);
    }

    /**
     * `plan`, or one of the provider slugs (`off`, `credential_missing`,
     * `model_missing`, `endpoint_missing`, `unknown_driver`,
     * `fake_in_production`). Null when available.
     */
    public function unavailableReason(): ?string
    {
        return $this->gateway->unavailableReason(AiCapability::PedagogicalAnalysis);
    }

    /**
     * @param  array<string, mixed>  $statistics  exactly what `BuildClassStatistics::for()` returned.
     *
     * @throws AiUnavailable when the plan does not include it, or nothing is configured
     * @throws AiQuotaExceeded when a ceiling has been reached
     * @throws AiRequestFailed when the engine refuses, errors, times out, or answers with nothing usable
     */
    public function analyse(SchoolClass $class, array $statistics, User $author): ClassAnalysis
    {
        // The one place a class becomes a context, and the only place the
        // subject and grade level are read off the model. Nothing else about
        // the class — its label, its ulid, its teacher — is looked at.
        $context = ClassAnalysisContext::build(
            $statistics,
            $class->subject->name,
            $class->grade_level === null ? null : (string) $class->grade_level,
        );

        $payload = $context->toPayload($this->sanitizer);

        $answer = $this->gateway->ask(new AiAsk(
            useCase: AiUseCase::PedagogicalAnalysis,
            instruction: ClassAnalysisPrompt::text(),
            content: $payload,
            promptVersion: ClassAnalysisPrompt::VERSION,
            // The class and period this reading is OF, as a hash — so two
            // readings of the same class are recognisable in `ai_usage_events`
            // without the events carrying anything about the class.
            subjectHash: hash('sha256', $class->ulid.'|'.(string) ($statistics['selected_period']['id'] ?? '')),
        ), $author);

        $analysis = ClassAnalysisParser::parse($answer->text);

        $this->record($class, $author, $context->fields(), $payload->wasPseudonymised(), $analysis !== null, $answer->provider, $answer->model);

        if ($analysis === null) {
            throw AiRequestFailed::unparsableAnswer('no analysis could be parsed from the answer');
        }

        return $analysis;
    }

    /**
     * The business trail — distinct from, and never a substitute for, the
     * gateway's own `ai_usage_events` row (contract §7). Tokens, duration and
     * status live there; what belongs here is what a school would want to know
     * happened.
     *
     * NEITHER THE CONTEXT NOR THE ANALYSIS IS STORED — only their dimensions.
     * The context is pseudonymised, not anonymous, and a trail that kept it
     * would quietly become a second copy of the class's results living under a
     * different retention rule.
     *
     * @param  array<string, string>  $fields
     */
    protected function record(
        SchoolClass $class,
        User $author,
        array $fields,
        bool $pseudonymised,
        bool $parsed,
        string $provider,
        string $model,
    ): void {
        $this->audit->record(
            'results.ai_analysis_requested',
            $class,
            $author,
            summary: 'Análise pedagógica com IA da estatística da turma.',
            properties: [
                'provider' => $provider,
                'model' => $model,
                'field_count' => count($fields),
                'parsed' => $parsed,
                'prompt_version' => ClassAnalysisPrompt::VERSION,
                // Whether the central pseudonymiser actually replaced
                // something on the way out. Recorded because it is the claim
                // this feature makes to the teacher, and a trail that records
                // the claim is what makes it auditable.
                'pseudonymised' => $pseudonymised,
            ],
        );
    }
}
