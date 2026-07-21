<?php

namespace App\Domain\Assessment;

/**
 * The engine's result for one domain. normalizedValue is null when the domain had
 * no eligible elements — "sem elementos", never zero (§9 rule 5). Values are
 * percent-of-max strings, truncated to 6 decimals.
 */
final readonly class DomainOutcome
{
    public function __construct(
        public int $domainId,
        public ?string $normalizedValue,
        public string $pointsEarned,
        public string $pointsPossible,
        public string $weightPercent,
        public int $contributingCount,
        public bool $coverageWarning,
    ) {}

    public function hasValue(): bool
    {
        return $this->normalizedValue !== null;
    }
}
