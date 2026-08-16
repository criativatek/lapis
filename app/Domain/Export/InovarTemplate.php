<?php

namespace App\Domain\Export;

/**
 * The INOVAR grid as it was read: where its students are, where its domains are,
 * and nothing else.
 *
 * Plain data. It never touches Eloquent and never holds the spreadsheet — the
 * writer opens the file again from the path when the teacher confirms, so
 * nothing is carried between requests except coordinates and the values needed
 * to match.
 */
final readonly class InovarTemplate
{
    /**
     * @param  string  $sheet  the one sheet the grid lives on, by title
     * @param  int  $headerRow  the row carrying the domain names
     * @param  list<InovarTemplateStudent>  $students
     * @param  array<string, string>  $domainColumns  column letter → the domain name written in the header
     */
    public function __construct(
        public string $sheet,
        public int $headerRow,
        public array $students,
        public array $domainColumns,
    ) {}
}
