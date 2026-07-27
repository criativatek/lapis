<?php

namespace App\Domain\Assessment;

/**
 * The engine's result for one (enrollment, scope): the per-domain outcomes, the
 * combined overall value, the rounded proposal, and a structured explanation of
 * exactly how it was reached (§13.5).
 *
 * normalizedValue / proposedValue are null when there was nothing to compute
 * (every element excluded) — the result state is then `pending`, with a coverage
 * warning. Never zero (§9 rule 5).
 */
final readonly class CalculationOutcome
{
    /**
     * @param  list<DomainOutcome>  $domains
     * @param  array<string, mixed>  $explanation
     */
    public function __construct(
        public array $domains,
        public ?string $normalizedValue,
        public ?string $proposedValue,
        public string $resultState,
        public bool $coverageWarning,
        public int $contributingCount,
        public ?int $scaleLevelId,
        public array $explanation,
    ) {}

    public function hasValue(): bool
    {
        return $this->normalizedValue !== null;
    }
}
