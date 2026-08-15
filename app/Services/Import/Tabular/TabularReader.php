<?php

namespace App\Services\Import\Tabular;

use App\Domain\Import\Tabular\TabularSourceSnapshot;

/**
 * Reads one container format into a rectangle of cells. Nothing else.
 *
 * A reader knows about bytes, delimiters, encodings and sheets. It does not know
 * what a student is, what a mark is, or that LÁPIS exists — which is what lets
 * the CSV one and the XLSX one be genuinely interchangeable, and what makes the
 * mapping and canonicalisation above them written exactly once (§40).
 */
interface TabularReader
{
    /**
     * File extensions this reader accepts, lowercase and without the dot.
     *
     * @return list<string>
     */
    public function extensions(): array;

    /**
     * MIME types this reader accepts.
     *
     * @return list<string>
     */
    public function mimeTypes(): array;

    /**
     * A cheap look: is this plausibly the container this reader opens? Structural
     * only — the expensive answer is `read()`.
     */
    public function supports(string $absolutePath, string $originalFilename): bool;

    /**
     * @throws UnreadableSpreadsheet when the file cannot be read honestly.
     */
    public function read(string $absolutePath): TabularSourceSnapshot;
}
