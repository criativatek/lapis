<?php

namespace App\Support\Assessment;

/**
 * Joins a profile's grade levels into one pt-PT display string
 * ("7.º", "7.º e 8.º", "7.º, 8.º e 9.º"), so this joining logic lives in
 * exactly one place — the backend hands the frontend a ready label instead of
 * every Vue page reimplementing its own comma-and-"e" copy.
 */
final class GradeLevelLabel
{
    /**
     * @param  list<string>  $gradeLevels
     */
    public static function forList(array $gradeLevels): string
    {
        $count = count($gradeLevels);

        return match (true) {
            $count === 0 => '',
            $count === 1 => $gradeLevels[0],
            default => implode(', ', array_slice($gradeLevels, 0, -1)).' e '.$gradeLevels[$count - 1],
        };
    }
}
