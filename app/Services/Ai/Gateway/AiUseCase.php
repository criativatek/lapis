<?php

namespace App\Services\Ai\Gateway;

/**
 * What a particular call was FOR, one level finer than the capability that
 * permits it.
 *
 * WHY BOTH. `capability` answers «was this organization allowed to do this», and
 * it is what quotas count and what plans grant. `use_case` answers «which of the
 * things that capability covers was this», and it is what makes the meter
 * readable: two features can share one entitlement and still need to be told
 * apart when somebody asks where the month went. Splitting the entitlement
 * instead would mean a commercial decision every time a screen gains a button.
 *
 * CLOSED, AND THAT IS A SAFETY PROPERTY, not tidiness. A free-string use case
 * would be a free-string column, and `ai_usage_events` would end up carrying
 * whatever a caller felt like writing — which on a bad day is the question the
 * user asked. An enum can only ever store one of these words.
 *
 * A USE CASE KNOWS ITS CAPABILITY. `AiGateway` derives the entitlement from the
 * use case rather than accepting both, so a caller cannot ask for a pedagogical
 * analysis while presenting the help assistant's entitlement.
 */
enum AiUseCase: string
{
    /** «Como faço X no Lapispro?» — answered from the Centro de Ajuda's authored articles. */
    case HelpAnswer = 'help_answer';

    /** The same corpus, asked to point at articles rather than to compose an answer. */
    case HelpArticleSuggestion = 'help_article_suggestion';

    /** Reading a class's Estatística and describing it. Never deciding anything. */
    case PedagogicalAnalysis = 'pedagogical_analysis';

    /**
     * Reading a period's RESULTS — domain by domain, with the coverage the
     * engine reported — and describing how the assessment went.
     *
     * NOT THE SAME CALL AS `PedagogicalAnalysis`, and the difference is the read
     * model rather than the wording. That one is handed `BuildClassStatistics`
     * (distribution, bands, success rate); this one is handed
     * `ClassResultsCalculator::forPeriod` (per-domain outcomes and coverage
     * warnings). Two different sets of already-decided numbers, two different
     * questions, two different pages.
     */
    case AssessmentAnalysis = 'assessment_analysis';

    /** Synthesising one student's Evolução — what the record shows, and what changed. */
    case FollowupSynthesis = 'followup_synthesis';

    /** Proposing strategies for a teacher to consider, accept or ignore. */
    case PedagogicalStrategySuggestion = 'pedagogical_strategy_suggestion';

    /** Saying one already-written report section better. Never writing a new fact into it. */
    case ReportSectionRewrite = 'report_section_rewrite';

    /**
     * The backoffice proving a credential works.
     *
     * NOT A TENANT'S TRAFFIC. It is recorded with no organization, it counts
     * against no quota, and it is the reason `AiUsageEvent::$organization_id` is
     * nullable — billing a school for an operator's connection test would be
     * wrong in both directions.
     */
    case AdminConnectionTest = 'admin_connection_test';

    /**
     * The backoffice proving the configured model can do the WORK, not merely
     * that the credential opens the door.
     *
     * WHY IT IS NOT THE CONNECTION TEST. That one asks for the word «OK». It
     * proves a key, a URL and a route to Google, and it passes on a model that
     * cannot produce a single usable answer in this product — which is exactly
     * what happened: the connection test was green throughout, while every
     * síntese de acompanhamento failed, because the two questions are not the
     * same question. Six labelled sections under a long instruction is the
     * shape of the real work, and it is the shape that runs out of budget.
     *
     * IT CARRIES THE SYNTHESIS'S BUDGET, deliberately — see
     * `minimumOutputTokens()`. A probe run under a more generous ceiling than
     * the feature it stands for would be a test that passes when the thing it
     * tests does not.
     *
     * NOBODY'S DATA IS IN IT. The content is a fixed synthetic record written
     * into this repository, about a student who does not exist, and it is the
     * same on every installation. Like the connection test it is recorded with
     * no organization and counts against no school's quota.
     */
    case AdminCapabilityProbe = 'admin_capability_probe';

    /**
     * The entitlement this use case needs, or null when it needs none.
     *
     * Only the two backoffice diagnostics return null, and both are reachable
     * exclusively from a `platform-admin` route — their authorization is the
     * middleware, not an entitlement, because the SaaS operator is not a
     * customer of the SaaS.
     */
    public function capability(): ?AiCapability
    {
        return match ($this) {
            self::HelpAnswer,
            self::HelpArticleSuggestion => AiCapability::HelpAssistant,

            self::PedagogicalAnalysis => AiCapability::PedagogicalAnalysis,

            self::AssessmentAnalysis => AiCapability::Assessment,

            self::FollowupSynthesis => AiCapability::Followup,

            self::PedagogicalStrategySuggestion => AiCapability::Strategies,

            self::ReportSectionRewrite => AiCapability::Reports,

            self::AdminConnectionTest,
            self::AdminCapabilityProbe => null,
        };
    }

    /**
     * The SMALLEST answer budget, in tokens, this use case needs to be able to
     * produce a complete answer — or null to take the installation default.
     *
     * WHY THIS LIVES ON A CLOSED ENUM AND NOT ON `AiAsk`. The rule this codebase
     * has held since the writing assistant shipped is that a ceiling a CALLER
     * can express is a ceiling a caller can raise, which is why `AiAsk` has no
     * such field and `AiTextRequest` had none either. A use case is a different
     * thing from a caller: it is an application-authored constant in a closed
     * enum, it cannot be built from a request, and the number below is therefore
     * no more raisable than the prompt text beside it. `AiGateway` reads it,
     * clamps it against the installation's hard ceiling, and hands the result to
     * the provider — so what reaches the wire is still decided by the
     * installation, never by whoever called.
     *
     * ONLY THE SYNTHESIS SETS ONE, and it is the reason this method exists. Its
     * instruction asks for SIX labelled sections, four of them lists of up to
     * four sentences each, and it is the only reading in the product whose
     * parser refuses a partial answer outright. The installation default of
     * 2048 is a comfortable budget for one rephrased paragraph and a tight one
     * for that; on a model that spends the same budget thinking first, it was
     * not a budget at all. Every other use case is left at the default
     * deliberately — a ceiling is raised where it is short, not everywhere.
     */
    public function minimumOutputTokens(): ?int
    {
        return match ($this) {
            // The probe stands for the synthesis, so it runs under the
            // synthesis's budget — a probe with more room than the feature it
            // stands for would pass while the feature fails.
            self::FollowupSynthesis,
            self::AdminCapabilityProbe => 3072,

            default => null,
        };
    }

    /** pt-PT, for an operator reading the meter. Never for a prompt. */
    public function label(): string
    {
        return match ($this) {
            self::HelpAnswer => 'Resposta do Assistente',
            self::HelpArticleSuggestion => 'Sugestão de artigos de ajuda',
            self::PedagogicalAnalysis => 'Análise da estatística da turma',
            self::AssessmentAnalysis => 'Análise dos resultados do período',
            self::FollowupSynthesis => 'Síntese de acompanhamento do aluno',
            self::PedagogicalStrategySuggestion => 'Sugestão de estratégias',
            self::ReportSectionRewrite => 'Aperfeiçoamento de redação',
            self::AdminConnectionTest => 'Teste de ligação (plataforma)',
            self::AdminCapabilityProbe => 'Teste de capacidade (plataforma)',
        };
    }

    /**
     * What `ai_usage_events.capability` stores for this use case.
     *
     * `platform` for the operator's own traffic: a truthful label for the one
     * kind of call that belongs to nobody's plan, and one that can never collide
     * with a module key because no module is called that.
     */
    public function capabilityColumn(): string
    {
        $capability = $this->capability();

        return $capability === null ? 'platform' : $capability->value;
    }
}
