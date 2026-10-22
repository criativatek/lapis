<?php

namespace App\Services\Assessment\Advanced;

use App\Services\Assessment\BuildResultsProgression;

/**
 * The movement between two figures of the timeline: the rule that already exists, plus the
 * CHANGE OF LEVEL.
 *
 * NO SECOND RULE FOR «SUBIU». The movement of a percentage is `BuildResultsProgression::
 * movement()` — the very function Resultados, Estatística and Evolução do Aluno use: any
 * change at the precision the figures are shown at, never a tolerance nobody chose (decisão
 * 3). What is added is the change of LEVEL, read on the scale's own order and its own line:
 * «passou do nível 2 para o 3», and — said apart, because it is the one change a school
 * cares about — «passou a nível positivo» / «passou a nível negativo».
 *
 * A LEVEL IS COMPARED ONLY WHEN THE SCALE IS DEMONSTRABLY THE SAME and both sides know
 * their place in it. Otherwise the answer is `unavailable`, with the reason, and never a
 * guess from the label: two scales may both have a «Bom».
 *
 * Pure: cells in, a verdict out. No database.
 */
final class TimelineMovement
{
    /**
     * A result or a domain, between two moments.
     *
     * @param  array{value: string|null, band: array<string, mixed>|null}|null  $before
     * @param  array{value: string|null, band: array<string, mixed>|null}|null  $after
     * @param  array{percentages: bool, levels: bool, reasons: list<array{code: string, text: string}>}  $comparability
     * @return array{comparable: bool, percentages: array{direction: string, points: string, previous: string, current: string}|null, level: array<string, mixed>}
     */
    public static function between(?array $before, ?array $after, array $comparability): array
    {
        return [
            'comparable' => $comparability['percentages'],
            // Only when the criteria are the same: a difference across a change of weights
            // is a change of rule, and is not reported as movement of the student.
            'percentages' => $comparability['percentages']
                ? BuildResultsProgression::movement($before['value'] ?? null, $after['value'] ?? null)
                : null,
            'level' => self::level($before['band'] ?? null, $after['band'] ?? null, $comparability['levels']),
        ];
    }

    /**
     * What was assigned, between two moments: the teacher's decision, level or value.
     *
     * @param  array<string, mixed>|null  $before  the `classification` of a student's cell
     * @param  array<string, mixed>|null  $after
     * @param  array{percentages: bool, levels: bool, reasons: list<array{code: string, text: string}>}  $comparability
     * @return array<string, mixed>
     */
    public static function classification(?array $before, ?array $after, array $comparability): array
    {
        $student = TimelineComparability::classification($before, $after);

        if (! $student['comparable']) {
            return self::unavailable($student['reason']['code'] ?? 'not_comparable');
        }

        $first = $before['assigned'] ?? null;
        $second = $after['assigned'] ?? null;

        if ($first !== null && $second !== null) {
            return self::level($first, $second, $comparability['levels']);
        }

        // A scale of values (0–20): the grade IS the number. Compared only on the same scale.
        $firstValue = $before['assigned_value'] ?? null;
        $secondValue = $after['assigned_value'] ?? null;

        if ($first === null && $second === null && $firstValue !== null && $secondValue !== null) {
            if (! $comparability['levels']) {
                return self::unavailable('not_comparable');
            }

            $movement = BuildResultsProgression::movement($firstValue, $secondValue);

            return [
                'state' => $movement === null ? 'unavailable' : ['up' => 'up', 'down' => 'down', 'flat' => 'same'][$movement['direction']],
                'from' => null,
                'to' => null,
                'from_value' => $firstValue,
                'to_value' => $secondValue,
                'crossed_line' => null,
                'reason' => $movement === null ? 'missing' : null,
            ];
        }

        return self::unavailable('missing');
    }

    /**
     * The change of level between two bands of the same scale.
     *
     * @param  array{code?: string|null, label?: string|null, sequence?: int|null, is_negative?: bool|null}|null  $before
     * @param  array{code?: string|null, label?: string|null, sequence?: int|null, is_negative?: bool|null}|null  $after
     * @return array{state: string, from: array<string, mixed>|null, to: array<string, mixed>|null, crossed_line: string|null, reason: string|null}
     */
    public static function level(?array $before, ?array $after, bool $comparable): array
    {
        if ($before === null || $after === null) {
            return self::unavailable('missing');
        }

        if (! $comparable) {
            return self::unavailable('not_comparable');
        }

        // Without a place in the scale a level has no direction: it is named, not ranked.
        if (($before['sequence'] ?? null) === null || ($after['sequence'] ?? null) === null) {
            return self::unavailable('no_sequence');
        }

        $state = match (true) {
            $after['sequence'] > $before['sequence'] => 'up',
            $after['sequence'] < $before['sequence'] => 'down',
            default => 'same',
        };

        $crossed = null;

        if (($before['is_negative'] ?? null) === true && ($after['is_negative'] ?? null) === false) {
            $crossed = 'to_positive';
        } elseif (($before['is_negative'] ?? null) === false && ($after['is_negative'] ?? null) === true) {
            $crossed = 'to_negative';
        }

        return ['state' => $state, 'from' => $before, 'to' => $after, 'crossed_line' => $crossed, 'reason' => null];
    }

    /**
     * @return array{state: string, from: null, to: null, crossed_line: null, reason: string}
     */
    private static function unavailable(string $reason): array
    {
        return ['state' => 'unavailable', 'from' => null, 'to' => null, 'crossed_line' => null, 'reason' => $reason];
    }
}
