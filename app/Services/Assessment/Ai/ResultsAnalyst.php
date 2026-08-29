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
 * «Analisar a avaliação com IA» — a reading of a period's Resultados, in words.
 *
 * WHY IT IS NOT `ClassAnalyst` WITH ANOTHER PROMPT. The two read different
 * things, from different read models, on different screens, and answer
 * different questions. `ClassAnalyst` is handed `BuildClassStatistics` — the
 * distribution across the scale, the success rate, the movement between
 * periods — and answers «como está a turma». This is handed the RESULTS TABLE:
 * per-domain figures straight off `ClassResultsCalculator::forPeriod()`, the
 * coverage warnings the engine raised, the self-assessments, the decisions the
 * teacher has already taken. It answers «como correu esta avaliação, e onde é
 * que a evidência não chega». Merging them would mean one prompt trying to hold
 * both, and one capability a school could not buy separately.
 *
 * IT INTERPRETS, IT DOES NOT COMPUTE. Every figure it sends was decided by the
 * calculation engine and rendered on the screen the teacher pressed the button
 * on. `ResultsAnalysisContext` is handed the controller's own finished payload
 * and selects from it; the only arithmetic in this whole feature is counting
 * how many rows have a result and how many carry a coverage warning, which is
 * what makes «a evidência ainda é escassa» a statement the model can support.
 *
 * A READING, NEVER A WRITE. This class has no relationship to any model it
 * could change. It takes an array, returns six blocks of text, and stops. There
 * is no endpoint anywhere that accepts a `ResultsAnalysis` back, and the object
 * deliberately carries no id, key or verb that one could be built around. A
 * grade changes when a teacher changes it, in the form that already exists.
 *
 * EVERYTHING ELSE BELONGS TO `AiGateway` (ADR-0007): the entitlement on
 * `ai_assessment`, the provider, the rate limit, the daily and monthly quotas,
 * the institutional pool, the privacy assertion over the payload, and the
 * `ai_usage_events` row. None of it is repeated here.
 *
 * THE GATEWAY DOES NOT VALIDATE WHAT COMES BACK, and says so (contract §3).
 * `ResultsAnalysisParser` is this feature's own guard: an answer that is not a
 * usable set of blocks is refused rather than shown under headings that promise
 * them.
 */
class ResultsAnalyst
{
    /**
     * Below this many students with a result, no request is made at all.
     *
     * A CEILING ON HARM, NOT ON COST. One or two results is not an assessment
     * to read; asking a model to describe them produces a confident paragraph
     * about a period that has barely started, and the teacher pays for it in
     * both senses. Refusing here — before the gateway, before the quota — is
     * the honest answer, and the screen says which.
     */
    public const MINIMUM_STUDENTS_WITH_RESULT = 3;

    public function __construct(
        protected AiGateway $gateway,
        protected AiPayloadSanitizer $sanitizer,
        protected AuditLog $audit,
    ) {}

    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable(AiCapability::Assessment);
    }

    /**
     * `plan`, or one of the provider slugs (`off`, `credential_missing`,
     * `model_missing`, `endpoint_missing`, `unknown_driver`,
     * `fake_in_production`). Null when available.
     */
    public function unavailableReason(): ?string
    {
        return $this->gateway->unavailableReason(AiCapability::Assessment);
    }

    /**
     * Whether this particular period has enough in it to be worth reading.
     *
     * SEPARATE FROM `isAvailable()`, because they are different answers to
     * different people. «O seu plano não inclui» is for the school; «ainda não
     * há resultados suficientes» is for the teacher, is temporary, and fixes
     * itself the moment they correct another instrument.
     *
     * @param  array<string, mixed>  $payload  exactly what `ResultsController::show()` renders.
     */
    public function hasEnoughEvidence(array $payload): bool
    {
        return $this->studentsWithResult($payload) >= self::MINIMUM_STUDENTS_WITH_RESULT;
    }

    /**
     * @param  array<string, mixed>  $payload  exactly what `ResultsController::show()` renders.
     *
     * @throws AiUnavailable when the plan does not include it, or nothing is configured
     * @throws AiQuotaExceeded when a ceiling has been reached
     * @throws AiRequestFailed when there is too little to read, or when the engine
     *                         refuses, errors, times out, or answers with nothing usable
     */
    public function analyse(SchoolClass $class, array $payload, User $author): ResultsAnalysis
    {
        if (! $this->hasEnoughEvidence($payload)) {
            throw AiRequestFailed::unusableAnswer('too few students have a result in this period');
        }

        // The one place a class becomes a context, and the only place the
        // subject and grade level are read off the model. Nothing else about
        // the class — its label, its ulid, its teachers — is looked at.
        $context = ResultsAnalysisContext::build(
            $payload,
            $class->subject->name,
            $class->grade_level === null ? null : (string) $class->grade_level,
        );

        $sanitised = $context->toPayload($this->sanitizer);

        $answer = $this->gateway->ask(new AiAsk(
            useCase: AiUseCase::AssessmentAnalysis,
            instruction: ResultsAnalysisPrompt::text(),
            content: $sanitised,
            promptVersion: ResultsAnalysisPrompt::VERSION,
            // The class and period this reading is OF, as a hash — so two
            // readings of the same results are recognisable in
            // `ai_usage_events` without the events carrying anything about the
            // class.
            subjectHash: hash('sha256', $class->ulid.'|'.$this->periodKey($payload)),
        ), $author);

        $analysis = ResultsAnalysisParser::parse($answer->text);

        $this->record($class, $author, count($context->fields()), $sanitised->wasPseudonymised(), $analysis !== null, $answer->provider, $answer->model);

        if ($analysis === null) {
            throw AiRequestFailed::unusableAnswer('no analysis could be parsed from the answer');
        }

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function studentsWithResult(array $payload): int
    {
        $rows = $payload['rows'] ?? null;

        if (! is_iterable($rows)) {
            return 0;
        }

        $count = 0;

        foreach ($rows as $row) {
            if (is_array($row) && ($row['has_value'] ?? false) === true) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Which period the payload is showing, for the subject hash only.
     *
     * THE ULID, NOT THE LABEL. «1.º Período» is the same string in every class
     * in the country; the ulid is what makes two readings of the same period
     * recognisable as the same, and it never leaves this method — the hash is
     * one-way and the ulid itself is never sent anywhere.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function periodKey(array $payload): string
    {
        $periods = $payload['periods'] ?? null;

        if (! is_iterable($periods)) {
            return '';
        }

        foreach ($periods as $period) {
            $period = is_array($period) ? $period : null;

            if ($period !== null && ($period['selected'] ?? false) === true) {
                return (string) ($period['ulid'] ?? '');
            }
        }

        return '';
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
     */
    protected function record(
        SchoolClass $class,
        User $author,
        int $fieldCount,
        bool $pseudonymised,
        bool $parsed,
        string $provider,
        string $model,
    ): void {
        $this->audit->record(
            'results.ai_assessment_analysis_requested',
            $class,
            $author,
            summary: 'Análise com IA dos resultados do período.',
            properties: [
                'provider' => $provider,
                'model' => $model,
                'field_count' => $fieldCount,
                'parsed' => $parsed,
                'prompt_version' => ResultsAnalysisPrompt::VERSION,
                // Whether the central pseudonymiser actually replaced something
                // on the way out. Recorded because it is the claim this feature
                // makes to the teacher, and a trail that records the claim is
                // what makes it auditable.
                'pseudonymised' => $pseudonymised,
            ],
        );
    }
}
