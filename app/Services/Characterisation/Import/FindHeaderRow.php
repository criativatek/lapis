<?php

namespace App\Services\Characterisation\Import;

/**
 * Locates the header row of a plain matrix by content, not position.
 *
 * Extracted out of ReadCharacterisationTable so NormaliseExtractedTable can
 * find the same row the same way, on the matrix it produces after expanding
 * merged cells. School exports put a printed report above the data — school
 * name, year, a title — so the header is rarely row 1, and a candidate row
 * must actually name the student to qualify: a line of prose that happens to
 * contain the word «notas» is not a header, and treating it as one would
 * shift every student's text up by a row.
 */
class FindHeaderRow
{
    /**
     * @param  list<list<string>>  $matrix
     */
    public function find(array $matrix): int
    {
        $classifier = new ClassifyColumns;
        $bestIndex = 0;
        $bestScore = -1;

        foreach (array_slice($matrix, 0, 15) as $index => $row) {
            $columns = $classifier->classify(new TableGrid(array_map('strval', $row), []));

            $namesStudent = array_filter(
                $columns,
                fn (ClassifiedColumn $column) => $column->role->isIdentifying(),
            );

            if ($namesStudent === []) {
                continue;
            }

            $score = count(array_filter(
                $columns,
                fn (ClassifiedColumn $column) => $column->role !== ColumnRole::Unknown,
            ));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestScore < 0 ? 0 : $bestIndex;
    }
}
