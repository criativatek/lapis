<?php

namespace App\Services\Characterisation\Import;

/**
 * A table, stripped of whatever produced it.
 *
 * Everything downstream — column classification, code resolution, matching,
 * preview — works on this and only this. That is the whole reason the feature
 * can accept pasted text, CSV and XLSX without three pipelines, and the reason
 * a future source (an OCR'd PDF, say) would plug in by producing a grid rather
 * than by touching anything that reads one.
 */
readonly class TableGrid
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  list<list<?float>>  $confidences  EXTRACTION confidence (§18) — "did I read
     *                                           this cell right?" — one entry per data row,
     *                                           parallel to $rows, keyed the same way. Null for
     *                                           any cell that was read exactly (every source
     *                                           except OCR), exactly like ExtractedCell's own
     *                                           $confidence — see that class's docblock for why
     *                                           this is a different axis from CodeConfidence.
     *                                           Left empty by every caller that builds a
     *                                           TableGrid by hand (a plain CSV/XLSX/paste read,
     *                                           or a test): confidence(), like cell(), degrades
     *                                           to "no answer" rather than requiring one.
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public array $confidences = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function columnCount(): int
    {
        return count($this->headers);
    }

    /**
     * The cell at a column index, or '' when the row is short.
     *
     * Rows arriving shorter than the header is normal in pasted text — a
     * trailing empty cell simply does not survive the copy — and is not an
     * error worth refusing the whole table over.
     *
     * @param  list<string>  $row
     */
    public function cell(array $row, int $index): string
    {
        return trim($row[$index] ?? '');
    }

    /**
     * The EXTRACTION confidence for the cell at $rowIndex/$columnIndex, or
     * null when there is none — either because the source read this cell
     * exactly, or because this grid was built without confidences at all.
     * Never CodeConfidence: see ExtractedCell's docblock.
     */
    public function confidence(int $rowIndex, int $columnIndex): ?float
    {
        return $this->confidences[$rowIndex][$columnIndex] ?? null;
    }
}
