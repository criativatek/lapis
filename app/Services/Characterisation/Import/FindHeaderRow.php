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
 *
 * NO CANDIDATE CONFIDENTLY SCORING AS A HEADER RETURNS null, NOT ROW 0. This
 * used to fall back to declaring the very first row the header no matter
 * what it scored — meaning a table nobody could identify a header in still
 * had its first row silently sliced off and discarded as if it were one. If
 * that first row happened to be a real student (a header-less export, or one
 * whose header this scorer genuinely cannot recognise), that student's row
 * simply never reached the preview, with nothing in the response to say so.
 * null tells NormaliseExtractedTable there is no header to slice at all — see
 * its own handling for what a headerless table becomes (every row kept as
 * data, with an explicit warning, rather than a guess at which row to lose).
 */
class FindHeaderRow
{
    /**
     * @param  list<list<string>>  $matrix
     */
    public function find(array $matrix): ?int
    {
        $classifier = new ClassifyColumns;
        $bestIndex = null;
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

        return $bestIndex;
    }
}
