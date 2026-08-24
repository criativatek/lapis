<?php

namespace App\Services\Import\Timetable;

use App\Domain\Import\Timetable\ExtractedPdfDocument;
use App\Domain\Import\Timetable\ExtractedPdfPage;
use App\Domain\Import\Timetable\PositionedTextFragment;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Opens a PDF and reads it twice: linearly, and positionally.
 *
 * DELIBERATELY IGNORANT OF TIMETABLES. This layer knows about PDFs and nothing
 * else — no weekday, no lesson, no column. Everything that gives those runs
 * meaning lives in TimetableParser, one layer up, which is why that parser can
 * be unit-tested against hand-written fragment data without a real file ever
 * being opened.
 *
 * The file is read from memory (`parseContent`), never from a path: the upload
 * never becomes a temporary file, so there is no temp-file lifecycle to leak, to
 * clean up, or to accidentally expose in an error message.
 */
class PdfTextExtractor
{
    public function extract(string $contents): ExtractedPdfDocument
    {
        try {
            $pdf = $this->parser()->parseContent($contents);
            $pages = $pdf->getPages();
        } catch (Throwable) {
            // The library's own messages name internal offsets and object
            // numbers. None of that helps a teacher, and some of it describes
            // the contents of their file, so nothing from it is passed on.
            throw new TimetablePdfException(
                __('Não foi possível ler este PDF. Verifique se o ficheiro está completo e se não está protegido por palavra-passe.'),
            );
        }

        $extracted = [];

        foreach ($pages as $page) {
            $extracted[] = new ExtractedPdfPage(
                $this->textOf($page),
                $this->fragmentsOf($page),
            );
        }

        return new ExtractedPdfDocument($extracted);
    }

    protected function parser(): Parser
    {
        return new Parser;
    }

    protected function textOf(Page $page): string
    {
        try {
            return $page->getText();
        } catch (Throwable) {
            // One unreadable page must not lose the others: a page that yields
            // no text simply contributes none.
            return '';
        }
    }

    /**
     * @return list<PositionedTextFragment>
     */
    protected function fragmentsOf(Page $page): array
    {
        try {
            $data = $page->getDataTm();
        } catch (Throwable) {
            return [];
        }

        $fragments = [];

        foreach ($data as $entry) {
            // Each entry is [[a, b, c, d, e, f], text] — a PDF text matrix and
            // the run it placed. `e` and `f` are the translation components,
            // i.e. x and y, and arrive as strings.
            if (! is_array($entry) || count($entry) < 2) {
                continue;
            }

            [$matrix, $text] = [$entry[0], $entry[1]];

            if (! is_array($matrix) || count($matrix) < 6 || ! is_string($text)) {
                continue;
            }

            $fragments[] = new PositionedTextFragment(
                (float) $matrix[4],
                (float) $matrix[5],
                $text,
            );
        }

        return $fragments;
    }
}
