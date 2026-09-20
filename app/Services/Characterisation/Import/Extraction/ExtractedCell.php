<?php

namespace App\Services\Characterisation\Import\Extraction;

/**
 * One cell as read from its source, before any domain meaning is attached.
 *
 * `confidence` HERE is extraction confidence — "did I read this cell right?"
 * — and it is a DIFFERENT AXIS from the domain confidence that
 * App\Support\Characterisation\CodeConfidence carries — "do I know what this
 * text means?". A cell read from a pasted HTML table or a .docx is read
 * EXACTLY, character for character, so its extraction confidence is null: it
 * is not merely "high", the question does not apply. The only source in this
 * pipeline where the question does apply is an image read by OCR, which is
 * why `confidence` exists on this class at all and is null everywhere else.
 * Never write one of these two confidences into the other — CodeConfidence
 * answers a pedagogical question this class has no opinion on, and this
 * class answers a legibility question CodeConfidence has no opinion on.
 */
readonly class ExtractedCell
{
    public function __construct(
        public string $text,
        public int $row,
        public int $column,
        public int $colspan = 1,
        public int $rowspan = 1,
        public ?float $confidence = null,
    ) {}
}
