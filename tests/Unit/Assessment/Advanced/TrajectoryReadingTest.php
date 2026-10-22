<?php

namespace Tests\Unit\Assessment\Advanced;

use App\Services\Assessment\Advanced\TrajectoryReading;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A trajectory read for two questions that are never mixed: its TREND (three points) and whether it is
 * PERSISTENTLY below the positive level (two moments, the latest included).
 */
class TrajectoryReadingTest extends TestCase
{
    private const NEG = ['code' => '2', 'label' => 'Insuficiente', 'sequence' => 2, 'is_negative' => true];

    private const POS = ['code' => '3', 'label' => 'Suficiente', 'sequence' => 3, 'is_negative' => false];

    /**
     * @param  list<string|null>  $values  oldest first; null = no value at that moment
     * @param  list<int>  $leftOut  positions of moments left out of the series
     * @return list<array<string, mixed>>
     */
    private function entries(array $values, array $leftOut = [], ?array $band = null): array
    {
        $entries = [];
        foreach ($values as $index => $value) {
            $entries[] = [
                'key' => "period:{$index}",
                'label' => 'Momento '.($index + 1),
                'comparable' => ! in_array($index, $leftOut, true),
                'value' => $value,
                'band' => $value === null ? null : ($band ?? ((float) $value < 50 ? self::NEG : self::POS)),
            ];
        }

        return $entries;
    }

    // ================================================================= trend

    #[Test]
    #[DataProvider('trends')]
    public function it_names_the_trend_from_the_steps_between_values(array $values, string $state, array $counts): void
    {
        $trend = TrajectoryReading::trend($this->entries($values));

        $this->assertSame($state, $trend['state']);
        $this->assertSame($counts, $trend['counts']);
        $this->assertCount(count($values) - 1, $trend['steps']);
    }

    /** @return iterable<string, array{list<string>, string, array<string, int>}> */
    public static function trends(): iterable
    {
        yield 'every step up' => [['60.0', '65.0', '70.5'], 'consistent_up', ['up' => 2, 'flat' => 0, 'down' => 0]];
        yield 'every step down' => [['70.5', '65.0', '60.0', '55.0'], 'consistent_down', ['up' => 0, 'flat' => 0, 'down' => 3]];
        yield 'every step equal at the precision shown' => [['60.0', '60.04', '59.96'], 'stable', ['up' => 0, 'flat' => 2, 'down' => 0]];
        yield 'up and down' => [['60.0', '70.0', '65.0'], 'variable', ['up' => 1, 'flat' => 0, 'down' => 1]];
        yield 'a pause among the ups is not consistent progression' => [['60.0', '60.0', '70.0'], 'variable', ['up' => 1, 'flat' => 1, 'down' => 0]];
        yield 'a tenth is already a step' => [['60.0', '60.1', '60.2'], 'consistent_up', ['up' => 2, 'flat' => 0, 'down' => 0]];
    }

    #[Test]
    public function two_points_are_a_comparison_not_a_trend(): void
    {
        $trend = TrajectoryReading::trend($this->entries(['60.0', '70.0']));

        $this->assertSame('insufficient', $trend['state']);
        $this->assertSame(2, $trend['with_value']);
        $this->assertSame([], $trend['steps']);
    }

    #[Test]
    public function a_missing_value_is_a_gap_never_a_zero_and_it_is_said(): void
    {
        // Three values among four moments: the trend is read on the three; the hole is named.
        $trend = TrajectoryReading::trend($this->entries(['60.0', null, '65.0', '70.0']));

        $this->assertSame('consistent_up', $trend['state']);
        $this->assertSame(3, $trend['with_value']);
        $this->assertSame([['label' => 'Momento 2', 'reason' => 'no_value']], $trend['gaps']);
        // The step across the hole is between the two values that exist (60 → 65), not through a zero.
        $this->assertSame(['60.0', '65.0'], [$trend['steps'][0]['previous'], $trend['steps'][0]['current']]);
    }

    #[Test]
    public function with_a_hole_the_values_that_are_left_are_not_enough(): void
    {
        $this->assertSame('insufficient', TrajectoryReading::trend($this->entries(['60.0', null, '70.0']))['state']);
        $this->assertSame('insufficient', TrajectoryReading::trend($this->entries([null, null, '70.0']))['state']);
    }

    #[Test]
    public function a_moment_left_out_of_the_series_does_not_take_part_and_is_named(): void
    {
        $trend = TrajectoryReading::trend($this->entries(['60.0', '99.0', '65.0', '70.0'], leftOut: [1]));

        $this->assertSame('consistent_up', $trend['state']);
        $this->assertSame(3, $trend['with_value']);
        $this->assertSame([['label' => 'Momento 2', 'reason' => 'left_out']], $trend['gaps']);

        // And it may not make a trend out of nothing: two comparable values and a left-out one are two.
        $this->assertSame('insufficient', TrajectoryReading::trend($this->entries(['60.0', '70.0', '80.0'], leftOut: [1]))['state']);
    }

    // ================================================================= persistent negative

    #[Test]
    public function two_consecutive_moments_below_the_line_including_the_latest_are_persistent(): void
    {
        $persistent = TrajectoryReading::persistent($this->entries(['40.0', '45.0']));

        $this->assertSame('yes', $persistent['state']);
        $this->assertSame(2, $persistent['run']);
        $this->assertSame(['Momento 1', 'Momento 2'], array_column($persistent['points'], 'label'));
        $this->assertSame(['40.0', '45.0'], array_column($persistent['points'], 'value'));
        $this->assertSame('start', $persistent['stopped_by']);
    }

    #[Test]
    public function the_run_is_counted_back_from_the_latest_and_stops_at_the_first_positive(): void
    {
        $persistent = TrajectoryReading::persistent($this->entries(['80.0', '40.0', '45.0', '30.0']));

        $this->assertSame('yes', $persistent['state']);
        $this->assertSame(3, $persistent['run']);
        $this->assertSame('positive', $persistent['stopped_by']);
        $this->assertSame(['Momento 2', 'Momento 3', 'Momento 4'], array_column($persistent['points'], 'label'));
    }

    #[Test]
    public function the_latest_moment_must_be_one_of_them(): void
    {
        // Negative twice, then positive at the latest: no longer below the line.
        $this->assertSame('no', TrajectoryReading::persistent($this->entries(['40.0', '45.0', '70.0']))['state']);
        $this->assertSame(0, TrajectoryReading::persistent($this->entries(['40.0', '45.0', '70.0']))['run']);

        // Negative only at the latest: one moment is not persistence.
        $single = TrajectoryReading::persistent($this->entries(['70.0', '75.0', '40.0']));
        $this->assertSame('no', $single['state']);
        $this->assertSame(1, $single['run']);
        $this->assertSame('positive', $single['stopped_by']);
    }

    #[Test]
    public function a_result_that_is_not_there_is_not_still_negative(): void
    {
        $persistent = TrajectoryReading::persistent($this->entries(['40.0', '45.0', null]));

        $this->assertSame('no_recent', $persistent['state']);
        $this->assertSame(0, $persistent['run']);
    }

    #[Test]
    public function with_nothing_negative_before_a_missing_latest_there_is_nothing_to_confirm(): void
    {
        // Positive before, no value now: not «no longer confirmed», simply nothing to say.
        $this->assertSame('no_value', TrajectoryReading::persistent($this->entries(['70.0', '75.0', null]))['state']);

        // No value anywhere: insufficient, not zero and not «not negative».
        $this->assertSame('insufficient', TrajectoryReading::persistent($this->entries([null, null, null]))['state']);
    }

    #[Test]
    public function a_hole_breaks_the_run_and_nothing_is_guessed_about_it(): void
    {
        $persistent = TrajectoryReading::persistent($this->entries(['40.0', null, '45.0']));

        $this->assertSame('no', $persistent['state']);
        $this->assertSame(1, $persistent['run']);
        $this->assertSame('no_value', $persistent['stopped_by']);
    }

    #[Test]
    public function a_moment_left_out_breaks_the_run_because_the_rule_changed_there(): void
    {
        $persistent = TrajectoryReading::persistent($this->entries(['40.0', '40.0', '45.0'], leftOut: [1]));

        $this->assertSame('no', $persistent['state']);
        $this->assertSame(1, $persistent['run']);
        $this->assertSame('left_out', $persistent['stopped_by']);
    }

    #[Test]
    public function a_band_the_scale_cannot_place_is_unknown_not_positive(): void
    {
        $unknown = ['code' => 'x', 'label' => 'Sem posição', 'sequence' => null, 'is_negative' => null];

        $this->assertSame('unknown', TrajectoryReading::persistent($this->entries(['40.0', '45.0'], band: $unknown))['state']);

        // An unknown band in the middle stops the run, as a hole would.
        $entries = $this->entries(['40.0', '45.0', '48.0']);
        $entries[1]['band'] = $unknown;
        $run = TrajectoryReading::persistent($entries);
        $this->assertSame('no', $run['state']);
        $this->assertSame('unknown_band', $run['stopped_by']);
    }

    #[Test]
    public function one_moment_alone_is_not_persistence_and_says_so(): void
    {
        $this->assertSame('insufficient', TrajectoryReading::persistent($this->entries(['40.0']))['state']);
        $this->assertSame('insufficient', TrajectoryReading::persistent([])['state']);
    }

    #[Test]
    public function a_persistent_negative_needs_two_where_a_trend_needs_three(): void
    {
        $reading = TrajectoryReading::read($this->entries(['40.0', '45.0']));

        $this->assertSame('yes', $reading['persistent']['state']);
        $this->assertSame('insufficient', $reading['trend']['state']);
        $this->assertSame(['40.0', '45.0'], $reading['values']);
    }

    #[Test]
    public function the_reading_keeps_the_values_and_the_negative_flags_in_the_order_of_the_moments(): void
    {
        $reading = TrajectoryReading::read($this->entries(['40.0', null, '70.0']));

        $this->assertSame(['40.0', null, '70.0'], $reading['values']);
        // No value is neither negative nor positive: null, never false.
        $this->assertSame([true, null, false], $reading['negative']);
    }
}
