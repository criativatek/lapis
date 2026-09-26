<?php

namespace Tests\Unit\Assessment\Analysis;

use App\Domain\Assessment\Analysis\AnalysisBand;
use App\Domain\Assessment\Analysis\ScaleThreshold;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScaleThresholdTest extends TestCase
{
    #[Test]
    public function the_system_1_to_5_scale_derives_49_5(): void
    {
        $bands = [
            new AnalysisBand(1, '1', 'Fraco', 1, true, '0', '19.499999'),
            new AnalysisBand(2, '2', 'Insuficiente', 2, true, '19.5', '49.499999'),
            new AnalysisBand(3, '3', 'Suficiente', 3, false, '49.5', '69.499999'),
            new AnalysisBand(4, '4', 'Bom', 4, false, '69.5', '89.499999'),
            new AnalysisBand(5, '5', 'Muito Bom', 5, false, '89.5', '100'),
        ];

        $this->assertSame('49.5', ScaleThreshold::from($bands));
    }

    #[Test]
    public function a_custom_scale_with_a_boundary_at_45_is_derived_correctly(): void
    {
        $bands = [
            new AnalysisBand(1, 'neg', 'Negativa', 1, true, '0', '44.999999'),
            new AnalysisBand(2, 'pos', 'Positiva', 2, false, '45', '100'),
        ];

        $this->assertSame('45', ScaleThreshold::from($bands));
    }

    #[Test]
    public function no_negative_marks_means_no_threshold(): void
    {
        $bands = [
            new AnalysisBand(1, 'a', 'A', 1, false, '0', '49.999999'),
            new AnalysisBand(2, 'b', 'B', 2, false, '50', '100'),
        ];

        $this->assertNull(ScaleThreshold::from($bands));
    }

    #[Test]
    public function interleaved_bands_mean_no_threshold(): void
    {
        // negative, positive, negative — the negative bands do not sit
        // entirely below the non-negative ones.
        $bands = [
            new AnalysisBand(1, 'neg1', 'Negativa 1', 1, true, '0', '19.999999'),
            new AnalysisBand(2, 'pos', 'Positiva', 2, false, '20', '59.999999'),
            new AnalysisBand(3, 'neg2', 'Negativa 2', 3, true, '60', '69.999999'),
            new AnalysisBand(4, 'pos2', 'Positiva 2', 4, false, '70', '100'),
        ];

        $this->assertNull(ScaleThreshold::from($bands));
    }

    #[Test]
    public function no_bands_means_no_threshold(): void
    {
        $this->assertNull(ScaleThreshold::from([]));
    }
}
