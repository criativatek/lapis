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
     */
    public function __construct(
        public array $headers,
        public array $rows,
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
}
