<?php

namespace App\Services\Interventions\Ai;

use App\Models\InterventionPurpose;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiTextProviders;
use App\Services\Ai\AiTextRequest;
use App\Services\Ai\AiUnavailable;
use App\Services\Audit\AuditLog;
use App\Support\Entitlements\Entitlements;

/**
 * «Sugestões pedagógicas (IA)» —
 * the sibling of ReportWritingAssistant for Estratégias e Medidas.
 *
 * THE SAME SHAPE OF GUARANTEE, FOR A DIFFERENT INPUT. The writing assistant
 * sends a whole section of prose with names and numbers replaced by markers;
 * this sends far less than that — no prose from the report exists here to
 * send. What leaves the building is a domain name, the teacher's chosen
 * purpose, one short server-derived factual sentence, names/objectives of
 * prior strategies in that domain, and an optional teacher objective. No raw
 * item score or direct student identifier enters this class.
 *
 * GATED BY `ai_assistance`, SEPARATELY FROM `advanced_analytics`. A school
 * can be Pro without an engine configured, and a school can — in principle,
 * through a per-organization override — hold one capability without the
 * other. `isAvailable()` checks the plan and the engine in that order, and
 * `unavailableReason()` says which one is missing, exactly as
 * ReportWritingAssistant already does for the same two questions.
 *
 * A PROPOSAL, NEVER A WRITE. This class has no relationship to the
 * Intervention model. It returns a list of StrategySuggestion and stops;
 * turning one into a real intervention happens through the ordinary creation
 * form, with the teacher's own submit.
 */
class InterventionStrategySuggester
{
    public const AI_MODULE = 'ai_assistance';

    public function __construct(
        protected AiTextProviders $providers,
        protected Entitlements $entitlements,
        protected AuditLog $audit,
    ) {}

    public function isAvailable(): bool
    {
        return $this->entitlements->allows(self::AI_MODULE) && $this->providers->isConfigured();
    }

    /** Why it is not available, as a slug the screen can turn into a sentence — the same pattern as ReportWritingAssistant. */
    public function unavailableReason(): ?string
    {
        if (! $this->entitlements->allows(self::AI_MODULE)) {
            return 'plan';
        }

        if (! $this->providers->isConfigured()) {
            return 'provider';
        }

        return null;
    }

    /**
     * @param  list<array{name: string|null, objective: string|null}>  $existingStrategies
     * @return list<StrategySuggestion>
     *
     * @throws AiUnavailable when the installation has no engine, or the plan does not include it
     * @throws AiRequestFailed when the engine refuses, errors, times out, or answers with nothing usable
     */
    public function suggest(
        string $domainName,
        InterventionPurpose $purpose,
        string $factualPattern,
        array $existingStrategies,
        ?string $teacherObjective,
        User $author,
    ): array {
        if (! $this->isAvailable()) {
            throw AiUnavailable::notConfigured();
        }

        $provider = $this->providers->make();

        $response = $provider->complete(new AiTextRequest(
            instruction: InterventionSuggestionPrompt::text($purpose),
            content: $this->content($domainName, $purpose, $factualPattern, $existingStrategies, $teacherObjective),
        ));

        $suggestions = InterventionSuggestionParser::parse($response->text, $purpose);

        $this->record(
            $author,
            $domainName,
            $purpose,
            count($existingStrategies),
            $teacherObjective !== null,
            count($suggestions),
            $response->metrics(),
        );

        if ($suggestions === []) {
            throw AiRequestFailed::unusableAnswer('no suggestion could be parsed from the answer');
        }

        return $suggestions;
    }

    /**
     * Teacher text remains delimited content. It never becomes part of the
     * versioned instruction, and strategy history is reduced to name/objective.
     *
     * @param  list<array{name: string|null, objective: string|null}>  $existingStrategies
     */
    protected function content(
        string $domainName,
        InterventionPurpose $purpose,
        string $factualPattern,
        array $existingStrategies,
        ?string $teacherObjective,
    ): string {
        $lines = [
            'Domínio: '.$domainName,
            'Finalidade escolhida: '.$purpose->value.' ('.$purpose->label().')',
            'Padrão factual atual: '.$factualPattern,
            'Estratégias já aplicadas neste domínio (nomes e objetivos apenas):',
        ];

        if ($existingStrategies === []) {
            $lines[] = '- Nenhuma estratégia anterior registada neste domínio.';
        } else {
            foreach ($existingStrategies as $strategy) {
                $name = $this->singleLine($strategy['name'] ?? null) ?? 'Sem nome registado';
                $objective = $this->singleLine($strategy['objective'] ?? null) ?? 'Sem objetivo registado';
                $lines[] = '- Nome: '.$name.' | Objetivo: '.$objective;
            }
        }

        if ($teacherObjective === null) {
            $lines[] = 'Objetivo indicado pelo professor: não indicado.';
        } else {
            $lines[] = 'Objetivo indicado pelo professor (conteúdo delimitado):';
            $lines[] = '<<<INÍCIO DO OBJETIVO DO PROFESSOR>>>';
            $lines[] = $teacherObjective;
            $lines[] = '<<<FIM DO OBJETIVO DO PROFESSOR>>>';
        }

        return implode("\n", $lines);
    }

    protected function singleLine(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return preg_replace('/\s+/u', ' ', trim($value));
    }

    /**
     * The trail, following the exact pattern ReportWritingAssistant already
     * uses (§20, §46, §48 of the AI brief): metrics and metadata, never the
     * suggested text itself.
     *
     * @param  array<string, mixed>  $metrics
     */
    protected function record(
        User $author,
        string $domainName,
        InterventionPurpose $purpose,
        int $existingStrategyCount,
        bool $teacherObjectiveSupplied,
        int $count,
        array $metrics,
    ): void {
        $this->audit->record(
            'intervention.ai_suggestion_requested',
            null,
            $author,
            summary: 'Sugestões pedagógicas (IA) para o domínio «'.$domainName.'».',
            properties: [
                ...$metrics,
                'domain' => $domainName,
                'purpose' => $purpose->value,
                'existing_strategy_count' => $existingStrategyCount,
                'teacher_objective_supplied' => $teacherObjectiveSupplied,
                'suggestion_count' => $count,
                'prompt_version' => InterventionSuggestionPrompt::VERSION,
            ],
        );
    }
}
