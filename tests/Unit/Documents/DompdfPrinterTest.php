<?php

namespace Tests\Unit\Documents;

use App\Services\Documents\Pdf\DompdfPrinter;
use App\Services\Documents\Pdf\LetterheadLayout;
use App\Services\Documents\Pdf\PaperOrientation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shared dompdf wrapper: a real PDF in both orientations, with the paper
 * measured the way the page label is placed from it.
 */
class DompdfPrinterTest extends TestCase
{
    private const HTML = '<html><body><h1>Teste</h1><p>Acentuação: ção, é, ü.</p></body></html>';

    #[Test]
    public function it_prints_a_portrait_pdf_by_default(): void
    {
        $bytes = app(DompdfPrinter::class)->print(self::HTML);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertMatchesRegularExpression('/MediaBox\s*\[[\d.]+\s+[\d.]+\s+595\.28\d*\s+841\.89\d*\]/', $bytes);
    }

    #[Test]
    public function it_prints_a_landscape_pdf(): void
    {
        $bytes = app(DompdfPrinter::class)->print(self::HTML, PaperOrientation::Landscape);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertMatchesRegularExpression('/MediaBox\s*\[[\d.]+\s+[\d.]+\s+841\.89\d*\s+595\.28\d*\]/', $bytes);
    }

    #[Test]
    public function the_orientation_swaps_the_sheet_dimensions(): void
    {
        $this->assertSame(595.28, PaperOrientation::Portrait->width());
        $this->assertSame(841.89, PaperOrientation::Portrait->height());
        $this->assertSame(841.89, PaperOrientation::Landscape->width());
        $this->assertSame(595.28, PaperOrientation::Landscape->height());
    }

    #[Test]
    public function the_portrait_footer_sits_where_the_report_pdf_always_put_it(): void
    {
        // 841.89 - 35.89: the 806.0 pt the footer label has always been drawn at.
        $this->assertEqualsWithDelta(806.0, PaperOrientation::Portrait->height() - 35.89, 0.0001);
    }

    #[Test]
    public function the_standard_layout_keeps_the_label_where_it_always_was(): void
    {
        $layout = LetterheadLayout::Standard;

        $this->assertEqualsWithDelta(806.0, PaperOrientation::Portrait->height() - $layout->labelOffsetFromBottom(), 0.0001);
        $this->assertSame(56.7, $layout->sideMargin());
    }

    #[Test]
    public function the_compact_layout_lifts_the_label_with_its_narrower_footer_band(): void
    {
        $layout = LetterheadLayout::Compact;

        // (16 - 10) mm + 4.66 mm = 10.66 mm = 30.2 pt above the bottom edge.
        $this->assertEqualsWithDelta(10.66 * 72 / 25.4, $layout->labelOffsetFromBottom(), 0.05);
        $this->assertEqualsWithDelta(14 * 72 / 25.4, $layout->sideMargin(), 0.01);
        $this->assertEqualsWithDelta(811.7, PaperOrientation::Portrait->height() - $layout->labelOffsetFromBottom(), 0.1);
    }

    #[Test]
    public function it_prints_a_compact_landscape_pdf(): void
    {
        $bytes = app(DompdfPrinter::class)->print(self::HTML, PaperOrientation::Landscape, LetterheadLayout::Compact);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertMatchesRegularExpression('/MediaBox\s*\[[\d.]+\s+[\d.]+\s+841\.89\d*\s+595\.28\d*\]/', $bytes);
    }

    #[Test]
    public function the_page_label_is_never_printed_with_a_zero_total(): void
    {
        $this->assertSame('Página 2 / 5', DompdfPrinter::pageLabel(2, 5));
        $this->assertSame('Página 2', DompdfPrinter::pageLabel(2, 0));
    }
}
