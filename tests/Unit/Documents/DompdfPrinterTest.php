<?php

namespace Tests\Unit\Documents;

use App\Services\Documents\Pdf\DompdfPrinter;
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
    public function the_page_label_is_never_printed_with_a_zero_total(): void
    {
        $this->assertSame('Página 2 / 5', DompdfPrinter::pageLabel(2, 5));
        $this->assertSame('Página 2', DompdfPrinter::pageLabel(2, 0));
    }
}
