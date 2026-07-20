<?php

namespace App\Support\Assessment;

use App\Models\ResultState;
use RuntimeException;

/**
 * Thrown when a score would break the rule that an empty cell is never a zero
 * (§12.4, §13.3).
 *
 * A missing value and a value of zero mean different things to a student's
 * grade. Storing 0 for an absence silently turns "we have no data" into "the
 * student scored nothing" — a mark the teacher never gave.
 */
class EmptyIsNotZeroException extends RuntimeException
{
    public static function forState(ResultState $state): self
    {
        return new self(sprintf(
            'A score with state "%s" cannot carry a value. Only an assessed score has a number; '.
            'storing 0 here would turn missing data into a mark the teacher never gave.',
            $state->value,
        ));
    }

    public static function assessedWithoutValue(): self
    {
        return new self(
            'An assessed score must carry either points_earned or a scale level. '.
            'If there is no value yet, the state is "pending", not "assessed".'
        );
    }
}
