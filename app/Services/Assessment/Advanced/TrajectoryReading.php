<?php

namespace App\Services\Assessment\Advanced;

use App\Services\Assessment\BuildResultsProgression;

/**
 * One trajectory — a result, a domain, a class mean — read for TWO different questions that are never
 * mixed: what its TREND is, and whether it is PERSISTENTLY below the positive level.
 *
 * A TREND NEEDS THREE POINTS. Of the moments of the series that have a value, at least three; the
 * steps between consecutive ones are the rule Resultados already has (`BuildResultsProgression::
 * movement()`: any change at the precision shown, no tolerance nobody chose). Then, and only then:
 *  - `consistent_up`: every step went up;
 *  - `consistent_down`: every step went down;
 *  - `stable`: every step is equal at the precision shown;
 *  - `variable`: anything else — ups and downs, or a pause among them.
 * Fewer than three values is `insufficient`, said as such: two points are a comparison, not a trend.
 *
 * A PERSISTENT NEGATIVE RESULT IS NOT A TREND. It is the plainest fact there is, and it needs only two
 * moments: the result is below the positive level at the MOST RECENT moment and at the one before it —
 * consecutive moments, counted back from the latest while each is negative. It is not a forecast, not a
 * cause and not a finding about the child: it says «abaixo do nível positivo em N momentos
 * consecutivos», with the values and the moments, and stops.
 *
 *  - the latest moment must have a value: a result that is not there is not «still negative» (`no_recent`
 *    when the nearest earlier value was below the line and this cannot be confirmed, `no_value` when there
 *    is nothing negative to confirm);
 *  - a moment without a value, or one the series left out (other criteria, another scale), BREAKS the
 *    run: the application does not guess what happened in the hole;
 *  - a band the scale cannot place (`is_negative` unknown) is `unknown`, never «not negative».
 *
 * MISSING IS NEVER ZERO. A moment without a value is a gap — named, and never counted, never filled.
 *
 * Pure: entries in (`TimelineSeries::entries()`), a reading out.
 */
final class TrajectoryReading
{
    public const MINIMUM_FOR_TREND = BuildAssessmentTimeline::MINIMUM_FOR_TREND;

    public const MINIMUM_FOR_PERSISTENCE = 2;

    /**
     * @param  list<array{key: string, label: string, comparable: bool, value: string|null, band: array<string, mixed>|null}>  $entries  every moment of the series, oldest first
     * @return array{values: list<string|null>, negative: list<bool|null>, trend: array<string, mixed>, persistent: array<string, mixed>}
     */
    public static function read(array $entries): array
    {
        return [
            'values' => array_map(fn (array $entry): ?string => $entry['value'], $entries),
            // Below the positive line at that moment, on that moment's own scale: true / false / null (no value or no line).
            'negative' => array_map(fn (array $entry): ?bool => $entry['value'] === null ? null : ($entry['band']['is_negative'] ?? null), $entries),
            'trend' => self::trend($entries),
            'persistent' => self::persistent($entries),
        ];
    }

    /**
     * @param  list<array{key: string, label: string, comparable: bool, value: string|null, band: array<string, mixed>|null}>  $entries
     * @return array<string, mixed>
     */
    public static function trend(array $entries): array
    {
        $usable = array_values(array_filter($entries, fn (array $entry): bool => $entry['comparable'] && $entry['value'] !== null));

        if (count($usable) < self::MINIMUM_FOR_TREND) {
            return ['state' => 'insufficient', 'with_value' => count($usable), 'steps' => [], 'counts' => ['up' => 0, 'flat' => 0, 'down' => 0], 'gaps' => []];
        }

        $steps = [];
        $counts = ['up' => 0, 'flat' => 0, 'down' => 0];

        for ($index = 1; $index < count($usable); $index++) {
            $movement = BuildResultsProgression::movement($usable[$index - 1]['value'], $usable[$index]['value']);

            // Both ends have a value, so there is always a movement; the guard is for the type, not for the data.
            if ($movement === null) {
                continue;
            }

            $counts[$movement['direction']]++;
            $steps[] = [
                'from' => $usable[$index - 1]['label'],
                'to' => $usable[$index]['label'],
                'direction' => $movement['direction'],
                'points' => $movement['points'],
                'previous' => $movement['previous'],
                'current' => $movement['current'],
            ];
        }

        $total = count($steps);

        $state = match (true) {
            $counts['up'] === $total => 'consistent_up',
            $counts['down'] === $total => 'consistent_down',
            $counts['flat'] === $total => 'stable',
            default => 'variable',
        };

        // Moments between the first and the last that did not take part: without a value, or left out.
        $first = array_search($usable[0]['key'], array_column($entries, 'key'), true);
        $last = array_search($usable[count($usable) - 1]['key'], array_column($entries, 'key'), true);
        $gaps = [];

        foreach (array_slice($entries, (int) $first, (int) $last - (int) $first + 1) as $entry) {
            if (! $entry['comparable']) {
                $gaps[] = ['label' => $entry['label'], 'reason' => 'left_out'];
            } elseif ($entry['value'] === null) {
                $gaps[] = ['label' => $entry['label'], 'reason' => 'no_value'];
            }
        }

        return ['state' => $state, 'with_value' => count($usable), 'steps' => $steps, 'counts' => $counts, 'gaps' => $gaps];
    }

    /**
     * @param  list<array{key: string, label: string, comparable: bool, value: string|null, band: array<string, mixed>|null}>  $entries
     * @return array<string, mixed>
     */
    public static function persistent(array $entries): array
    {
        $none = fn (string $state, string $stoppedBy = 'start'): array => ['state' => $state, 'run' => 0, 'points' => [], 'stopped_by' => $stoppedBy];

        if (count($entries) < self::MINIMUM_FOR_PERSISTENCE) {
            return $none('insufficient');
        }

        $latest = $entries[count($entries) - 1];

        if ($latest['value'] === null) {
            // The latest moment has no value. If the nearest earlier value WAS below the line, «still» cannot be
            // confirmed (and the reading says so); if it was not, or there is none, there is nothing to say.
            foreach (array_reverse(array_slice($entries, 0, -1)) as $entry) {
                if ($entry['comparable'] && $entry['value'] !== null) {
                    return ($entry['band']['is_negative'] ?? null) === true ? $none('no_recent', 'no_value') : $none('no_value', 'no_value');
                }
            }

            return $none('insufficient', 'no_value');
        }

        if (($latest['band']['is_negative'] ?? null) === null) {
            return $none('unknown', 'unknown_band');
        }

        if ($latest['band']['is_negative'] === false) {
            return $none('no', 'positive');
        }

        $run = [];
        $stoppedBy = 'start';

        for ($index = count($entries) - 1; $index >= 0; $index--) {
            $entry = $entries[$index];

            $stoppedBy = match (true) {
                ! $entry['comparable'] => 'left_out',
                $entry['value'] === null => 'no_value',
                ($entry['band']['is_negative'] ?? null) === null => 'unknown_band',
                $entry['band']['is_negative'] === false => 'positive',
                default => null,
            };

            if ($stoppedBy !== null) {
                break;
            }

            array_unshift($run, ['label' => $entry['label'], 'value' => $entry['value'], 'band' => $entry['band']]);
            $stoppedBy = 'start';
        }

        return [
            'state' => count($run) >= self::MINIMUM_FOR_PERSISTENCE ? 'yes' : 'no',
            'run' => count($run),
            'points' => $run,
            'stopped_by' => $stoppedBy,
        ];
    }
}
