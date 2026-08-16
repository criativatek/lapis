<?php

namespace App\Domain\Export;

/**
 * One student's line in the INOVAR grid.
 *
 * `processNumber` is the only thing matching ever looks at, and it is a STRING —
 * the grid writes some of them as text and some as numbers, and casting would
 * eat a leading zero that identifies a real person.
 *
 * `name` is carried for the teacher to recognise the line in the preview. It is
 * never matched on.
 */
final readonly class InovarTemplateStudent
{
    public function __construct(
        public int $row,
        public ?string $processNumber,
        public string $name,
    ) {}
}
