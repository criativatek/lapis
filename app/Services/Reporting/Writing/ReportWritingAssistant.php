<?php

namespace App\Services\Reporting\Writing;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionKey;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Gateway\AiAsk;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Ai\Gateway\AiQuotaExceeded;
use App\Services\Ai\Gateway\AiUseCase;
use App\Services\Audit\AuditLog;
use App\Services\Reporting\ReportCapabilities;
use App\Support\Privacy\AiPayloadSanitizer;

/**
 * «Aperfeiçoar redação» (§1, §4).
 *
 * THE DIRECTION OF THE ARROW IS THE WHOLE DESIGN:
 *
 *     dados canónicos → narrativa determinística → o professor edita
 *                     → a IA aperfeiçoa UMA secção → o professor decide
 *
 * and never dados → IA → relatório. Nothing in this class reads a statistic, a
 * result, a classification or a student record. It reads ONE section's text —
 * which the deterministic composers already wrote, or the teacher already typed
 * — and asks for the same thing said better. The engine cannot be the source of
 * a fact because it is never shown one (§17).
 *
 * WHAT LEAVES THE BUILDING, EXACTLY (§9, §40):
 *
 *   sent      the text of one section, with every figure replaced by a lettered
 *             marker and every name this application knows replaced by «Aluno
 *             A»; a fixed, versioned instruction; the mode the teacher picked.
 *
 *   not sent  the other sections, the class, the period, the roster, the
 *             statistics, the results, the classifications, the self-assessments,
 *             the records, the interventions, the school's identity, the
 *             teacher's name, and every figure and date in the text itself.
 *
 * THE ORDER OF OPERATIONS MATTERS AND IS NOT INTERCHANGEABLE. Pseudonyms first,
 * so the fact extractor never sees a name; markers second, so what goes out has
 * no digit in it at all; the guard third, comparing what was sent against what
 * came back while both are still in that form; and only then the values and the
 * names come home. Reversing any pair of those would let something real reach
 * the wire.
 *
 * IT RETURNS A PROPOSAL AND WRITES NOTHING (§19, §21). Accepting is a separate
 * act by the teacher, through the section editor that already exists, and it
 * touches `body` alone — `generated_body` stays the deterministic text, so
 * «restaurar texto automático» keeps meaning what it has always meant.
 *
 * MIGRATED ONTO `AiGateway` IN THE AI-COMPLETE SLICE. This class predates the
 * gateway: it used to resolve a provider itself, ask its own entitlement
 * question and leave nothing in `ai_usage_events`, so the single most-used AI
 * feature in the product was invisible to the meter that was supposed to answer
 * «what is the AI costing». Now the entitlement (`ai_reports`, with
 * `ai_assistance` still accepted), the provider, the rate limit, the quotas,
 * the institutional pool and the usage row all belong to the gateway.
 *
 * WHAT DID NOT MOVE, AND MUST NOT. `PseudonymMap`, `ProtectedFacts` and
 * `RewriteGuard` are this module's own and stay exactly where they are. The
 * gateway is explicit that it does not validate an answer (contract §3), and
 * what a good rewrite looks like — every protected fact still present, no digit
 * invented, no name resurrected — is a question only Relatórios can answer. The
 * central sanitiser is now a THIRD barrier over the already-redacted text
 * rather than a replacement for either of the first two.
 */
class ReportWritingAssistant
{
    public function __construct(
        protected AiGateway $gateway,
        protected AiPayloadSanitizer $sanitizer,
        protected ReportCapabilities $capabilities,
        protected RewriteGuard $guard,
        protected AuditLog $audit,
    ) {}

    /**
     * Whether the button may be shown at all.
     *
     * Two questions with two answers, because they fail for different reasons
     * and a teacher deserves to be told which (§41). A Base school is being
     * offered an upgrade; a Pro school with no engine configured is waiting on
     * whoever administers the installation, and clicking will never help either.
     *
     * ASKED OF THE GATEWAY NOW, not of a provider registry directly, so this
     * screen and the enforcement in `ask()` cannot disagree.
     */
    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable(AiCapability::Reports);
    }

    /**
     * Why it is not available, as a slug the screen can turn into a sentence.
     *
     * `provider` IS KEPT AS THE CATCH-ALL, and that is a compatibility
     * decision rather than a lazy one. The gateway distinguishes `off` from
     * `credential_missing`, `model_missing`, `endpoint_missing`,
     * `unknown_driver` and `fake_in_production`; the Relatórios screen has
     * always been given the single word `provider` for all of them and turns it
     * into one sentence for a teacher who cannot act on any of the six anyway.
     * Widening the vocabulary here would change a payload the frontend already
     * reads, for no gain to the person reading the screen.
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

    /**
     * Whether this particular section may be reworded.
     *
     * A FINALIZED REPORT IS NEVER TOUCHED. Its sections are history of how it
     * was assembled; the document is what it says, and it is frozen (§25).
     */
    public function mayRewrite(Report $report, ReportSection $section): bool
    {
        if (! $report->isDraft()) {
            return false;
        }

        $key = SectionKey::tryFrom($section->key);

        if ($key === null || ! SectionCatalogue::isRewritable($key)) {
            return false;
        }

        return trim((string) $section->body) !== '';
    }

    /**
     * Ask for one section to be said better.
     *
     * @throws AiUnavailable when the plan does not include it, or nothing is configured (§8)
     * @throws AiQuotaExceeded when a ceiling has been reached
     * @throws AiRequestFailed when the engine refuses, errors or times out (§26)
     */
    public function suggest(Report $report, ReportSection $section, WritingMode $mode, User $author): RewriteSuggestion
    {
        $current = trim((string) $section->body);

        if ($current === '') {
            throw AiRequestFailed::unusableAnswer('the section has no text to improve');
        }

        $limit = (int) config('lapis.ai.max_characters');

        // Refused, not truncated. Half a section rephrased and half left alone
        // is a worse document than the one that was already there.
        if (mb_strlen($current) > $limit) {
            throw AiRequestFailed::unusableAnswer('the section is longer than '.$limit.' characters');
        }

        $names = PseudonymMap::for($report);
        $pseudonymised = $names->apply($current);

        // Asserted rather than assumed. The substitution is a regex over text
        // this module did not write, and a name that survived it would be a name
        // on the wire.
        if (! $names->coversEverythingIn($pseudonymised)) {
            throw AiRequestFailed::unusableAnswer('a known name survived pseudonymisation');
        }

        $facts = ProtectedFacts::extract($pseudonymised);

        // THE THIRD BARRIER, AND IT IS NEW IN THE AI-COMPLETE SLICE. The first
        // two are this module's own — `PseudonymMap` for the names it knows,
        // `ProtectedFacts` for every digit in the paragraph. The central
        // sanitiser is what catches the shapes neither of those is looking for:
        // an email address a teacher pasted into a section, a URL, an address.
        // Digits are already gone by the time it runs, so its number rules
        // cannot fire and cannot damage a marker; what it adds is exactly the
        // non-numeric identifiers this module never had a rule for.
        //
        // THE GUARD COMPARES AGAINST WHAT WAS ACTUALLY SENT — `$payload->text`,
        // not `$facts->redacted` — because those are no longer necessarily the
        // same string, and comparing an answer against text the engine never
        // saw is how a guard starts reporting failures that did not happen.
        $payload = $this->sanitizer->sanitise($facts->redacted);

        $answer = $this->gateway->ask(new AiAsk(
            useCase: AiUseCase::ReportSectionRewrite,
            instruction: WritingPrompt::for($mode),
            content: $payload,
            promptVersion: WritingPrompt::VERSION,
            // Which section of which report this was, as a hash — so repeated
            // attempts at the same paragraph are recognisable in
            // `ai_usage_events` without the events carrying the paragraph.
            subjectHash: hash('sha256', $report->ulid.'|'.$section->ulid.'|'.$mode->value),
        ), $author);

        $normalised = $this->guard->normalise($answer->text, $payload->text);
        $verdict = $this->guard->inspect($facts, $normalised);

        $text = $verdict->acceptable
            ? $names->rehydrate($facts->restore($normalised))
            : null;

        $suggestion = new RewriteSuggestion(
            sectionUlid: $section->ulid,
            sectionKey: $section->key,
            heading: $section->heading,
            mode: $mode,
            current: $current,
            text: $text,
            verdict: $verdict,
            promptVersion: WritingPrompt::VERSION,
            provider: $answer->provider,
            model: $answer->model,
            pseudonymised: ! $names->isEmpty() && $pseudonymised !== $current,
        );

        // The same metric keys `AiTextResponse::metrics()` produced before this
        // moved onto the gateway, so an existing audit reader keeps working.
        // `latency_ms` now measures the gateway's whole call rather than the
        // provider's alone; the difference is the sanitiser's second pass, and
        // it is a truer number than the one it replaces.
        $this->record($report, $section, $suggestion, $author, $payload->text, $normalised, [
            'provider' => $answer->provider,
            'model' => $answer->model,
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
            'latency_ms' => $answer->durationMilliseconds,
        ]);

        return $suggestion;
    }

    /**
     * The trail (§20, §46, §48).
     *
     * NO TEXT, ONLY HASHES. A row here says that a rewrite of this section was
     * asked for in this mode, that this engine answered, that the guard said yes
     * or no and why, and what it cost. It does not say what the paragraph was —
     * the audit log is not a second copy of the report, and a log full of
     * children's difficulties would be a data-protection problem of its own.
     *
     * The hashes are still worth having: two identical requests are visible as
     * identical, and «was the text that was accepted the text that was
     * suggested» is answerable without either being stored.
     *
     * REUSES THE EXISTING AUDIT TABLE, deliberately. §46 asks for a new one only
     * if the existing metadata will not serve, and `audit_events` already has a
     * causer, a tenant, a subject, a moment and a JSON column.
     *
     * @param  array<string, mixed>  $metrics
     */
    protected function record(
        Report $report,
        ReportSection $section,
        RewriteSuggestion $suggestion,
        User $author,
        string $sent,
        string $received,
        array $metrics,
    ): void {
        $this->audit->record(
            'report.rewrite_suggested',
            $section,
            $author,
            summary: 'Sugestão de redação para «'.$section->heading.'» ('.$suggestion->mode->label().').',
            properties: [
                ...$metrics,
                'report_ulid' => $report->ulid,
                'report_type' => $report->type->value,
                'section_key' => $section->key,
                'mode' => $suggestion->mode->value,
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

    /**
     * The teacher took it (§20).
     *
     * Recorded from the section editor rather than from here, because accepting
     * is a separate request that happens whenever they decide — possibly after
     * editing the suggestion by hand, which is why the hash of what was actually
     * saved is the thing worth keeping.
     */
    public function recordAcceptance(Report $report, ReportSection $section, User $author, string $saved): void
    {
        $this->audit->record(
            'report.rewrite_accepted',
            $section,
            $author,
            summary: 'Sugestão de redação aplicada a «'.$section->heading.'».',
            properties: [
                'report_ulid' => $report->ulid,
                'report_type' => $report->type->value,
                'section_key' => $section->key,
                'prompt_version' => WritingPrompt::VERSION,
                'output_hash' => hash('sha256', $saved),
            ],
        );
    }
}
