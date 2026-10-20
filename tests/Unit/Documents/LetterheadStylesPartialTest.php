<?php

namespace Tests\Unit\Documents;

use App\Services\Documents\Pdf\LetterheadLayout;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The letterhead CSS is written from LetterheadLayout, and the Standard layout
 * must stay byte-for-byte what the report and the Mapa de Medidas have always
 * been printed with.
 */
class LetterheadStylesPartialTest extends TestCase
{
    #[Test]
    public function the_standard_layout_is_the_default_and_writes_the_original_measures(): void
    {
        $css = view('documents.partials.letterhead-styles')->render();

        $this->assertStringContainsString('margin: 36mm 20mm 24mm 20mm;', $css);
        $this->assertStringContainsString('top: -26mm;', $css);
        $this->assertStringContainsString('height: 20mm;', $css);
        $this->assertStringContainsString('bottom: -16mm;', $css);
        $this->assertSame(
            $css,
            view('documents.partials.letterhead-styles', ['layout' => LetterheadLayout::Standard])->render(),
        );
    }

    #[Test]
    public function the_compact_layout_writes_the_compact_measures(): void
    {
        $css = view('documents.partials.letterhead-styles', ['layout' => LetterheadLayout::Compact])->render();

        $this->assertStringContainsString('margin: 26mm 14mm 16mm 14mm;', $css);
        $this->assertStringContainsString('top: -21mm;', $css);
        $this->assertStringContainsString('height: 16mm;', $css);
        $this->assertStringContainsString('bottom: -10mm;', $css);
        $this->assertStringNotContainsString('36mm 20mm 24mm 20mm', $css);
    }
}
