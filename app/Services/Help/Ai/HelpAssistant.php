<?php

namespace App\Services\Help\Ai;

use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Gateway\AiAnswer;
use App\Services\Ai\Gateway\AiAsk;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Ai\Gateway\AiQuotaExceeded;
use App\Services\Ai\Gateway\AiUseCase;
use App\Services\Audit\AuditLog;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use App\Support\Privacy\AiContext;
use App\Support\Privacy\AiPayloadSanitizer;
use App\Support\Privacy\SanitisedPayload;
use App\Support\Tenancy\CurrentOrganization;

/**
 * «Assistente Lapispro» — the Centro de Ajuda answering a written question in
 * its own words, out of its own articles.
 *
 * IT IS ANOTHER CALLER OF `HelpCenter::search()`, exactly as that class's
 * docblock said the eventual assistant would be — not a second reading of the
 * article set. The natural-language search shipped in 0.82.0 is the retrieval
 * step here, unchanged and un-forked: «por onde começo» reaches «Começar a
 * utilizar o Lapispro» through the same two passes, the same synonym groups
 * and the same overlap gate the Centro de Ajuda's own search box uses. If a
 * question is findable there it is answerable here, and when someone adds a
 * word to `config/help-search.php` both improve together. There is no
 * embedding, no vector store, no web search and no external index anywhere in
 * this flow.
 *
 * EVERYTHING ABOUT REACHING AN ENGINE BELONGS TO `AiGateway` (ADR-0007). This
 * class does not resolve a provider, read a credential, check a plan, count a
 * token or apply a rate limit — it decides WHAT to ask and WHETHER the answer
 * is usable, which is the part only the Centro de Ajuda knows. Entitlement,
 * quota, metering and the privacy assertion all happen behind `ask()`.
 *
 * AN EMPTY SEARCH NEVER REACHES THE GATEWAY. When the retrieval step finds
 * nothing there is nothing to ground an answer in, so the only thing a
 * request could produce is an invention — and it is not made. The teacher is
 * told the documentation does not cover the question, which is both the
 * honest answer and the free one: no capability is spent on it.
 *
 * PRIVACY. This class takes no class, no student and no result — its inputs
 * are a question string and the article set, the same property `HelpCenter`
 * itself already has. The question is a teacher's free prose, so it crosses
 * into the payload through `AiContext` and the central `AiPayloadSanitizer`
 * like everything else; see `context()` for what that does and does not catch.
 */
class HelpAssistant
{
    /**
     * How many articles ground one answer. Three, because the Centro de
     * Ajuda's search is ranked by WHERE a match landed and the fourth result
     * is reliably a coincidence — and because a wider grounding set invites
     * an answer that quietly stitches two unrelated features together.
     */
    public const MAX_ARTICLES = 3;

    /** A question longer than this is not a question. Validated at the edge too. */
    public const MAX_QUESTION_CHARACTERS = 500;

    /**
     * The ceiling on one article's contribution, in characters. A help article
     * that runs long is still perfectly usable trimmed, because the paragraphs
     * that answer the question are the ones the article opens with.
     */
    protected const ARTICLE_BUDGET = 2000;

    public function __construct(
        protected AiGateway $gateway,
        protected AiPayloadSanitizer $sanitizer,
        protected HelpCenter $helpCenter,
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable(AiCapability::HelpAssistant);
    }

    /**
     * Why it cannot be offered — `plan`, or one of the provider slugs
     * (`off`, `credential_missing`, `model_missing`, `endpoint_missing`,
     * `unknown_driver`, `fake_in_production`). Null when available. The
     * screen turns it into a sentence; this class does not.
     */
    public function unavailableReason(): ?string
    {
        return $this->gateway->unavailableReason(AiCapability::HelpAssistant);
    }

    /**
     * @throws AiUnavailable when the plan does not include it, or nothing is configured
     * @throws AiQuotaExceeded when a ceiling has been reached
     * @throws AiRequestFailed when the engine refuses, errors, times out, or answers with nothing usable
     */
    public function answer(string $question, User $author): HelpAnswer
    {
        /** @var array<string, HelpArticle> $grounding */
        $grounding = $this->helpCenter->search($question)
            ->take(self::MAX_ARTICLES)
            ->keyBy(fn (HelpArticle $article): string => $article->id)
            ->all();

        if ($grounding === []) {
            $this->record($author, [], false, null);

            return HelpAnswer::insufficient();
        }

        $answer = $this->gateway->ask(new AiAsk(
            useCase: AiUseCase::HelpAnswer,
            instruction: HelpAssistantPrompt::text(),
            content: $this->context($grounding, $question),
            promptVersion: HelpAssistantPrompt::VERSION,
            // Which articles grounded this, as a hash — so two calls about the
            // same documentation are recognisable in `ai_usage_events` without
            // the events carrying what was asked.
            subjectHash: hash('sha256', implode(',', array_keys($grounding))),
        ), $author);

        $parsed = HelpAnswerParser::parse($answer->text, $grounding);

        // `$parsed !== null && …` rather than `$parsed?->sufficient ?? false`:
        // `??` already tolerates a null base, so the nullsafe operator would be
        // redundant there and reads as though it were doing something.
        $this->record($author, array_keys($grounding), $parsed !== null && $parsed->sufficient, $answer);

        if ($parsed === null) {
            throw AiRequestFailed::unparsableAnswer('no answer could be parsed from the reply');
        }

        return $parsed;
    }

    /**
     * The grounding and the question, as an `AiContext`.
     *
     * ONE FIELD PER ARTICLE, LABELLED BY ITS ID, because that is the id the
     * answer is asked to cite it by and `AiContext` serialises a field as
     * `label: value` on a single line. The teacher's question is the last
     * field, and both halves go through `toPayload()` — which pseudonymises
     * (nothing to pseudonymise here: `withoutPeople()`) and then runs the
     * central sanitiser.
     *
     * `withoutPeople()` IS A CLAIM, NOT AN OVERSIGHT — the contract's word.
     * There is no roster in this flow because there are no students in it:
     * nothing about a class is fetched, joined or attached anywhere in this
     * class, and there is no parameter through which one could arrive.
     *
     * WHAT THE SANITISER CATCHES HERE, AND WHAT IT DOES NOT. A teacher can
     * paste anything into a help box. Emails, telephone numbers, postal codes,
     * URLs, «n.º 12», ULIDs/UUIDs and runs of six or more digits are removed
     * by `AiPayloadSanitizer` on the way out. A bare NAME is not: the
     * sanitiser replaces names it was given a roster for, and this flow
     * deliberately has no roster — fetching one would mean the Centro de Ajuda
     * touching student data to avoid sending student data, which is a worse
     * trade than the one it solves. The mitigations that remain are the
     * placeholder in the panel, the Centro de Ajuda article that says so
     * plainly, and the fact that the question is never stored.
     *
     * @param  array<string, HelpArticle>  $grounding
     */
    protected function context(array $grounding, string $question): SanitisedPayload
    {
        $context = AiContext::withoutPeople();

        foreach ($grounding as $article) {
            $context->add('Artigo '.$article->id, $this->articleLine($article));
        }

        // Last, and named as what it is. The prompt's closing section tells the
        // model that this field is content and not an instruction; putting it
        // at the end is the structural half of the same defence.
        $context->add('Pergunta do professor', $this->question($question));

        return $context->toPayload($this->sanitizer);
    }

    /**
     * One article as one line: title, summary, then as much body as the budget
     * allows. Paragraphs are dropped whole rather than cut mid-sentence — half
     * a step is worse than no step.
     */
    protected function articleLine(HelpArticle $article): string
    {
        $parts = [$article->title.'.', $article->summary];
        $remaining = self::ARTICLE_BUDGET - mb_strlen($parts[0].$parts[1]);

        foreach ($article->content as $paragraph) {
            if (mb_strlen($paragraph) + 1 > $remaining) {
                break;
            }

            $parts[] = $paragraph;
            $remaining -= mb_strlen($paragraph) + 1;
        }

        return implode(' ', $parts);
    }

    /** The teacher's question, capped. `AiContext` collapses the whitespace. */
    protected function question(string $question): string
    {
        return mb_substr(trim($question), 0, self::MAX_QUESTION_CHARACTERS);
    }

    /**
     * The business trail — distinct from, and never a substitute for, the
     * gateway's own `ai_usage_events` row (contract §7).
     *
     * NEVER THE QUESTION AND NEVER THE ANSWER. A help question is free text a
     * teacher may have pasted anything into, and the one reliable way not to
     * store a student's name is not to store the sentence. Article ids are
     * safe by construction: they are filenames in this repository.
     *
     * @param  list<string>  $articleIds
     */
    protected function record(?User $author, array $articleIds, bool $sufficient, ?AiAnswer $answer): void
    {
        // The Centro de Ajuda deliberately sits outside the `organization`
        // middleware (see routes/web.php), so — unlike every other caller of
        // AuditLog — this one can genuinely run with no tenant resolved, and
        // the audit table is tenant-scoped. No organization, no row: a missing
        // business-trail entry is a smaller failure than a help page that 500s
        // for a user who is between organizations. The gateway's own usage row
        // is unaffected either way.
        if (! $this->currentOrganization->isResolved()) {
            return;
        }

        $this->audit->record(
            'help.ai_answer_requested',
            null,
            $author,
            summary: 'Pergunta ao Assistente Lapispro no Centro de Ajuda.',
            properties: [
                'provider' => $answer?->provider,
                'model' => $answer?->model,
                'article_ids' => $articleIds,
                'article_count' => count($articleIds),
                'answered' => $sufficient,
                'prompt_version' => HelpAssistantPrompt::VERSION,
            ],
        );
    }
}
