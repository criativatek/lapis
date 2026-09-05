<?php

namespace Tests\Unit\Assessment;

use App\Domain\Assessment\Bc;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bc::round() stripped the sign, rounded the magnitude, then reapplied the
 * sign — which silently swaps ceil and floor for negative values (ceil on a
 * negative magnitude moves it toward zero, i.e. what floor must do on the
 * signed value, and vice versa). Positives were never affected, which is why
 * this went unnoticed: no grade is ever negative, but the same helper rounds
 * negative statistic variations elsewhere.
 */
class BcRoundTest extends TestCase
{
    #[Test]
    public function floor_on_a_negative_value_rounds_away_from_zero(): void
    {
        $this->assertSame('-3', Bc::round('-2.5', 0, 'floor'));
        $this->assertSame('-3', Bc::round('-2.1', 0, 'floor'));
    }

    #[Test]
    public function ceil_on_a_negative_value_rounds_toward_zero(): void
    {
        $this->assertSame('-2', Bc::round('-2.5', 0, 'ceil'));
        $this->assertSame('-2', Bc::round('-2.9', 0, 'ceil'));
    }

    #[Test]
    public function floor_and_ceil_on_positive_values_are_unaffected(): void
    {
        $this->assertSame('2', Bc::round('2.5', 0, 'floor'));
        $this->assertSame('3', Bc::round('2.5', 0, 'ceil'));
    }

    #[Test]
    public function half_up_rounds_away_from_zero_on_the_tie_regardless_of_sign(): void
    {
        // half_up means "half rounds up in magnitude" on both sides of zero —
        // it is symmetric, unlike floor/ceil which are direction-bound.
        $this->assertSame('-3', Bc::round('-2.5', 0, 'half_up'));
        $this->assertSame('3', Bc::round('2.5', 0, 'half_up'));
    }

    #[Test]
    public function half_down_rounds_toward_zero_on_the_tie_regardless_of_sign(): void
    {
        $this->assertSame('-2', Bc::round('-2.5', 0, 'half_down'));
        $this->assertSame('2', Bc::round('2.5', 0, 'half_down'));
    }

    #[Test]
    public function half_even_picks_the_even_neighbour_regardless_of_sign(): void
    {
        $this->assertSame('-2', Bc::round('-2.5', 0, 'half_even'));
        $this->assertSame('-4', Bc::round('-3.5', 0, 'half_even'));
        $this->assertSame('2', Bc::round('2.5', 0, 'half_even'));
        $this->assertSame('4', Bc::round('3.5', 0, 'half_even'));
    }
}
