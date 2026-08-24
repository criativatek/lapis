<?php

namespace App\Domain\Import\Timetable;

/**
 * One run of text as the PDF itself placed it on the page.
 *
 * `x`/`y` come straight from the text matrix (the `Tm` operator) — they are page
 * coordinates in PDF units, nothing more. This object deliberately knows nothing
 * about timetables, weekdays or columns: it is the raw evidence the
 * interpretation layer reasons over, and keeping it dumb is what makes that
 * layer testable without ever opening a real PDF.
 */
final readonly class PositionedTextFragment
{
    public function __construct(
        public float $x,
        public float $y,
        public string $text,
    ) {}
}
