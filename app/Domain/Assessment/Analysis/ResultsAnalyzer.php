<?php

namespace App\Domain\Assessment\Analysis;

use App\Domain\Assessment\Bc;

/**
 * Descriptive statistics over a list of Observations — no Eloquent, no dates,
 * no second engine. Every number here is derived from `Observation::$exact`,
 * the value the calculation engine already produced (design spec §3, §9).
 *
 * Rules (design spec §3):
 *  - denominators are the CLASSIFIED observations of the dimension;
 *  - out_of_scope observations are outside the universe entirely;
 *  - the 49,5 % threshold is applied to the EXACT value, never the rounded one;
 *  - mean/median are computed on exact values (bcmath) and rounded only for
 *    display (1 decimal, half_up — the app's one rounding rule for display);
 *  - the quantitative distribution has 10 fixed classes over the exact value;
 *  - the qualitative distribution has one category per scale band, in the
 *    scale's own sequence, matched inclusively — never fixed intervals.
 */
final class ResultsAnalyzer
{
    public const DEFAULT_THRESHOLD = '49.5';

    public const DISPLAY_SCALE = 1;

    public const DISPLAY_ROUNDING = 'half_up';

    /**
     * @param  list<Observation>  $observations
     * @param  list<AnalysisBand>  $bands
     * @return array<string, mixed>
     */
    public function analyse(array $observations, array $bands, string $threshold = self::DEFAULT_THRESHOLD): array
    {
        $universe = 0;
        $classified = [];
        $partial = 0;
        $outOfScope = 0;
        $missing = [
            'pending' => 0,
            'under_review' => 0,
            'absent' => 0,
            'absent_justified' => 0,
            'exempt' => 0,
            'not_applicable' => 0,
            'annulled' => 0,
        ];

        foreach ($observations as $observation) {
            if ($observation->status === ObservationStatus::OutOfScope) {
                $outOfScope++;

                continue;
            }

            $universe++;

            if ($observation->status === ObservationStatus::Classified) {
                // A classified observation always carries an exact value — the
                // engine never returns `classified` without one.
                $classified[] = (string) $observation->exact;

                if ($observation->partial) {
                    $partial++;
                }

                continue;
            }

            $missing[$observation->status->value]++;
        }

        $missingTotal = array_sum($missing);

        return [
            'universe' => $universe,
            'classified' => count($classified),
            'partial' => $partial,
            'out_of_scope' => $outOfScope,
            'missing' => ['total' => $missingTotal, ...$missing],
            'mean' => $this->mean($classified),
            'median' => $this->median($classified),
            'min' => $this->displayValue($this->min($classified)),
            'max' => $this->displayValue($this->max($classified)),
            'threshold' => $this->thresholdBlock($classified, $threshold),
            'quantitative' => $this->quantitative($classified, $threshold),
            'qualitative' => $this->qualitative($classified, $bands),
        ];
    }

    /**
     * @param  list<string>  $values
     */
    protected function mean(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $sum = '0';
        foreach ($values as $value) {
            $sum = Bc::add($sum, Bc::of($value));
        }

        return $this->displayValue(Bc::div($sum, (string) count($values)));
    }

    /**
     * @param  list<string>  $values
     */
    protected function median(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $sorted = $values;
        usort($sorted, fn (string $a, string $b): int => Bc::compare(Bc::of($a), Bc::of($b)));

        $count = count($sorted);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $this->displayValue($sorted[$middle]);
        }

        $exact = Bc::div(Bc::add(Bc::of($sorted[$middle - 1]), Bc::of($sorted[$middle])), '2');

        return $this->displayValue($exact);
    }

    /**
     * @param  list<string>  $values
     */
    protected function min(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $min = $values[0];
        foreach ($values as $value) {
            if (Bc::compare(Bc::of($value), Bc::of($min)) < 0) {
                $min = $value;
            }
        }

        return $min;
    }

    /**
     * @param  list<string>  $values
     */
    protected function max(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $max = $values[0];
        foreach ($values as $value) {
            if (Bc::compare(Bc::of($value), Bc::of($max)) > 0) {
                $max = $value;
            }
        }

        return $max;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    protected function thresholdBlock(array $values, string $threshold): array
    {
        $denominator = count($values);
        $below = 0;
        $atOrAbove = 0;

        foreach ($values as $value) {
            if (Bc::compare(Bc::of($value), Bc::of($threshold)) < 0) {
                $below++;
            } else {
                $atOrAbove++;
            }
        }

        return [
            'value' => $threshold,
            'below' => ['count' => $below, 'percent' => $this->percent($below, $denominator)],
            'at_or_above' => ['count' => $atOrAbove, 'percent' => $this->percent($atOrAbove, $denominator)],
        ];
    }

    /**
     * The 10 fixed quantitative classes [0,10[ … [80,90[ [90,100], over the
     * exact value. Bonus items can push a value above 100 — it lands in the
     * last, closed class. A value below 0 is not possible in practice, but is
     * clamped into the first class defensively.
     *
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    protected function quantitative(array $values, string $threshold): array
    {
        $bounds = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
        $counts = array_fill(0, 10, 0);

        foreach ($values as $value) {
            $counts[$this->quantitativeClassIndex($value)]++;
        }

        $total = count($values);
        $classes = [];

        foreach ($bounds as $index => $upper) {
            $lower = $index === 0 ? 0 : $bounds[$index - 1];
            $isLast = $index === count($bounds) - 1;
            $label = $isLast ? "[{$lower}, {$upper}]" : "[{$lower}, {$upper}[";

            $classes[] = [
                'key' => (string) $index,
                'label' => $label,
                'count' => $counts[$index],
                'percent' => $this->percent($counts[$index], $total),
                // A class is "below threshold" when it lies ENTIRELY below the
                // threshold (its upper bound is at or under it). A class that
                // straddles the threshold (e.g. [40,50[ against 49,5) is not
                // flagged — its members are a mix, and the threshold block
                // above is the correct place to read the split.
                'below_threshold' => Bc::compare((string) $upper, Bc::of($threshold)) <= 0,
            ];
        }

        return ['total' => $total, 'classes' => $classes];
    }

    protected function quantitativeClassIndex(string $value): int
    {
        $normalized = Bc::of($value);

        if (Bc::compare($normalized, '0') <= 0) {
            return 0;
        }

        if (Bc::compare($normalized, '100') >= 0) {
            return 9;
        }

        $index = (int) bcdiv($normalized, '10', 0);

        return min(9, max(0, $index));
    }

    /**
     * One category per band, in the scale's own sequence order, matched
     * inclusively on the exact value — the same semantics as
     * ScaleProposalResolver::bandFor() (first match wins). A scale with no
     * bands makes the qualitative distribution unavailable, never a guess
     * with fixed intervals.
     *
     * @param  list<string>  $values
     * @param  list<AnalysisBand>  $bands
     * @return array<string, mixed>
     */
    protected function qualitative(array $values, array $bands): array
    {
        if ($bands === []) {
            return ['available' => false, 'total' => 0, 'unplaced' => 0, 'categories' => []];
        }

        $total = count($values);
        $counts = [];
        foreach ($bands as $band) {
            $counts[$band->id] = 0;
        }
        $unplaced = 0;

        foreach ($values as $value) {
            $matched = null;
            foreach ($bands as $band) {
                if (Bc::compare(Bc::of($value), Bc::of($band->min)) >= 0
                    && Bc::compare(Bc::of($value), Bc::of($band->max)) <= 0) {
                    $matched = $band->id;

                    break;
                }
            }

            if ($matched === null) {
                $unplaced++;
            } else {
                $counts[$matched]++;
            }
        }

        $categories = [];
        foreach ($bands as $band) {
            $categories[] = [
                'key' => (string) $band->id,
                'code' => $band->code,
                'label' => $band->label,
                'sequence' => $band->sequence,
                'is_negative' => $band->isNegative,
                'count' => $counts[$band->id],
                'percent' => $this->percent($counts[$band->id], $total),
            ];
        }

        return ['available' => true, 'total' => $total, 'unplaced' => $unplaced, 'categories' => $categories];
    }

    protected function percent(int $count, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }

        $exact = Bc::mul(Bc::div((string) $count, (string) $denominator), '100');

        return Bc::round($exact, self::DISPLAY_SCALE, self::DISPLAY_ROUNDING);
    }

    protected function displayValue(?string $exact): ?string
    {
        if ($exact === null) {
            return null;
        }

        return Bc::round(Bc::of($exact), self::DISPLAY_SCALE, self::DISPLAY_ROUNDING);
    }
}
