<?php

namespace Tests\Unit\Assessment;

use App\Domain\Assessment\ScaleCutsToBands;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dos cortes «a partir de X %» às bandas guardadas — a convenção do
 * SystemScalesSeeder (corte 50 → mínimo 49.500000, máximo anterior 49.499999).
 */
class ScaleCutsToBandsTest extends TestCase
{
    #[Test]
    public function the_default_cuts_give_exactly_the_bands_of_the_system_scale(): void
    {
        $this->assertSame([
            ['min' => '0.000000', 'max' => '19.499999'],
            ['min' => '19.500000', 'max' => '49.499999'],
            ['min' => '49.500000', 'max' => '69.499999'],
            ['min' => '69.500000', 'max' => '89.499999'],
            ['min' => '89.500000', 'max' => '100.000000'],
        ], ScaleCutsToBands::convert([0, 20, 50, 70, 90]));
    }

    #[Test]
    public function four_levels_cover_zero_to_one_hundred_without_gaps_or_overlaps(): void
    {
        $this->assertSame([
            ['min' => '0.000000', 'max' => '49.499999'],
            ['min' => '49.500000', 'max' => '69.499999'],
            ['min' => '69.500000', 'max' => '89.499999'],
            ['min' => '89.500000', 'max' => '100.000000'],
        ], ScaleCutsToBands::convert([0, 50, 70, 90]));
    }

    #[Test]
    public function ten_levels_are_contiguous_in_millionths(): void
    {
        $bands = ScaleCutsToBands::convert([0, 10, 20, 30, 40, 50, 60, 70, 80, 90]);

        $this->assertCount(10, $bands);
        $this->assertSame('0.000000', $bands[0]['min']);
        $this->assertSame('100.000000', $bands[9]['max']);

        for ($index = 1; $index < 10; $index++) {
            // Cada mínimo é exatamente uma milionésima acima do máximo anterior.
            $this->assertSame(
                bcadd($bands[$index - 1]['max'], '0.000001', 6),
                $bands[$index]['min'],
            );
        }
    }

    #[Test]
    public function a_cut_of_one_hundred_in_the_last_level_is_valid(): void
    {
        $this->assertSame([
            ['min' => '0.000000', 'max' => '99.499999'],
            ['min' => '99.500000', 'max' => '100.000000'],
        ], ScaleCutsToBands::convert([0, 100]));
    }

    #[Test]
    public function cuts_that_do_not_start_at_zero_or_do_not_grow_are_refused(): void
    {
        foreach ([[10, 50], [0, 50, 50], [0, 60, 40], [0, 101]] as $cuts) {
            try {
                ScaleCutsToBands::convert($cuts);
                $this->fail('Cortes inválidos: '.json_encode($cuts));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
