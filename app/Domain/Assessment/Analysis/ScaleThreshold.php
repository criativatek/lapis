<?php

namespace App\Domain\Assessment\Analysis;

use App\Domain\Assessment\Bc;

/**
 * Derives the boundary between "negative" and "non-negative" appreciations
 * from a scale's own bands — never a universal constant (design spec
 * addendum, Q3: the 49,5 % figure only coincided with the system 1–5 scale;
 * a custom scale may draw the line elsewhere, or may not draw one at all).
 *
 * A threshold exists only when the scale unambiguously separates the two
 * kinds of band: at least one negative band, at least one non-negative band,
 * and every negative band lies entirely below every non-negative band — no
 * gap-free interleaving, no overlap. When that holds, the threshold is the
 * lowest `min` among the non-negative bands: the exact point where the scale
 * itself stops being negative.
 *
 * Missing `is_negative` marks, a scale with no bands, or an ambiguous
 * (interleaved) layout all resolve to `null` — never a fallback, never a
 * guess.
 */
final class ScaleThreshold
{
    /**
     * @param  list<AnalysisBand>  $bands
     */
    public static function from(array $bands): ?string
    {
        if ($bands === []) {
            return null;
        }

        $negative = array_values(array_filter($bands, fn (AnalysisBand $band): bool => $band->isNegative));
        $nonNegative = array_values(array_filter($bands, fn (AnalysisBand $band): bool => ! $band->isNegative));

        if ($negative === [] || $nonNegative === []) {
            return null;
        }

        $maxNegative = self::max($negative);
        $minNonNegative = self::min($nonNegative);

        if (Bc::compare(Bc::of($maxNegative), Bc::of($minNonNegative)) >= 0) {
            return null;
        }

        // No interleaving: every negative band's max must be at or below the
        // lowest non-negative min (already true above) AND every negative
        // band's max must be below every non-negative band's min individually
        // — a negative band sitting between two non-negative bands would pass
        // the max/min comparison above while still being interleaved.
        foreach ($negative as $negativeBand) {
            foreach ($nonNegative as $nonNegativeBand) {
                if (Bc::compare(Bc::of($negativeBand->max), Bc::of($nonNegativeBand->min)) >= 0) {
                    return null;
                }
            }
        }

        return self::normalize($minNonNegative);
    }

    /**
     * @param  list<AnalysisBand>  $bands
     */
    private static function max(array $bands): string
    {
        $max = $bands[0]->max;
        foreach ($bands as $band) {
            if (Bc::compare(Bc::of($band->max), Bc::of($max)) > 0) {
                $max = $band->max;
            }
        }

        return $max;
    }

    /**
     * @param  list<AnalysisBand>  $bands
     */
    private static function min(array $bands): string
    {
        $min = $bands[0]->min;
        foreach ($bands as $band) {
            if (Bc::compare(Bc::of($band->min), Bc::of($min)) < 0) {
                $min = $band->min;
            }
        }

        return $min;
    }

    /**
     * Trim trailing zeros ("49.500000" → "49.5", "45.000000" → "45") without
     * ever touching the value's magnitude — bcmath strings only.
     */
    private static function normalize(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim($value, '0');
        $trimmed = rtrim($trimmed, '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
