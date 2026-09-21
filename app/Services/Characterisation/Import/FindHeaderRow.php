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
     * A candidate with NO named identifying column is still allowed to win
     * when it scores at least this many OTHER recognised columns — see the
     * loop's own comment for why. Deliberately well above what a real
     * caption or legend row could ever score on its own (those have at most
     * one or two non-empty cells to begin with), so this never rescues a
     * false candidate, only a genuine header whose own name column happens
     * to carry no printed label at all.
     */
    private const MIN_COLUMNS_WITHOUT_IDENTIFYING = 4;

    /**
     * @param  list<list<string>>  $matrix
     * @param  array<int, true>  $excludedIndices  positions in $matrix that must never be considered as a header, however they score — a row NormaliseExtractedTable already knows, structurally or by content, to be a caption or legend rather than a header. See that class's own comment on why this has to be excluded HERE, before scoring, rather than filtered out of the result afterwards: a full-width group caption, once expanded, scores as if every column had text in it, which is exactly the shape this method is built to reward.
     */
    public function find(array $matrix, array $excludedIndices = []): ?int
    {
        $classifier = new ClassifyColumns;
        $bestIndex = null;
        $bestScore = -1;
        $bestUnnamedIndex = null;
        $bestUnnamedScore = -1;

        foreach (array_slice($matrix, 0, 15, preserve_keys: true) as $index => $row) {
            if (isset($excludedIndices[$index])) {
                continue;
            }

            $strRow = array_map('strval', $row);
            $columns = $classifier->classify(new TableGrid($strRow, []));

            $namesStudent = array_filter(
                $columns,
                fn (ClassifiedColumn $column) => $column->role->isIdentifying(),
            );

            $score = count(array_filter(
                $columns,
                fn (ClassifiedColumn $column) => $column->role !== ColumnRole::Unknown,
            ));

            // A row that actually names the student always wins over one
            // that merely scores well otherwise — tracked SEPARATELY from
            // the unnamed fallback below, rather than compared score for
            // score against it, precisely because a header LEVEL sitting
            // above the real header (e.g. a merged "Medidas"/"Apoio" row,
            // repeated across every column it spans by expand()) can easily
            // out-SCORE the true header on raw recognised-column count —
            // «Medidas» alone drags three columns to Measures — without
            // being the row that should ever be chosen.
            //
            // BUT a single identifying match, on its own, is not enough to
            // trust UNLESS it is the row's own FIRST non-empty cell — the
            // position a name column always occupies, header or data alike.
            // Without this, an ordinary DATA row whose free text happens to
            // contain the word "aluno" ("Aluno novo.", a perfectly normal
            // observation, matched on a LATE column) scored namesStudent
            // !== [] from that one accidental substring and nothing else,
            // and used to win outright over the real header purely for
            // having any named column at all, however coincidental. A
            // genuine minimal header — a bare "Nome" column and nothing
            // else recognised — keeps working because ITS identifying match
            // IS the first non-empty cell.
            if ($namesStudent !== []) {
                $firstNonEmptyIndex = $this->firstNonEmptyIndex($strRow);
                $identifyingIsFirstCell = false;

                foreach ($namesStudent as $column) {
                    if ($column->index === $firstNonEmptyIndex) {
                        $identifyingIsFirstCell = true;

                        break;
                    }
                }

                if (($score > 1 || $identifyingIsFirstCell) && $score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $index;
                }

                continue;
            }

            // No named identifying column here — remembered only as a
            // FALLBACK candidate, used below solely when nothing in the
            // whole table ever named the student at all. A real header
            // whose own name column prints no label (a blank first cell
            // above a column of names, common enough on a printed form)
            // still has every OTHER column recognisable, which a caption or
            // legend row never does — those have at most one or two
            // non-empty cells, nowhere near MIN_COLUMNS_WITHOUT_IDENTIFYING.
            if ($score >= self::MIN_COLUMNS_WITHOUT_IDENTIFYING && $score > $bestUnnamedScore) {
                $bestUnnamedScore = $score;
                $bestUnnamedIndex = $index;
            }
        }

        return $bestIndex ?? $bestUnnamedIndex;
    }

    /**
     * @param  list<string>  $row
     */
    private function firstNonEmptyIndex(array $row): ?int
    {
        foreach ($row as $index => $cell) {
            if (trim($cell) !== '') {
                return $index;
            }
        }

        return null;
    }
}
