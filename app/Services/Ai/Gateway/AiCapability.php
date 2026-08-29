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
 * ONE CAPABILITY PER PRODUCT AREA, NOT ONE PER BUTTON. A school buys «IA na
 * avaliação» or it does not; it does not buy the analysis button on Resultados
 * separately from the one that will live beside it next year. The finer grain —
 * which of an area's features a particular call was — is `AiUseCase`, which
 * costs nothing commercially and is what makes the meter readable.
 *
 * WHY THE AREAS ARE SEPARATE, AND IT IS NOT TIDINESS. They differ in what they
 * see, what they may say, and what it costs to be wrong:
 *
 *   help_assistant           answers «como faço X no Lapispro» from the Centro
 *                            de Ajuda's own authored articles. It sees product
 *                            documentation and a question. It never sees a
 *                            student.
 *
 *   ai_pedagogical_analysis  reads a class's Estatística — the distribution,
 *                            the evolution, the success rate — and describes it.
 *
 *   ai_assessment            reads the RESULTS of a period, domain by domain,
 *                            with the coverage warnings the engine raised. A
 *                            different read model, a different page and a
 *                            different question: «como correu esta avaliação»,
 *                            not «como está a turma».
 *
 *   ai_followup              reads one student's Evolução and synthesises it.
 *
 *   ai_strategies            proposes strategies for a teacher to consider.
 *
 *   ai_reports               rewrites a paragraph a teacher is already writing.
 *
 *   ai_governance            is not an engine capability at all: it is the
 *                            institutional administrator's view of what the
 *                            organization is consuming.
 *
 *   ai_institutional_pool    is not an engine capability either: it is whether
 *                            this organization's consumption is governed by a
 *                            shared organizational pool on top of the ordinary
 *                            per-capability ceilings.
 *
 * A school might reasonably want the help assistant and none of the rest. A
 * school might reasonably want every teacher-facing capability and no
 * institutional governance. Folding them into one key would make both
 * impossible to express, and would mean the cheapest, most harmless capability
 * carried the entitlement of the most dangerous one.
 *
 * `ai_assistance` IS THE HISTORICAL KEY AND IT IS NOT DELETED. It gated
 * «Aperfeiçoar redação» in Relatórios and the strategy suggester in Intervenções
 * before either of those features went through `AiGateway`. Both now check
 * `ai_reports` and `ai_strategies` respectively, and both still accept
 * `ai_assistance` through `legacyModuleKeys()` — see that method for why the
 * alias exists and what would have broken without it.
 */
enum AiCapability: string
{
    case HelpAssistant = 'help_assistant';

    case PedagogicalAnalysis = 'ai_pedagogical_analysis';

    case Assessment = 'ai_assessment';

    case Followup = 'ai_followup';

    case Strategies = 'ai_strategies';

    case Reports = 'ai_reports';

    case Governance = 'ai_governance';

    case InstitutionalPool = 'ai_institutional_pool';

    /** The entitlement key. Identical to the value, on purpose — see the class docblock. */
    public function moduleKey(): string
    {
        return $this->value;
    }

    /**
     * Historical entitlement keys that ALSO grant this capability.
     *
     * A TRANSITION, NOT A SYNONYM, AND IT IS DELIBERATELY ONE-WAY. Before this
     * release, «Aperfeiçoar redação» and «Sugestões de estratégia» were both
     * gated by the single key `ai_assistance`, which Pro and Institucional
     * grant and which some organizations additionally hold through a row in
     * `organization_module_overrides` — a pilot, a temporary benefit, a
     * negotiated exception. Renaming the check to `ai_reports` and
     * `ai_strategies` without this list would have switched those organizations
     * off silently, and the only symptom would have been a teacher saying «o
     * botão desapareceu».
     *
     * SO THE OLD KEY STILL OPENS THE NEW DOOR, and the new keys are what the
     * plans grant from here on. `ai_assistance` stays in the catalogue and
     * stays in Pro/Institucional; nothing about an existing subscription or
     * override changes. When every override has been migrated, removing an
     * entry here is a one-line change, and `AiCapabilityCatalogTest` is what
     * says which entries are still load-bearing.
     *
     * IT ONLY EVER WIDENS ACCESS. There is no path by which a legacy key
     * REMOVES a capability the new key granted — `AiGateway` asks whether ANY
     * of the keys is allowed, so an organization holding `ai_reports` is
     * unaffected by whatever `ai_assistance` says.
     *
     * @return list<string>
     */
    public function legacyModuleKeys(): array
    {
        return match ($this) {
            self::Strategies, self::Reports => ['ai_assistance'],
            default => [],
        };
    }

    /**
     * Every entitlement key that grants this capability, current one first.
     *
     * @return list<string>
     */
    public function moduleKeys(): array
    {
        return [$this->moduleKey(), ...$this->legacyModuleKeys()];
    }

    /**
     * Whether this capability is one an `AiAsk` can be made under.
     *
     * TWO OF THEM ARE NOT, AND THE DISTINCTION IS LOAD-BEARING RATHER THAN
     * DECORATIVE. `ai_governance` and `ai_institutional_pool` are entitlements
     * about ADMINISTERING AI, not about calling an engine: the first opens a
     * screen showing what an organization consumed, the second decides whether
     * a shared organizational ceiling applies on top of the per-capability
     * ones. Neither ever produces a token, so neither has a quota, a
     * rate-limit bucket that could ever be hit, or a row in `ai_usage_events`.
     *
     * The administration screen reads this to decide which capabilities get
     * quota fields. A quota field for a capability that can never make a
     * request is a control that does nothing, and a control that does nothing
     * is a support ticket.
     */
    public function isMetered(): bool
    {
        return match ($this) {
            self::Governance, self::InstitutionalPool => false,
            default => true,
        };
    }

    /**
     * Every capability that can actually reach an engine.
     *
     * @return list<self>
     */
    public static function metered(): array
    {
        return array_values(array_filter(self::cases(), fn (self $capability): bool => $capability->isMetered()));
    }

    /** pt-PT, for an operator's screen. Never for a prompt. */
    public function label(): string
    {
        return match ($this) {
            self::HelpAssistant => 'Assistente do Centro de Ajuda',
            self::PedagogicalAnalysis => 'Análise pedagógica da turma (IA)',
            self::Assessment => 'IA na avaliação',
            self::Followup => 'IA no acompanhamento do aluno',
            self::Strategies => 'IA em estratégias e medidas',
            self::Reports => 'IA nos relatórios',
            self::Governance => 'Governação de IA (institucional)',
            self::InstitutionalPool => 'Pool de IA da organização',
        };
    }

    /**
     * Where in the application a teacher meets this capability, in pt-PT.
     *
     * FOR THE OPERATOR'S SCREEN, so that «IA no acompanhamento do aluno» does
     * not have to be looked up in the source to find out which page it is. It
     * is never sent to an engine and never used for authorization.
     */
    public function whereItLives(): string
    {
        return match ($this) {
            self::HelpAssistant => 'Centro de Ajuda',
            self::PedagogicalAnalysis => 'Resultados › Estatística da turma',
            self::Assessment => 'Resultados › Resultados do período',
            self::Followup => 'Evolução do Aluno',
            self::Strategies => 'Evolução do Aluno › Estratégias e medidas',
            self::Reports => 'Relatórios › Secções',
            self::Governance => 'Administração institucional › Inteligência Artificial',
            self::InstitutionalPool => 'Aplica-se a todos os pedidos de IA da organização',
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
     * `AiGateway` has to hold the same line for a queued job or a console
     * command, where there is no middleware stack to hang a limiter on — so it
     * takes the buckets itself. Routes built on the gateway must NOT also add
     * `throttle:` for the same capability; that would halve the ceiling by
     * counting twice. See docs/ai-core-contract.md.
     */
    public function rateLimiterKey(): string
    {
        return 'ai:'.$this->value;
    }
}
