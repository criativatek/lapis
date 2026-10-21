<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\ClassDocuments\InstrumentGridLayout;
use App\Services\Reporting\ClassDocuments\InstrumentGridOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The options arrive as a query string and the server reads them against a
 * whitelist: a forged value is the default, never an error and never a column the
 * teacher did not ask for.
 */
class InstrumentGridOptionsTest extends TestCase
{
    #[Test]
    public function everything_is_on_and_the_orientation_automatic_by_default(): void
    {
        $options = InstrumentGridOptions::fromQuery([]);

        $this->assertEquals(new InstrumentGridOptions, $options);
        $this->assertSame(
            ['raw_score' => true, 'result' => true, 'domains' => true, 'legend' => true, 'charts' => false, 'orientation' => 'auto'],
            $options->toArray(),
        );
    }

    #[Test]
    public function a_clear_no_turns_one_option_off_and_leaves_the_others(): void
    {
        $options = InstrumentGridOptions::fromQuery(['bruta' => '0', 'dominios' => 'false', 'legenda' => 'off']);

        $this->assertFalse($options->rawScore);
        $this->assertTrue($options->result);
        $this->assertFalse($options->domains);
        $this->assertFalse($options->legend);
    }

    #[Test]
    public function forged_values_are_the_default_not_an_error(): void
    {
        $options = InstrumentGridOptions::fromQuery([
            'bruta' => ['0'],
            'resultado' => '',
            'dominios' => 'banana',
            'legenda' => '<script>',
            'orientacao' => 'diagonal',
            'inventada' => '0',
        ]);

        $this->assertEquals(new InstrumentGridOptions, $options);
    }

    #[Test]
    public function the_orientation_is_one_of_three_values_and_nothing_else(): void
    {
        $this->assertSame(InstrumentGridLayout::PORTRAIT, InstrumentGridOptions::fromQuery(['orientacao' => 'vertical'])->orientation);
        $this->assertSame(InstrumentGridLayout::LANDSCAPE, InstrumentGridOptions::fromQuery(['orientacao' => 'horizontal'])->orientation);
        $this->assertSame(InstrumentGridLayout::AUTO, InstrumentGridOptions::fromQuery(['orientacao' => 'auto'])->orientation);
        $this->assertSame(InstrumentGridLayout::AUTO, InstrumentGridOptions::fromQuery(['orientacao' => ['vertical']])->orientation);
        $this->assertSame(InstrumentGridLayout::AUTO, InstrumentGridOptions::fromQuery(['orientacao' => 'portrait'])->orientation);
    }
}
