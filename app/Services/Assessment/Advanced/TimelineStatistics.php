<?php

namespace App\Services\Assessment\Advanced;

use App\Domain\Assessment\Bc;
use App\Services\Assessment\BuildResultsProgression;

/**
 * The one mean the advanced readings use: the same sum-and-divide, rounded the same way, as
 * `BuildClassStatistics::mean()`.
 *
 * ONE COPY. The class comparison, the strengths of a moment and the trajectories of a class all say
 * «a média da turma»; three private copies of an eight-line function is how three readings would come
 * to disagree about the same figure in the last decimal.
 *
 * An empty set has no mean (`null`), never zero: a class with no value at a moment did not score zero.
 */
final class TimelineStatistics
{
    /**
     * @param  list<string>  $values
     */
    public static function mean(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $total = '0';
        foreach ($values as $value) {
            $total = Bc::add($total, Bc::of($value));
        }

        return Bc::round(Bc::div($total, (string) count($values)), BuildResultsProgression::PRECISION, 'half_up');
    }
}
