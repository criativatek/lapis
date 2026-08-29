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
     * The entitlement this use case needs, or null when it needs none.
     *
     * Only the connection test returns null, and it is reachable exclusively
     * from a `platform-admin` route — its authorization is the middleware, not
     * an entitlement, because the SaaS operator is not a customer of the SaaS.
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

            self::AdminConnectionTest => null,
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
