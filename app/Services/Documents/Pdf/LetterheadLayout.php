<?php

namespace App\Services\Documents\Pdf;

/**
 * The measures the letterhead template and the page-number painter share.
 *
 * ONE SOURCE FOR TWO CONSUMERS. The `@page` margin, the fixed letterhead and
 * the fixed footer are written by the Blade partial, while the «Página N / T»
 * label is painted afterwards by DompdfPrinter at a point computed from the
 * same band. If the two kept their own numbers, narrowing the margin in one
 * would draw the label outside the footer rule of the other.
 *
 * Standard is the layout every document has always used. Compact trades
 * daylight for rows per page, meant for wide grids on landscape A4; no
 * document uses it yet.
 */
enum LetterheadLayout: string
{
    case Standard = 'standard';
    case Compact = 'compact';

    /** The `@page` margin (top right bottom left), as written in CSS. */
    public function pageMargin(): string
    {
        return match ($this) {
            self::Standard => '36mm 20mm 24mm 20mm',
            self::Compact => '26mm 14mm 16mm 14mm',
        };
    }

    /** Where the fixed letterhead starts, relative to the content box. */
    public function letterheadTop(): string
    {
        return match ($this) {
            self::Standard => '-26mm',
            self::Compact => '-21mm',
        };
    }

    public function letterheadHeight(): string
    {
        return match ($this) {
            self::Standard => '20mm',
            self::Compact => '16mm',
        };
    }

    /** Where the fixed footer sits, relative to the content box. */
    public function footerBottom(): string
    {
        return match ($this) {
            self::Standard => '-16mm',
            self::Compact => '-10mm',
        };
    }

    /** The side margin in points (20 mm / 14 mm). */
    public function sideMargin(): float
    {
        return match ($this) {
            self::Standard => 56.7,
            self::Compact => 39.69,
        };
    }

    /**
     * How far above the bottom edge of the sheet the page label sits, in
     * points. Standard lands the label at 806.0 pt on portrait A4, the value
     * the report PDF has always used; Compact keeps the same footer-to-label
     * relation ((16 - 10) mm + 4.66 mm).
     */
    public function labelOffsetFromBottom(): float
    {
        return match ($this) {
            self::Standard => 35.89,
            self::Compact => 30.2,
        };
    }
}
