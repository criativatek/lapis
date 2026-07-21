<?php

namespace App\Domain\Assessment;

use App\Models\ResultState;

/**
 * One student's score on one item, as plain data for the engine.
 *
 * `eligible` is resolved by the caller: it is false when the instrument does not
 * count, is in a non-entering state, or does not apply to this enrollment (late
 * entry before the instrument's date, or an explicit exclusion). The engine does
 * not touch dates — that derivation (§11.4, A3) happens before it.
 */
final readonly class ScoreInput
{
    /**
     * @param  list<array{domain_id: int, allocation_percent: string}>  $allocations
     */
    public function __construct(
        public int $instrumentId,
        public string $itemCode,
        public string $pointsPossible,
        public ResultState $state,
        public ?string $pointsEarned,
        public bool $isBonus,
        public bool $eligible,
        public array $allocations,
    ) {}
}
