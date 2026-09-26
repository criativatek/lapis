<?php

namespace App\Domain\Assessment\Analysis;

/**
 * One level of the class's scale, as the analyzer needs it: identity, the
 * label to show, its position in the scale's own order, and the inclusive
 * band it matches on the exact normalized value — the same semantics as
 * ScaleProposalResolver::bandFor(). min/max are decimal strings; a band
 * without either is not a valid AnalysisBand (levels without bands are
 * filtered out by the caller, per design spec §3.7).
 */
final readonly class AnalysisBand
{
    public function __construct(
        public int|string $id,
        public string $code,
        public string $label,
        public int $sequence,
        public bool $isNegative,
        public string $min,
        public string $max,
    ) {}
}
