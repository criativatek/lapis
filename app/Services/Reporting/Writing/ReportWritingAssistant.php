<?php

namespace App\Services\Reporting\Writing;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionKey;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiTextProviders;
use App\Services\Ai\AiTextRequest;
use App\Services\Ai\AiUnavailable;
use App\Services\Audit\AuditLog;
use App\Services\Reporting\ReportCapabilities;

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
 */
class ReportWritingAssistant
{
    public function __construct(
        protected AiTextProviders $providers,
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
     */
    public function isAvailable(): bool
    {
        return $this->capabilities->allowsWritingAssistance() && $this->providers->isConfigured();
    }

    /** Why it is not available, as a slug the screen can turn into a sentence. */
    public function unavailableReason(): ?string
    {
        if (! $this->capabilities->allowsWritingAssistance()) {
            return 'plan';
        }

        if (! $this->providers->isConfigured()) {
            return 'provider';
        }

        return null;
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
     * @throws AiUnavailable when the installation has no engine (§8)
     * @throws AiRequestFailed when the engine refuses, errors or times out (§26)
     */
    public function suggest(Report $report, ReportSection $section, WritingMode $mode, User $author): RewriteSuggestion
    {
        if (! $this->isAvailable()) {
            throw AiUnavailable::notConfigured();
        }

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

        $provider = $this->providers->make();

        $response = $provider->complete(new AiTextRequest(
            instruction: WritingPrompt::for($mode),
            content: $facts->redacted,
        ));

        $answer = $this->guard->normalise($response->text, $facts->redacted);
        $verdict = $this->guard->inspect($facts, $answer);

        $text = $verdict->acceptable
            ? $names->rehydrate($facts->restore($answer))
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
            provider: $response->provider,
            model: $response->model,
            pseudonymised: ! $names->isEmpty() && $pseudonymised !== $current,
        );

        $this->record($report, $section, $suggestion, $author, $facts->redacted, $answer, $response->metrics());

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
