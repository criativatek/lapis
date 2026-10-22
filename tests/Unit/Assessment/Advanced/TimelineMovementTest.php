<?php

namespace Tests\Unit\Assessment\Advanced;

use App\Services\Assessment\Advanced\TimelineMovement;
use App\Services\Assessment\BuildResultsProgression;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TimelineMovementTest extends TestCase
{
    private const SAME = ['percentages' => true, 'levels' => true, 'reasons' => []];

    /** @return array{code: string, label: string, sequence: int|null, is_negative: bool} */
    private function level(string $code, ?int $sequence, bool $negative): array
    {
        return ['code' => $code, 'label' => "Nível {$code}", 'sequence' => $sequence, 'is_negative' => $negative];
    }

    #[Test]
    public function the_movement_of_a_percentage_is_the_one_rule_the_rest_of_the_application_uses(): void
    {
        foreach ([['50.04', '50.05'], ['70.00', '70.04'], ['80.0', '72.5'], ['61.35', '61.35'], [null, '10'], ['10', null]] as [$before, $after]) {
            $this->assertSame(
                BuildResultsProgression::movement($before, $after),
                TimelineMovement::between(['value' => $before, 'band' => null], ['value' => $after, 'band' => null], self::SAME)['percentages'],
            );
        }

        // Any change at the precision shown is a change; below it, it is flat. No tolerance invented.
        $this->assertSame('up', BuildResultsProgression::movement('50.00', '50.10')['direction']);
        $this->assertSame('flat', BuildResultsProgression::movement('50.00', '50.04')['direction']);
    }

    #[Test]
    public function across_different_criteria_no_movement_of_the_student_is_reported(): void
    {
        $verdict = TimelineMovement::between(
            ['value' => '50', 'band' => null],
            ['value' => '80', 'band' => null],
            ['percentages' => false, 'levels' => true, 'reasons' => []],
        );

        $this->assertFalse($verdict['comparable']);
        $this->assertNull($verdict['percentages']);
    }

    #[Test]
    public function a_missing_side_is_unavailable_not_zero(): void
    {
        $verdict = TimelineMovement::between(null, ['value' => '80', 'band' => $this->level('3', 3, false)], self::SAME);

        $this->assertNull($verdict['percentages']);
        $this->assertSame(['state' => 'unavailable', 'from' => null, 'to' => null, 'crossed_line' => null, 'reason' => 'missing'], $verdict['level']);
    }

    #[Test]
    public function the_direction_of_a_level_is_the_scales_own_order(): void
    {
        $two = $this->level('2', 2, false);
        $three = $this->level('3', 3, false);

        $this->assertSame('up', TimelineMovement::level($two, $three, true)['state']);
        $this->assertSame('down', TimelineMovement::level($three, $two, true)['state']);
        $this->assertSame('same', TimelineMovement::level($three, $three, true)['state']);

        // The code and the label are not the order: sequence 10 is above sequence 9 although «10» < «9» as text.
        $this->assertSame('up', TimelineMovement::level($this->level('9', 9, false), $this->level('10', 10, false), true)['state']);
    }

    #[Test]
    public function crossing_the_scales_own_line_is_said_apart_and_only_when_it_happens(): void
    {
        $negative = $this->level('2', 2, true);
        $positive = $this->level('3', 3, false);

        $this->assertSame('to_positive', TimelineMovement::level($negative, $positive, true)['crossed_line']);
        $this->assertSame('to_negative', TimelineMovement::level($positive, $negative, true)['crossed_line']);
        $this->assertNull(TimelineMovement::level($negative, $negative, true)['crossed_line']);
        $this->assertNull(TimelineMovement::level($positive, $this->level('4', 4, false), true)['crossed_line']);
    }

    #[Test]
    public function without_a_demonstrated_scale_or_a_place_in_it_a_level_has_no_direction(): void
    {
        $two = $this->level('2', 2, false);

        $this->assertSame('not_comparable', TimelineMovement::level($two, $this->level('3', 3, false), false)['reason']);
        $this->assertSame('unavailable', TimelineMovement::level($two, $this->level('3', 3, false), false)['state']);
        $this->assertSame('no_sequence', TimelineMovement::level($two, $this->level('Bom', null, false), true)['reason']);
        $this->assertSame('missing', TimelineMovement::level(null, $two, true)['reason']);
    }

    #[Test]
    public function an_assigned_level_is_compared_like_a_band(): void
    {
        $verdict = TimelineMovement::classification(
            ['assigned' => $this->level('2', 2, true), 'assigned_value' => null, 'level_comparable' => true],
            ['assigned' => $this->level('4', 4, false), 'assigned_value' => null, 'level_comparable' => true],
            self::SAME,
        );

        $this->assertSame('up', $verdict['state']);
        $this->assertSame('to_positive', $verdict['crossed_line']);
    }

    #[Test]
    public function a_decision_taken_on_another_scale_never_enters_the_comparison(): void
    {
        $verdict = TimelineMovement::classification(
            ['assigned' => $this->level('2', 2, true), 'assigned_value' => null, 'level_comparable' => false],
            ['assigned' => $this->level('4', 4, false), 'assigned_value' => null, 'level_comparable' => true],
            self::SAME,
        );

        $this->assertSame('unavailable', $verdict['state']);
        $this->assertSame('decided_on_another_scale', $verdict['reason']);
    }

    #[Test]
    public function a_value_scale_compares_the_grades_only_when_the_scale_is_the_same(): void
    {
        $before = ['assigned' => null, 'assigned_value' => '12', 'level_comparable' => true];
        $after = ['assigned' => null, 'assigned_value' => '14', 'level_comparable' => true];

        $up = TimelineMovement::classification($before, $after, self::SAME);
        $this->assertSame('up', $up['state']);
        $this->assertSame(['12.0', '14.0'], [$up['from_value'] === null ? null : number_format((float) $up['from_value'], 1), number_format((float) $up['to_value'], 1)]);
        $this->assertSame('same', TimelineMovement::classification($before, $before, self::SAME)['state']);
        $this->assertSame('down', TimelineMovement::classification($after, $before, self::SAME)['state']);

        $other = TimelineMovement::classification($before, $after, ['percentages' => true, 'levels' => false, 'reasons' => []]);
        $this->assertSame('unavailable', $other['state']);
        $this->assertSame('not_comparable', $other['reason']);
    }

    #[Test]
    public function a_level_against_a_value_or_nothing_is_missing_and_not_guessed(): void
    {
        $this->assertSame('missing', TimelineMovement::classification(
            ['assigned' => $this->level('2', 2, false), 'assigned_value' => null],
            ['assigned' => null, 'assigned_value' => '14'],
            self::SAME,
        )['reason']);
        $this->assertSame('missing', TimelineMovement::classification(null, null, self::SAME)['reason']);
    }
}
