<?php

namespace App\Domain\Assessment\Analysis;

/**
 * One student's observation in one analysis dimension (global or a domain).
 *
 * `key` identifies the student (an enrollment id as a string) for callers that
 * need to correlate observations across dimensions — the analyzer itself never
 * inspects it. `exact` is the engine's normalized value (percent-of-max, up to
 * 6 decimals) and is only non-null when `status` is `classified`. `partial`
 * marks a classified observation that still has items excluded as
 * pending/under_review — it is classified, but not yet complete.
 */
final readonly class Observation
{
    public function __construct(
        public string $key,
        public ObservationStatus $status,
        public ?string $exact,
        public bool $partial = false,
    ) {}
}
