<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\ScaleProposal;
use App\Models\Scale;
use App\Models\ScaleLevel;

/**
 * Turns the engine's normalized percentage into the proposal on the profile's
 * own scale — the single place that translation happens.
 *
 * It reads the scale as configured and nothing else: no "1 to 5 for basic
 * education", no "percentage divided by five" for a 0-20. A scale that has not
 * been given bands cannot classify, and saying so is the correct answer
 * (§10.4). Inventing a threshold would put a number in front of the teacher
 * that no approved rule produced.
 */
class ScaleProposalResolver
{
    /**
     * @param  int|null  $scaleLevelId  the band the engine matched, if any
     * @param  string|null  $normalizedValue  null when there was nothing to compute
     * @param  string|null  $roundedValue  the engine's rounded percentage
     * @param  string  $roundingMode  the profile version's own rounding rule
     */
    public function resolve(
        ?Scale $scale,
        ?int $scaleLevelId,
        ?string $normalizedValue,
        ?string $roundedValue,
        string $roundingMode = 'half_up',
        int $roundingScale = 0,
    ): ScaleProposal {
        // No elements is not a low classification — it is the absence of one.
        if ($normalizedValue === null) {
            return ScaleProposal::noResult();
        }

        if ($scale === null) {
            return ScaleProposal::unconfigured();
        }

        // A percentage scale classifies IN percent: here the normalized value is
        // already expressed on the scale, and rounding it is the translation.
        if ($scale->kind === 'percentage') {
            return $roundedValue === null
                ? ScaleProposal::unconfigured()
                : new ScaleProposal($this->trim($roundedValue), ScaleProposal::RESOLVED, isPercentage: true);
        }

        // A numeric scale spans a continuous interval and needs no qualitative
        // bands to place a result in it: the interval itself is the definition.
        // Bands, when a numeric scale happens to have them, still win — they are
        // a more specific statement than the interval.
        if ($scale->kind === 'numeric' && $scaleLevelId === null) {
            return $this->onNumericInterval($scale, $normalizedValue, $roundingMode, $roundingScale);
        }

        if ($scaleLevelId === null) {
            // A level scale with no band for this result. Placing it would mean
            // inventing a threshold, which is exactly what is forbidden.
            return ScaleProposal::unconfigured();
        }

        $level = $scale->levels->firstWhere('id', $scaleLevelId)
            ?? ScaleLevel::find($scaleLevelId);

        if (! $level instanceof ScaleLevel) {
            return ScaleProposal::unconfigured();
        }

        // numeric_value is what a 1-5 or 0-20 level is called; a purely
        // qualitative level has none and is named by its label instead (§10.4).
        $value = $level->numeric_value !== null
            ? $this->trim((string) $level->numeric_value)
            : $level->label;

        return new ScaleProposal($value, ScaleProposal::RESOLVED);
    }

    /**
     * Places a normalized percentage on a numeric scale's own interval.
     *
     *   value = min + (normalized / 100) × (max − min)
     *
     * Explicit and generic: the scale's own min_value and max_value, never a
     * "divide by five" that only holds for 0-20, and never an assumption about
     * what level of schooling uses which interval. A 1-20, a 0-10 and a 5-15
     * all go through the same arithmetic.
     *
     * Decimal throughout (§24.4) and rounded by the profile version's own rule,
     * so the proposal obeys the same rounding the teacher configured.
     */
    protected function onNumericInterval(
        Scale $scale,
        string $normalizedValue,
        string $roundingMode,
        int $roundingScale,
    ): ScaleProposal {
        $min = Bc::of((string) $scale->min_value);
        $max = Bc::of((string) $scale->max_value);

        // A degenerate interval cannot place anything.
        if (Bc::compare($max, $min) <= 0) {
            return ScaleProposal::unconfigured();
        }

        $span = Bc::sub($max, $min);
        $fraction = Bc::div(Bc::of($normalizedValue), '100');
        $value = Bc::add($min, Bc::mul($fraction, $span));

        // The column is CHECK-constrained to this set, but narrowing it here
        // keeps the caller honest and falls back the same way the calculator does.
        $mode = match ($roundingMode) {
            'half_up', 'half_down', 'half_even', 'ceil', 'floor', 'none' => $roundingMode,
            default => 'half_up',
        };

        return new ScaleProposal(
            $this->trim(Bc::round($value, $roundingScale, $mode)),
            ScaleProposal::RESOLVED,
        );
    }

    /**
     * "4.000" reads as 4, "16.500" as 16,5 — the stored decimal scale is a
     * storage detail, not something to show a teacher.
     */
    protected function trim(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}
