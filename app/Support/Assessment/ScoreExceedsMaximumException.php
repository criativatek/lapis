<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Thrown when a submitted score is higher than the item's own points_possible.
 * A question's own maximum is a hard ceiling regardless of is_bonus — bonus
 * only excuses an item from the denominator (§4.2), it never raises what a
 * single question can itself be worth.
 */
class ScoreExceedsMaximumException extends RuntimeException
{
    public static function make(string $itemCode, string $pointsEarned, string $pointsPossible): self
    {
        return new self(__(
            'A nota :earned na questão :code excede a cotação máxima (:max).',
            ['earned' => $pointsEarned, 'code' => $itemCode, 'max' => $pointsPossible],
        ));
    }
}
