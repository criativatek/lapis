<?php

namespace App\Services\Documents\Pdf;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;

/**
 * HTML in, PDF bytes out — the one place dompdf is configured (§49).
 *
 * EXTRACTED FROM PdfRenderer WHEN A SECOND DOCUMENT NEEDED IT. The report
 * export and the class documents (Mapa de Medidas Educativas) are different
 * documents built from different structures, but they must be printed by the
 * same engine under the same restrictions. Two copies of these options would
 * drift, and the day one of them re-enabled remote fetching nobody would
 * notice it was the one that is fed text a teacher typed.
 *
 * DOMPDF, AND NOTHING THAT NEEDS A BROWSER. A headless-Chrome renderer produces
 * prettier output and needs Node, Chromium and a working sandbox on every
 * machine Lapispro runs on — including a teacher's Herd install. This is pure PHP,
 * has no binary dependency, and lays out what the template was written for.
 *
 * ISOLATED ON PURPOSE. `isRemoteEnabled` stays FALSE: the document must not be
 * able to fetch anything over the network while rendering, and it never needs
 * to — a logo arrives as bytes from the builder and is embedded as a data
 * URI. A renderer that follows URLs found in its input is an SSRF surface, and
 * this one is fed content that ultimately includes text a teacher typed.
 *
 * IT PRINTS WHAT IT IS GIVEN and reads nothing else: no report, no database,
 * no session.
 *
 * THE PAGE NUMBER IS DRAWN HERE, NOT IN THE TEMPLATE. The footer used to ask
 * for `counter(pages)`, which dompdf does not implement: its user stylesheet
 * defines a `page` counter and nothing anywhere defines `pages`, so an
 * unresolved counter came back as zero and every teacher's PDF was footed «1 /
 * 0 · 2 / 0 · 3 / 0». The total only becomes a number once the layout has
 * finished, so the label is painted afterwards, over the pages that exist.
 */
class DompdfPrinter
{
    private const FOOTER_SIZE = 7.5;

    /**
     * The side margin and the label's distance from the bottom edge come from
     * `$layout` (see LetterheadLayout): on the Standard layout a portrait A4
     * lands the label at 806.0 pt, the value the report PDF has always used,
     * and a landscape page puts it in the same place relative to ITS bottom.
     */
    public function print(
        string $html,
        PaperOrientation $orientation = PaperOrientation::Portrait,
        LetterheadLayout $layout = LetterheadLayout::Standard,
    ): string {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        // Local file access off as well: the only image in a document is
        // already inline, so nothing legitimate needs a path.
        $options->set('chroot', storage_path('app/private'));
        $options->set('defaultFont', 'DejaVu Sans');
        // Portuguese needs the accented glyphs, and DejaVu is the font shipped
        // with dompdf that has them. A missing glyph prints as a blank square.
        $options->set('defaultMediaType', 'print');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', $orientation->value);

        $dompdf->loadHtml($html, 'UTF-8');

        $dompdf->render();

        $this->paginate($dompdf, $orientation, $layout);

        return (string) $dompdf->output();
    }

    /**
     * «Página 3 / 7», right-aligned in the footer band of every page.
     *
     * Drawn through the canvas's page script, which runs once per page with the
     * page's own number AND the finished total — the two facts the template
     * could not have. Right-aligned by measuring the text, because the canvas
     * draws from a point and has no concept of a text box.
     */
    protected function paginate(Dompdf $dompdf, PaperOrientation $orientation, LetterheadLayout $layout): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans');

        if ($font === null) {
            // No font, no label. A PDF without a page number is a smaller
            // problem than a failed export of a document a teacher needs.
            return;
        }

        $pageWidth = $orientation->width();
        $footerTop = $orientation->height() - $layout->labelOffsetFromBottom();
        $sideMargin = $layout->sideMargin();

        $canvas->page_script(
            function (int $page, int $total, Canvas $canvas, FontMetrics $fontMetrics) use ($font, $pageWidth, $footerTop, $sideMargin): void {
                $label = self::pageLabel($page, $total);
                $width = (float) $fontMetrics->getTextWidth($label, $font, self::FOOTER_SIZE);

                $canvas->text(
                    $pageWidth - $sideMargin - $width,
                    $footerTop,
                    $label,
                    $font,
                    self::FOOTER_SIZE,
                    [0.4, 0.4, 0.4],
                );
            },
        );
    }

    /**
     * THE TOTAL IS NEVER PRINTED AS ZERO.
     *
     * A document always has the page it is being drawn on, so a total below one
     * is not a one-page document — it is an engine that failed to count. «Página
     * 3» is honest and legible; «Página 3 / 0» tells the reader the file is
     * broken, and on a report a school sends to a family that is the only thing
     * it tells them.
     */
    public static function pageLabel(int $page, int $total): string
    {
        return $total >= $page && $total > 0
            ? "Página {$page} / {$total}"
            : "Página {$page}";
    }
}
