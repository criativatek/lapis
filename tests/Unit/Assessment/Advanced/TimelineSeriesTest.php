<?php

namespace Tests\Unit\Assessment\Advanced;

use App\Services\Assessment\Advanced\TimelineSeries;
use App\Services\Assessment\Advanced\TimelineStatistics;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TimelineSeriesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function point(string $key, int $ordinal, array $over = []): array
    {
        return [
            'key' => $key, 'kind' => 'period', 'label' => "Momento {$ordinal}", 'ordinal' => $ordinal,
            'basis' => ['kind' => 'live', 'label' => 'Calculado com os dados de hoje', 'as_of' => null],
            'level_signature' => 'L', 'percentage_signature' => 'P', 'has_results' => true, 'notices' => [],
            ...$over,
        ];
    }

    #[Test]
    public function the_series_is_anchored_on_the_latest_moment_with_results(): void
    {
        $series = TimelineSeries::of(['points' => [
            $this->point('a', 1), $this->point('b', 2), $this->point('c', 3), $this->point('d', 4, ['has_results' => false]),
        ]]);

        $this->assertSame('c', $series['reference']['key']);
        $this->assertSame(['a', 'b', 'c'], array_column($series['points'], 'key'), 'a moment with no result for anyone is not in the line');
        $this->assertSame(3, $series['comparable_points']);
        $this->assertSame([], $series['left_out']);
    }

    #[Test]
    public function a_moment_under_other_criteria_or_scale_is_left_out_with_the_reason_and_still_in_its_place(): void
    {
        $series = TimelineSeries::of(['points' => [
            $this->point('a', 1, ['percentage_signature' => 'OTHER']),
            $this->point('b', 2, ['level_signature' => 'OTHER']),
            $this->point('c', 3, ['level_signature' => null]),
            $this->point('d', 4),
        ]]);

        $this->assertSame(['a', 'b', 'c', 'd'], array_column($series['points'], 'key'));
        $this->assertSame([false, false, false, true], array_column($series['points'], 'comparable'));
        $this->assertSame(1, $series['comparable_points']);

        $reasons = [];
        foreach ($series['left_out'] as $left) {
            $reasons[$left['key']] = array_column($left['reasons'], 'code');
        }
        $this->assertSame(['criteria_differ'], $reasons['a']);
        $this->assertSame(['scale_differs'], $reasons['b']);
        $this->assertSame(['scale_unknown'], $reasons['c']);
    }

    #[Test]
    public function a_moment_that_cannot_say_what_it_is_made_of_is_never_assumed_comparable(): void
    {
        $series = TimelineSeries::of(['points' => [
            $this->point('a', 1, ['percentage_signature' => null, 'level_signature' => null]),
            $this->point('b', 2, ['percentage_signature' => null, 'level_signature' => null]),
        ]]);

        // Two unknowns are not the same: only the reference itself is in the line.
        $this->assertSame([false, true], array_column($series['points'], 'comparable'));
    }

    #[Test]
    public function with_no_result_anywhere_there_is_no_series(): void
    {
        $this->assertNull(TimelineSeries::of(['points' => []]));
        $this->assertNull(TimelineSeries::of(['points' => [$this->point('a', 1, ['has_results' => false])]]));
    }

    #[Test]
    public function an_entity_s_entries_follow_the_points_and_a_missing_figure_is_a_missing_value(): void
    {
        $series = TimelineSeries::of(['points' => [$this->point('a', 1), $this->point('b', 2)]]);

        $entries = TimelineSeries::entries($series['points'], fn (string $key): ?array => $key === 'a' ? ['value' => '60.0', 'band' => ['is_negative' => false]] : null);

        $this->assertSame(['60.0', null], array_column($entries, 'value'));
        $this->assertSame([['is_negative' => false], null], array_column($entries, 'band'));
        $this->assertSame([true, true], array_column($entries, 'comparable'));
    }

    #[Test]
    public function the_mean_is_the_sum_and_divide_of_the_statistics_and_an_empty_set_has_none(): void
    {
        $this->assertNull(TimelineStatistics::mean([]));
        $this->assertSame('70.0', TimelineStatistics::mean(['60.0', '80.0']));
        $this->assertSame('66.7', TimelineStatistics::mean(['60', '60', '80']));
        $this->assertSame('0.1', TimelineStatistics::mean(['0.05']), 'half-up at one decimal');
    }
}
