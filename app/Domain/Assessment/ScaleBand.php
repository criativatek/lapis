<?php

namespace App\Domain\Assessment;

/**
 * One configured normalized-value band for a scale level, passed as plain data
 * so the calculation engine remains independent from Eloquent.
 */
final readonly class ScaleBand
{
    public function __construct(
        public int $id,
        public string $bandMin,
        public string $bandMax,
    ) {}
}
