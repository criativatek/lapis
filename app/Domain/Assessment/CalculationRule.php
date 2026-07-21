<?php

namespace App\Domain\Assessment;

/**
 * The frozen rule the engine calculates under — the fields copied from an
 * activated assessment_profile_version (ADR-0004). Passed in, never read from a
 * model inside the engine, so the engine stays pure and testable (§13.1).
 */
final readonly class CalculationRule
{
    /**
     * @param  'exclude_all'|'zero_all'|'zero_unjustified_only'|'exclude_all_warn'  $absenceMode
     * @param  'half_up'|'half_down'|'half_even'|'ceil'|'floor'|'none'  $roundingMode
     * @param  'final_only'|'each_domain'|'each_stage'  $roundingStage
     */
    public function __construct(
        public string $absenceMode = 'exclude_all_warn',
        public string $roundingMode = 'half_up',
        public int $roundingScale = 0,
        public string $roundingStage = 'final_only',
    ) {}
}
