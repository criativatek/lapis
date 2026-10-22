<?php

namespace App\Services\Assessment\Advanced;

/**
 * The moments a trajectory is read across: the ones that may be put in the same line.
 *
 * THE LATEST MOMENT DECIDES. A trend and a persistent negative result are statements about where
 * something stands NOW and how it got there, so the series is anchored on the most recent moment with
 * results and keeps only the moments that are DEMONSTRABLY under the same criteria and the same scale
 * as it (`TimelineComparability`, both signatures). A moment under other weights, another scale or a
 * scale that cannot be shown, is not in the line: it is listed as left out, with the reason in words,
 * and it breaks the run it sits in — the application does not read across a change of rule.
 *
 * ONLY MOMENTS WITH RESULTS. A period that has no result for anyone is not a moment of the line (it is
 * not offered anywhere else either); a student without a value at a moment that has results is a
 * GAP for that student, which the reading says and never fills with a zero.
 *
 * Pure: the timeline in, the series out. No database, no clock.
 */
final class TimelineSeries
{
    /**
     * @param  array<string, mixed>  $timeline  as `BuildAssessmentTimeline::for()` wrote it
     * @return array{reference: array<string, mixed>, points: list<array<string, mixed>>, left_out: list<array<string, mixed>>, comparable_points: int}|null null when no moment has results
     */
    public static function of(array $timeline): ?array
    {
        $withResults = array_values(array_filter($timeline['points'], fn (array $point): bool => $point['has_results']));

        if ($withResults === []) {
            return null;
        }

        $reference = $withResults[count($withResults) - 1];
        $referenceSignatures = self::signatures($reference);

        $points = [];
        $leftOut = [];

        foreach ($withResults as $point) {
            $verdict = TimelineComparability::between(self::signatures($point), $referenceSignatures);
            $comparable = $point['key'] === $reference['key'] || ($verdict['percentages'] && $verdict['levels']);

            $entry = [
                'key' => $point['key'],
                'label' => $point['label'],
                'kind' => $point['kind'],
                'basis_kind' => $point['basis']['kind'],
                'basis_label' => $point['basis']['label'],
                'comparable' => $comparable,
            ];

            $points[] = $entry;

            if (! $comparable) {
                $leftOut[] = [
                    'key' => $point['key'],
                    'label' => $point['label'],
                    'reasons' => $verdict['reasons'],
                ];
            }
        }

        return [
            'reference' => $reference,
            'points' => $points,
            'left_out' => $leftOut,
            'comparable_points' => count(array_filter($points, fn (array $point): bool => $point['comparable'])),
        ];
    }

    /**
     * One entity's entries along the series: the value and band it had at each moment.
     *
     * @param  list<array<string, mixed>>  $points  `of()['points']`
     * @param  callable(string): (array<string, mixed>|null)  $figureAt  the entity's figure at a point key
     * @return list<array{key: string, label: string, comparable: bool, value: string|null, band: array<string, mixed>|null}>
     */
    public static function entries(array $points, callable $figureAt): array
    {
        $entries = [];

        foreach ($points as $point) {
            $figure = $figureAt($point['key']);
            $band = $figure['band'] ?? null;

            $entries[] = [
                'key' => (string) $point['key'],
                'label' => (string) $point['label'],
                'comparable' => (bool) $point['comparable'],
                'value' => isset($figure['value']) ? (string) $figure['value'] : null,
                'band' => is_array($band) ? $band : null,
            ];
        }

        return $entries;
    }

    /**
     * @return array{comparable_points: int, trends: bool, persistence: bool, reason: array<string, mixed>|string|null}
     */
    public static function availability(int $comparable): array
    {
        return [
            'comparable_points' => $comparable,
            'trends' => $comparable >= TrajectoryReading::MINIMUM_FOR_TREND,
            'persistence' => $comparable >= TrajectoryReading::MINIMUM_FOR_PERSISTENCE,
            'reason' => match (true) {
                $comparable >= TrajectoryReading::MINIMUM_FOR_TREND => null,
                $comparable === 2 => __('Há 2 momentos comparáveis: dá para ver resultados abaixo do nível positivo em momentos consecutivos, mas as tendências precisam de pelo menos 3.'),
                default => __('Há :count momento comparável: nem as tendências (3) nem os resultados negativos persistentes (2) se podem ler.', ['count' => $comparable]),
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array{percentage_signature: string|null, level_signature: string|null}
     */
    private static function signatures(array $point): array
    {
        return [
            'percentage_signature' => isset($point['percentage_signature']) ? (string) $point['percentage_signature'] : null,
            'level_signature' => isset($point['level_signature']) ? (string) $point['level_signature'] : null,
        ];
    }
}
