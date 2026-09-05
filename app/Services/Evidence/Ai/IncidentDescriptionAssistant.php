<?php

namespace App\Services\Evidence\Ai;

use App\Models\EvidenceKind;
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
 * «Aperfeiçoar redação» applied to a disciplinary occurrence description,
 * before it is ever saved (SUP-U8FMAE) — the Registos equivalent of
 * `App\Services\Reporting\Writing\ReportWritingAssistant`.
 *
 * THE SHAPE IS DELIBERATELY THE SAME: pseudonymise the roster the teacher could
 * be naming → protect every figure behind a lettered marker → sanitise as a
 * third barrier → ask the gateway → guard the answer → rehydrate. It returns a
 * proposal and WRITES NOTHING — there is no `EvidenceRecord` yet for this call
 * to touch, and even if there were, this class would still not touch it. The
 * teacher accepts by replacing the text in their own, still-unsubmitted form.
 *
 * SAME CAPABILITY AS RELATÓRIOS (`ai_reports`), BY PRODUCT DECISION. No new
 * entitlement, no change to plan composition — see `AiUseCase::capability()`.
 */
class IncidentDescriptionAssistant
{
    public function __construct(
        protected AiGateway $gateway,
        protected AiPayloadSanitizer $sanitizer,
        protected IncidentRewriteGuard $guard,
        protected AuditLog $audit,
    ) {}

    /** Whether the button may be shown at all. */
    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable(AiCapability::Reports);
    }

    /**
     * Why it is not available, as a slug the screen can turn into a sentence.
     *
     * Same two-word vocabulary Relatórios uses (`plan` | `provider`) for the
     * same reason: a teacher cannot act on the difference between «no engine
     * configured» and «no credential», only on whether it is a plan question or
     * an installation question (§41).
     */
    public function unavailableReason(): ?string
    {
        $reason = $this->gateway->unavailableReason(AiCapability::Reports);

        return match (true) {
            $reason === null => null,
            $reason === 'plan' => 'plan',
            default => 'provider',
        };
    }

    /** Whether this kind of record may be reworded at all — only Incident. */
    public function mayRewrite(?string $kind, string $draft): bool
    {
        return $kind === EvidenceKind::Incident->value && trim($draft) !== '';
    }

    /**
     * Ask for a draft occurrence description to be said better.
     *
     * @throws AiUnavailable when the plan does not include it, or nothing is configured
     * @throws AiQuotaExceeded when a ceiling has been reached
     * @throws AiRequestFailed when the engine refuses, errors or times out
     */
    public function suggest(SchoolClass $class, string $draft, User $author): IncidentRewriteSuggestion
    {
        $current = trim($draft);

        if ($current === '') {
            throw AiRequestFailed::unusableAnswer('there is no draft to improve');
        }

        $limit = (int) config('lapis.ai.max_characters');

        if (mb_strlen($current) > $limit) {
            throw AiRequestFailed::unusableAnswer('the draft is longer than '.$limit.' characters');
        }

        $names = IncidentPseudonymMap::forClass($class);
        $pseudonymised = $names->apply($current);

        if (! $names->coversEverythingIn($pseudonymised)) {
            throw AiRequestFailed::unusableAnswer('a known name survived pseudonymisation');
        }

        $facts = IncidentProtectedFacts::extract($pseudonymised);

        // The third barrier, exactly as in Relatórios: digits are already gone
        // by the time this runs, so the sanitiser's number rules cannot fire on
        // a marker, and what it adds is the non-numeric identifiers neither
        // `IncidentPseudonymMap` nor `IncidentProtectedFacts` has a rule for.
        $payload = $this->sanitizer->sanitise($facts->redacted);

        $answer = $this->gateway->ask(new AiAsk(
            useCase: AiUseCase::EvidenceDescriptionRewrite,
            instruction: IncidentRewritePrompt::text(),
            content: $payload,
            promptVersion: IncidentRewritePrompt::VERSION,
            // No record id exists yet — the hash is of the class and the
            // pseudonymised, redacted draft, so repeated attempts at the same
            // paragraph are recognisable without the events carrying it.
            subjectHash: hash('sha256', $class->ulid.'|'.$payload->text),
        ), $author);

        $normalised = $this->guard->normalise($answer->text, $payload->text);
        $verdict = $this->guard->inspect($facts, $normalised);

        $text = $verdict->acceptable
            ? $names->rehydrate($facts->restore($normalised))
            : null;

        $suggestion = new IncidentRewriteSuggestion(
            current: $current,
            text: $text,
            verdict: $verdict,
            promptVersion: IncidentRewritePrompt::VERSION,
            provider: $answer->provider,
            model: $answer->model,
            pseudonymised: ! $names->isEmpty() && $pseudonymised !== $current,
        );

        $this->record($class, $suggestion, $author, $payload->text, $normalised, [
            'provider' => $answer->provider,
            'model' => $answer->model,
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
            'latency_ms' => $answer->durationMilliseconds,
        ]);

        return $suggestion;
    }

    /**
     * The trail. No text, only hashes — same reasoning as
     * `ReportWritingAssistant::record()`.
     *
     * @param  array<string, mixed>  $metrics
     */
    protected function record(
        SchoolClass $class,
        IncidentRewriteSuggestion $suggestion,
        User $author,
        string $sent,
        string $received,
        array $metrics,
    ): void {
        $this->audit->record(
            'evidence.rewrite_suggested',
            $class,
            $author,
            summary: 'Sugestão de redação para uma ocorrência disciplinar em «'.$class->label.'».',
            properties: [
                ...$metrics,
                'class_ulid' => $class->ulid,
                'kind' => EvidenceKind::Incident->value,
                'prompt_version' => $suggestion->promptVersion,
                'pseudonymised' => $suggestion->pseudonymised,
                'accepted_by_guard' => $suggestion->verdict->acceptable,
                'rejection_reason' => $suggestion->verdict->reason,
                'rejection_detail' => $suggestion->verdict->detail,
                'input_hash' => hash('sha256', $sent),
                'output_hash' => hash('sha256', $received),
            ],
        );
    }
}
