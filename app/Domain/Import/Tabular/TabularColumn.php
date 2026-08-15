<?php

namespace App\Domain\Import\Tabular;

/**
 * Spreadsheet column letters, both ways.
 *
 * A column is identified by its POSITION, never by its heading. Headings repeat
 * — «Item 1» twice in one sheet is ordinary — and a heading a teacher retypes is
 * still the same column. Position is the only identity a generic sheet offers
 * that survives both (§20).
 *
 * Deliberately not PhpSpreadsheet's `Coordinate`: a CSV has columns too, and the
 * tabular abstraction is not allowed to depend on a workbook library to name
 * them (§40).
 */
final class TabularColumn
{
    /**
     * 1 => A, 26 => Z, 27 => AA.
     */
    public static function letter(int $index): string
    {
        if ($index < 1) {
            return '';
        }

        $letter = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    /**
     * A => 1, Z => 26, AA => 27. Zero for anything that is not a column letter.
     */
    public static function index(string $letter): int
    {
        $letter = strtoupper(trim($letter));

        if ($letter === '' || preg_match('/^[A-Z]+$/', $letter) !== 1) {
            return 0;
        }

        $index = 0;

        foreach (str_split($letter) as $character) {
            $index = $index * 26 + (ord($character) - 64);
        }

        return $index;
    }
}
