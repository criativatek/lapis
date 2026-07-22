<?php

namespace App\Domain\Assessment;

use App\Models\ResultState;

/**
 * The deterministic calculation engine (§13). Pure: no Eloquent, no dates, no
 * side effects — it takes validated data and the frozen rule and returns a
 * result plus a structured explanation. Every rule the product owner decided
 * (ADR-0004) is applied here and nowhere else.
 *
 * The canonical unit is percent-of-max as a decimal string. Nothing is rounded
 * until the single proposal step; division by zero is a state, not a zero (§9).
 */
class CalculationEngine
{
    /**
     * Stamped into every snapshot (§13.6): a value frozen by v1.0 stays explainable
     * by v1.0's rules even after the engine evolves. Bump on any change to the
     * arithmetic or rule application here.
     */
    public const VERSION = '1.0';

    /**
     * @param  list<ScoreInput>  $scores
     * @param  array<int, string>  $domainWeights  domain_id => weight_percent (the profile version's weights)
     */
    public function calculate(array $scores, array $domainWeights, CalculationRule $rule): CalculationOutcome
    {
        $domainOutcomes = [];
        $domainExplanations = [];

        foreach ($domainWeights as $domainId => $weightPercent) {
            [$outcome, $explanation] = $this->calculateDomain($domainId, (string) $weightPercent, $scores, $rule);
            $domainOutcomes[] = $outcome;
            $domainExplanations[] = $explanation;
        }

        return $this->combine($domainOutcomes, $domainExplanations, $rule);
    }

    /**
     * @param  list<ScoreInput>  $scores
     * @return array{0: DomainOutcome, 1: array<string, mixed>}
     */
    protected function calculateDomain(int $domainId, string $weightPercent, array $scores, CalculationRule $rule): array
    {
        $numerator = '0';
        $denominator = '0';
        $contributing = 0;
        $coverageWarning = false;
        $included = [];
        $excluded = [];

        foreach ($scores as $score) {
            $allocation = $this->allocationFor($score, $domainId);

            if ($allocation === null) {
                continue; // This item does not touch this domain.
            }

            if (! $score->eligible) {
                $excluded[] = ['item' => $score->itemCode, 'reason' => 'not_applicable_to_enrollment'];

                continue;
            }

            $fraction = Bc::div(Bc::of($allocation), '100');
            $weightedPossible = Bc::mul(Bc::of($score->pointsPossible), $fraction);

            $treatment = $this->treatmentFor($score->state, $rule->absenceMode);

            if ($treatment === 'exclude') {
                if ($this->raisesCoverageWarning($score->state, $rule->absenceMode)) {
                    $coverageWarning = true;
                }
                $excluded[] = ['item' => $score->itemCode, 'reason' => $score->state->value];

                continue;
            }

            // 'zero' (an absence counted as zero) contributes 0 to the numerator
            // but still occupies the denominator; 'value' contributes the mark.
            $earned = $treatment === 'zero' ? '0' : Bc::of($score->pointsEarned ?? '0');
            $numerator = Bc::add($numerator, Bc::mul($earned, $fraction));

            // Bonus items add to the numerator but never the denominator (§4.2).
            if (! $score->isBonus) {
                $denominator = Bc::add($denominator, $weightedPossible);
            }

            $contributing++;
            $included[] = [
                'item' => $score->itemCode,
                'state' => $score->state->value,
                'earned' => $earned,
                'possible' => $score->pointsPossible,
                'allocation_percent' => $allocation,
                'is_bonus' => $score->isBonus,
            ];
        }

        $normalized = Bc::isZero($denominator)
            ? null
            : Bc::truncate(Bc::mul(Bc::div($numerator, $denominator), '100'), 6);

        // A domain with weight but no elements is a coverage gap, not a zero.
        if ($normalized === null && $contributing === 0) {
            $coverageWarning = true;
        }

        $outcome = new DomainOutcome(
            domainId: $domainId,
            normalizedValue: $normalized,
            pointsEarned: Bc::truncate($numerator, 4),
            pointsPossible: Bc::truncate($denominator, 4),
            weightPercent: $weightPercent,
            contributingCount: $contributing,
            coverageWarning: $coverageWarning,
        );

        return [$outcome, [
            'domain_id' => $domainId,
            'weight_percent' => $weightPercent,
            'normalized_value' => $normalized,
            'points_earned' => $outcome->pointsEarned,
            'points_possible' => $outcome->pointsPossible,
            'included' => $included,
            'excluded' => $excluded,
            'coverage_warning' => $coverageWarning,
        ]];
    }

    /**
     * @param  list<DomainOutcome>  $domains
     * @param  list<array<string, mixed>>  $domainExplanations
     */
    protected function combine(array $domains, array $domainExplanations, CalculationRule $rule): CalculationOutcome
    {
        $weightedSum = '0';
        $weightSum = '0';
        $contributing = 0;
        $coverageWarning = false;
        $droppedDomains = [];

        foreach ($domains as $domain) {
            if ($domain->coverageWarning) {
                $coverageWarning = true;
            }

            $weight = Bc::of($domain->weightPercent);

            if (! $domain->hasValue()) {
                // A domain with no value is dropped and the remaining weights
                // renormalize over the domains that do have one — a missing
                // domain must never act as a zero (§13.4).
                if (Bc::compare($weight, '0') > 0) {
                    $droppedDomains[] = $domain->domainId;
                    $coverageWarning = true;
                }

                continue;
            }

            $value = Bc::of($domain->normalizedValue ?? '0');
            $weightedSum = Bc::add($weightedSum, Bc::mul($value, $weight));
            $weightSum = Bc::add($weightSum, $weight);
            $contributing += $domain->contributingCount;
        }

        // Division by zero is a state: no domain had any element → no result.
        $normalized = Bc::isZero($weightSum)
            ? null
            : Bc::truncate(Bc::div($weightedSum, $weightSum), 6);

        // The single rounding point (§9 rule 2): the proposal, with the frozen rule.
        $proposed = $normalized === null
            ? null
            : Bc::round(Bc::of($normalized), $rule->roundingScale, $rule->roundingMode);

        return new CalculationOutcome(
            domains: $domains,
            normalizedValue: $normalized,
            proposedValue: $proposed,
            resultState: $normalized === null ? ResultState::Pending->value : ResultState::Assessed->value,
            coverageWarning: $coverageWarning,
            contributingCount: $contributing,
            explanation: [
                'domains' => $domainExplanations,
                'dropped_domains' => $droppedDomains,
                'weight_total_applied' => Bc::truncate($weightSum, 4),
                'normalized_value' => $normalized,
                'rounding' => ['mode' => $rule->roundingMode, 'scale' => $rule->roundingScale, 'stage' => $rule->roundingStage],
                'proposed_value' => $proposed,
                'coverage_warning' => $coverageWarning,
                // Bands are not configured (Q1), so no scale level is proposed —
                // the engine returns the value and says so rather than inventing one.
                'scale_level' => null,
                'scale_level_note' => 'Sem bandas de escala configuradas — o nível é atribuído pelo professor (Q1).',
            ],
        );
    }

    protected function allocationFor(ScoreInput $score, int $domainId): ?string
    {
        foreach ($score->allocations as $allocation) {
            if ($allocation['domain_id'] === $domainId) {
                return $allocation['allocation_percent'];
            }
        }

        return null;
    }

    /**
     * How a state's item is treated in the fraction: contribute its value, count
     * as zero, or be excluded from both sides. Absences defer to absence_mode
     * (§13.3), never assumed.
     *
     * @return 'value'|'zero'|'exclude'
     */
    protected function treatmentFor(ResultState $state, string $absenceMode): string
    {
        if ($state === ResultState::Assessed) {
            return 'value';
        }

        if ($state === ResultState::Absent || $state === ResultState::AbsentJustified) {
            return match ($absenceMode) {
                'zero_all' => 'zero',
                'zero_unjustified_only' => $state === ResultState::Absent ? 'zero' : 'exclude',
                default => 'exclude', // exclude_all, exclude_all_warn
            };
        }

        // pending, exempt, not_applicable, annulled, under_review — out of the
        // fraction entirely (§5).
        return 'exclude';
    }

    protected function raisesCoverageWarning(ResultState $state, string $absenceMode): bool
    {
        if ($absenceMode === 'exclude_all_warn'
            && ($state === ResultState::Absent || $state === ResultState::AbsentJustified)) {
            return true;
        }

        return false;
    }
}
