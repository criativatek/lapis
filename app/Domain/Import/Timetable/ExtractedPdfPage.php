<?php

namespace App\Domain\Import\Timetable;

/**
 * One page of a PDF, in both of the readings the interpretation layer needs.
 *
 * `text` is the linear reading — reliable for title, header and footer lines,
 * and useless for a table, because an empty cell produces no separator at all
 * and every following value silently shifts one column to the left.
 *
 * `fragments` is the positioned reading, which does not have that problem: each
 * run of text carries the coordinates the PDF placed it at, so a cell's column
 * is decided by where it IS rather than by how many separators preceded it.
 */
final readonly class ExtractedPdfPage
{
    /**
     * @param  list<PositionedTextFragment>  $fragments
     */
    public function __construct(
        public string $text,
        public array $fragments,
    ) {}
}
