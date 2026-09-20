<?php

namespace App\Services\Characterisation\Import\Extraction;

/**
 * The canonical intermediate shape every extractor produces, and the only
 * thing NormaliseExtractedTable reads.
 *
 * This sits BETWEEN a source (pasted HTML, a .docx, a workbook, an image) and
 * TableGrid. It exists because merged cells, multi-row headers and
 * group/legend rows are real in the sources this feature accepts, and
 * TableGrid — deliberately — knows nothing about any of that; it is a flat
 * rectangle of headers and data rows. Keeping the merge/kind bookkeeping here
 * means TableGrid, ClassifyColumns and everything downstream of it stay exactly
 * as simple as they already are.
 *
 * `extractionConfidence` is a table-level summary of ExtractedCell::$confidence
 * — see that class for why it must never be confused with CodeConfidence.
 */
readonly class ExtractedTable
{
    /**
     * @param  list<ExtractedRow>  $rows
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $rows,
        public ExtractedTableSource $sourceType,
        public ?string $sourceFilename = null,
        public array $warnings = [],
        public float $extractionConfidence = 1.0,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function columnCount(): int
    {
        $max = 0;

        foreach ($this->rows as $row) {
            foreach ($row->cells as $cell) {
                $max = max($max, $cell->column + $cell->colspan - 1);
            }
        }

        return $max;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType->value,
            'source_filename' => $this->sourceFilename,
            'row_count' => $this->rowCount(),
            'column_count' => $this->columnCount(),
            'warnings' => $this->warnings,
            'extraction_confidence' => $this->extractionConfidence,
        ];
    }
}
