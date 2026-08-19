<?php

namespace App\Services\Reporting\Export;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * The PDF (§49).
 *
 * DOMPDF, AND NOTHING THAT NEEDS A BROWSER. A headless-Chrome renderer produces
 * prettier output and needs Node, Chromium and a working sandbox on every
 * machine LÁPIS runs on — including a teacher's Herd install. This is pure PHP,
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
 */
class PdfRenderer
{
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

        return (string) $dompdf->output();
    }
}
