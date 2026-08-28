<?php

namespace App\Services\Ai\Gateway;

/**
 * The AI capabilities a plan can grant, and the entitlement key behind each.
 *
 * THE VALUE IS THE MODULE KEY. There is no mapping table and no `match` that
 * turns a case into a string — the case IS the key in `modules.key`, so
 * `$entitlements->allows($capability->value)` is the whole check and the two can
 * never drift apart (§8).
 *
 * THE TWO ARE SEPARATE AND THAT IS THE POINT (§8 of the AI Core brief). They
 * differ in what they see, what they may say, and what it costs to be wrong:
 *
 *   help_assistant           answers «como faço X no Lapispro» from the Centro
 *                            de Ajuda's own authored articles. It sees product
 *                            documentation and a question. It never sees a
 *                            student.
 *
 *   ai_pedagogical_analysis  reasons about teaching. It sees pedagogical
 *                            material — sanitised, pseudonymised, and only
 *                            through AiGateway — and a wrong answer here lands
 *                            in a document about a child.
 *
 * A school might reasonably want the first and not the second. Folding them into
 * one key would make that impossible to express, and would mean the cheaper,
 * harmless capability carried the entitlement of the dangerous one.
 *
 * NEITHER IS `ai_assistance`, which already exists and stays exactly where it
 * is: it gates «Aperfeiçoar redação» in Relatórios and the strategy suggester in
 * Intervenções. Reusing it here would have meant that turning on the help
 * assistant turned on rewriting inside reports.
 *
 * WHICH PLANS GRANT THESE IS NOT DECIDED HERE AND HAS NOT BEEN DECIDED AT ALL.
 * `EntitlementsSeeder` catalogues both keys and assigns them to no plan, which
 * is the honest expression of an open commercial question (CLAUDE.md §31 —
 * «change the commercial composition of the plans»). Until somebody decides,
 * every organization answers `false` to both, every screen shows the
 * upgrade-shaped unavailable state, and no request reaches an engine. See
 * docs/ai-core-contract.md §«Decisões pendentes».
 */
enum AiCapability: string
{
    case HelpAssistant = 'help_assistant';

    case PedagogicalAnalysis = 'ai_pedagogical_analysis';

    /** The entitlement key. Identical to the value, on purpose — see the class docblock. */
    public function moduleKey(): string
    {
        return $this->value;
    }

    /** pt-PT, for an operator's screen. Never for a prompt. */
    public function label(): string
    {
        return match ($this) {
            self::HelpAssistant => 'Assistente do Centro de Ajuda',
            self::PedagogicalAnalysis => 'Análise pedagógica (IA)',
        };
    }

    /**
     * The rate-limiter key prefix for this capability.
     *
     * ONE BUCKET PER CAPABILITY, never a shared one. A teacher exhausting the
     * help assistant's minute must not be the reason a pedagogical analysis is
     * refused — the two cost differently, fail differently, and a shared bucket
     * would let the cheap one starve the expensive one.
     *
     * A KEY, NOT A NAMED `RateLimiter::for()` LIMITER, and that is deliberate.
     * The report writing assistant is rate limited by route middleware, which
     * works because it is only ever reached by an HTTP request. `AiGateway` has
     * to hold the same line for a queued job or a console command, where there
     * is no middleware stack to hang a limiter on — so it takes the buckets
     * itself. Routes built on the gateway must NOT also add `throttle:` for the
     * same capability; that would halve the ceiling by counting twice. See
     * docs/ai-core-contract.md.
     */
    public function rateLimiterKey(): string
    {
        return 'ai:'.$this->value;
    }
}
