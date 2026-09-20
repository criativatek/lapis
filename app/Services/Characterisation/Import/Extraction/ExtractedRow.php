<?php

namespace App\Services\Characterisation\Import\Extraction;

/**
 * A row of cells, tagged with what kind of row it turned out to be.
 *
 * `kind` starts as ExtractedRowKind::Unknown for every extractor: deciding
 * Header/Data/Group/Legend is NormaliseExtractedTable's job, done once, on
 * the whole table, where the surrounding rows are visible. An extractor that
 * guessed row by row could not tell a lone-caption Group row from a Data row
 * that merely has a blank cell in every column but the first.
 */
readonly class ExtractedRow
{
    /**
     * @param  list<ExtractedCell>  $cells
     */
    public function __construct(
        public int $index,
        public array $cells,
        public ExtractedRowKind $kind = ExtractedRowKind::Unknown,
    ) {}

    public function withKind(ExtractedRowKind $kind): self
    {
        return new self($this->index, $this->cells, $kind);
    }
}
