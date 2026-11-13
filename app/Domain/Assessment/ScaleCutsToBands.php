<?php

namespace App\Domain\Assessment;

use InvalidArgumentException;

/**
 * Converts the teacher's «a partir de X %» cuts into the normalized bands a
 * scale level stores — the ONLY place that does it.
 *
 * Cuts are integers `c1 = 0 < c2 < … < cN ≤ 100`. Level `i` starts half a
 * point below its cut (`ci − 0.5`) and ends one millionth below the half point
 * under the next cut (`c(i+1) − 0.500001`); the first starts at 0 and the last
 * ends at 100. It is exactly the convention of `SystemScalesSeeder` (cut 50 →
 * min 49.500000, previous max 49.499999), so the default cuts 0/20/50/70/90
 * reproduce the system «Escala 1 a 5» field by field.
 *
 * Integer arithmetic in millionths throughout — a band is a DECIMAL(,6) and
 * never passes through a float.
 */
final class ScaleCutsToBands
{
    private const MILLIONTHS_PER_PERCENT = 1_000_000;

    /**
     * @param  list<int>  $cuts  one cut per level, by position
     * @return list<array{min: string, max: string}>
     */
    public static function convert(array $cuts): array
    {
        $count = count($cuts);

        if ($count < 1 || $cuts[0] !== 0) {
            throw new InvalidArgumentException('The first cut must be 0.');
        }

        for ($index = 1; $index < $count; $index++) {
            if ($cuts[$index] <= $cuts[$index - 1] || $cuts[$index] > 100) {
                throw new InvalidArgumentException('Cuts must be strictly increasing and at most 100.');
            }
        }

        $bands = [];

        foreach ($cuts as $index => $cut) {
            $min = $index === 0 ? 0 : $cut * self::MILLIONTHS_PER_PERCENT - 500_000;
            $max = $index === $count - 1
                ? 100 * self::MILLIONTHS_PER_PERCENT
                : $cuts[$index + 1] * self::MILLIONTHS_PER_PERCENT - 500_001;

            $bands[] = ['min' => self::format($min), 'max' => self::format($max)];
        }

        return $bands;
    }

    private static function format(int $millionths): string
    {
        return sprintf('%d.%06d', intdiv($millionths, self::MILLIONTHS_PER_PERCENT), $millionths % self::MILLIONTHS_PER_PERCENT);
    }
}
