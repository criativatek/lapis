<?php

namespace App\Services\Reporting\Export;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * The PDF (§49).
 *
 * DOMPDF, AND NOTHING THAT NEEDS A BROWSER. A headless-Chrome renderer produces
 * prettier output and needs Node, Chromium and a working sandbox on every
 * machine Lapispro runs on — including a teacher's Herd install. This is pure PHP,
 * has no binary dependency, and lays out what the template was written for.
 *
 * ISOLATED ON PURPOSE. `isRemoteEnabled` stays FALSE: the document must not be
 * able to fetch anything over the network while rendering, and it never needs
 * to — the logo arrives as bytes from the builder and is embedded as a data
 * URI. A renderer that follows URLs found in its input is an SSRF surface, and
 * this one is fed content that ultimately includes text a teacher typed.
 *
 * IT RENDERS WHAT THE BUILDER PRODUCED and reads nothing else: no report, no
 * database, no session. Same structure as the Word file, by construction (§47).
 *
 * THE PAGE NUMBER IS DRAWN HERE, NOT IN THE TEMPLATE. The footer used to ask
 * for `counter(pages)`, which dompdf does not implement: its user stylesheet
 * defines a `page` counter and nothing anywhere defines `pages`, so an
 * unresolved counter came back as zero and every teacher's PDF was footed «1 /
 * 0 · 2 / 0 · 3 / 0». The total only becomes a number once the layout has
 * finished, so the label is painted afterwards, over the pages that exist.
 */
class PdfRenderer
{
    /** A4 portrait, in points — the paper this is set on. */
    private const PAGE_WIDTH = 595.28;

    /** Matches the template's @page side margin (20 mm) and footer band. */
    private const SIDE_MARGIN = 56.7;

    /** The top of the footer text, just under the rule the template draws. */
    private const FOOTER_TOP = 806.0;

    private const FOOTER_SIZE = 7.5;

    /**
     * @param  array<string, mixed>  $document
     */
    public function render(array $document): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        // Local file access off as well: the only image in the document is
        // already inline, so nothing legitimate needs a path.
        $options->set('chroot', storage_path('app/private'));
        $options->set('defaultFont', 'DejaVu Sans');
        // Portuguese needs the accented glyphs, and DejaVu is the font shipped
        // with dompdf that has them. A missing glyph prints as a blank square.
        $options->set('defaultMediaType', 'print');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');

        $dompdf->loadHtml(
            View::make('reports.document', ['document' => $document])->render(),
            'UTF-8',
        );

        $dompdf->render();

        $this->paginate($dompdf);

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
    protected function paginate(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans');

        if ($font === null) {
            // No font, no label. A PDF without a page number is a smaller
            // problem than a failed export of a document a teacher needs.
            return;
        }

        $canvas->page_script(
            function (int $page, int $total, Canvas $canvas, FontMetrics $fontMetrics) use ($font): void {
                $label = self::pageLabel($page, $total);
                $width = (float) $fontMetrics->getTextWidth($label, $font, self::FOOTER_SIZE);

                $canvas->text(
                    self::PAGE_WIDTH - self::SIDE_MARGIN - $width,
                    self::FOOTER_TOP,
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
