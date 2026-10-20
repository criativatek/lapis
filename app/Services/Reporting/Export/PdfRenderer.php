<?php

namespace App\Services\Reporting\Export;

use App\Services\Documents\Pdf\DompdfPrinter;
use Illuminate\Support\Facades\View;

/**
 * The report PDF (§49).
 *
 * THE ENGINE LIVES IN DompdfPrinter. Dompdf, its isolation (no remote fetching,
 * no local file access outside the private chroot), the accented-glyph font and
 * the «Página N / T» footer are configured in one place that the class
 * documents share — see that class for the reasons. What stays here is the part
 * that is specific to a report: which template turns the structure into HTML.
 *
 * IT RENDERS WHAT THE BUILDER PRODUCED and reads nothing else: no report, no
 * database, no session. Same structure as the Word file, by construction (§47).
 */
class PdfRenderer
{
    public function __construct(protected DompdfPrinter $printer) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function render(array $document): string
    {
        return $this->printer->print(
            View::make('reports.document', ['document' => $document])->render(),
        );
    }

    /**
     * Kept here because the callers that test the label know it by this name;
     * the rule itself — the total is never printed as zero — is DompdfPrinter's.
     */
    public static function pageLabel(int $page, int $total): string
    {
        return DompdfPrinter::pageLabel($page, $total);
    }
}
