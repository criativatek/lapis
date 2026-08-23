<?php

namespace App\Services\Assessment;

/**
 * The outcome of one grid save, including the authoritative state needed by
 * the browser to continue editing without reloading the whole grid.
 */
final readonly class RecordScoresResult
{
    /**
     * @param  list<array{enrollment_id: int, instrument_item_id: int, lock_version: int}>  $versions
     * @param  list<array{enrollment_id: int, instrument_item_id: int, result_state: string, points_earned: float|null, state_reason: string|null, lock_version: int}>  $stale
     */
    public function __construct(
        public int $written,
        public array $versions,
        public array $stale,
    ) {}
}
