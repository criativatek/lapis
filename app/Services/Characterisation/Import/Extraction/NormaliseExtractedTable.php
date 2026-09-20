<?php

namespace App\Services\Characterisation\Import\Extraction;

use App\Services\Characterisation\Import\FindHeaderRow;
use App\Services\Characterisation\Import\TableGrid;
use App\Services\Import\Tabular\UnreadableSpreadsheet;

/**
 * Turns an ExtractedTable into the TableGrid everything downstream already
 * knows how to read.
 *
 * GROUP ROWS ARE CLASSIFIED BEFORE EXPANSION, AND THAT ORDER IS LOAD-BEARING.
 * expand() repeats a merged cell's text across every cell it covers (see that
 * method's own comment for why), which means a full-width «Alunos com RTP»
 * caption — a SINGLE cell with a colspan of, say, 15 — turns into fifteen
 * identical non-empty cells once expanded. A row-shape test run AFTER
 * expansion cannot tell that row apart from an ordinary row where all fifteen
 * columns happen to be filled; the structural fact that it was one cell is
 * gone. So the merge structure is read here, from the ExtractedTable itself,
 * before expand() ever runs, and a caption caught this way is never confused
 * with a real fifteen-column row — nor with a sparse student row that merely
 * has one cell filled in and fourteen empty ones, which is the ordinary case
 * for most of a class and must never be dropped.
 *
 * Sources with no merge information at all — a TSV or CSV paste, an XLSX
 * without merged cells — carry no colspan to read, so a caption arriving that
 * way is caught by a CONTENT test instead: the row's one non-empty cell must
 * read like a caption («Alunos com/sem …», or end in «:»), not merely BE
 * alone in an early column. A quiet student — a name and nothing else, the
 * common case — is alone in column 0 too, and the content test is what keeps
 * that student a Data row: matching text is required, not just isolation.
 * Where the content test is unsure, the row stays Data — a caption the
 * teacher can still untick in the preview is recoverable; a child silently
 * missing from it is not.
 *
 * Three things happen here, in order, and only here:
 *
 *  1. Group rows are found structurally (above), before anything is expanded.
 *  2. Merged cells are THEN expanded into a plain rectangle — a colspan/rowspan
 *     cell's text is REPEATED across every cell it covers, rather than left
 *     as a hole. A hole would shift every later column left by one for that
 *     row alone, silently, which is worse than a repeated value: a repeated
 *     value is visibly redundant, a shifted column is invisibly wrong.
 *  3. The rest of the classification — Header, Legend, and the Group content
 *     fallback for merge-less sources — runs on the expanded rectangle, which
 *     is the shape ClassifyColumns and the legend/caption content checks
 *     already expect. Only Data rows survive into TableGrid::rows; Group and
 *     Legend rows are dropped but COUNTED, and the count is exposed via
 *     warnings() so the controller can tell the teacher what was left out and
 *     why, rather than the row simply vanishing.
 *
 * `warnings()` reflects the most recent call to `normalise()`. This class is
 * built fresh per import (see ReadCharacterisationTable), so that statefulness
 * never crosses a request boundary — it exists purely because TableGrid itself
 * has no field for it, and changing TableGrid's shape is out of scope here.
 */
class NormaliseExtractedTable
{
    private const MAX_ROWS = 500;

    private const MAX_COLUMNS = 40;

    private const MAX_CELLS = 20000;

    /** @var list<string> */
    private array $warnings = [];

    public function normalise(ExtractedTable $table): TableGrid
    {
        $this->warnings = [];

        if ($table->isEmpty()) {
            throw new UnreadableSpreadsheet(__('A tabela não tem linhas com conteúdo.'));
        }

        $columnCount = $table->columnCount();

        if ($columnCount > self::MAX_COLUMNS) {
            throw new UnreadableSpreadsheet(__('A tabela tem colunas a mais para ser lida com segurança.'));
        }

        // Read BEFORE expand() destroys the merge structure. Keyed by the
        // ExtractedRow's own row number, which is exactly what expand()
        // preserves as the matrix row index (offset by one), so the two stay
        // aligned without needing to smuggle a kind through the matrix.
        $structuralGroupRows = $this->structuralGroupRowNumbers($table, $columnCount);

        $matrix = $this->expand($table, $columnCount);

        $entries = [];

        foreach ($matrix as $zeroBasedIndex => $cells) {
            $entries[] = ['number' => $zeroBasedIndex + 1, 'cells' => $cells];
        }

        $entries = array_values(array_filter(
            $entries,
            fn (array $entry): bool => trim(implode('', $entry['cells'])) !== '',
        ));

        if ($entries === []) {
            throw new UnreadableSpreadsheet(__('A tabela não tem linhas com conteúdo.'));
        }

        if (count($entries) * max($columnCount, 1) > self::MAX_CELLS) {
            throw new UnreadableSpreadsheet(__('A tabela tem células a mais para ser lida com segurança.'));
        }

        $plainMatrix = array_map(fn (array $entry) => $entry['cells'], $entries);

        $headerLevels = $this->headerLevels($plainMatrix);
        // headerLevels() always seeds itself with the primary header row
        // before optionally prefixing earlier levels, so the last element —
        // the highest index — is always present.
        $headerEndIndex = $headerLevels[count($headerLevels) - 1];
        $headers = $this->joinHeaderLevels($plainMatrix, $headerLevels, $columnCount);

        $bodyEntries = array_slice($entries, $headerEndIndex + 1);
        $dataRows = $this->classifyBody($bodyEntries, $structuralGroupRows);

        if (count($headers) > self::MAX_COLUMNS) {
            throw new UnreadableSpreadsheet(__('A tabela tem colunas a mais para ser lida com segurança.'));
        }

        if (count($dataRows) > self::MAX_ROWS) {
            throw new UnreadableSpreadsheet(__('A tabela tem :count linhas — mais do que esta importação aceita de uma vez. Importe uma turma de cada vez.', [
                'count' => count($dataRows),
            ]));
        }

        return new TableGrid($headers, array_map(
            fn (array $row) => array_map('strval', array_slice($row, 0, count($headers))),
            $dataRows,
        ));
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Row numbers of rows that ARE a single cell whose colspan covers all —
     * or very nearly all — of the table's width: a merged full-width caption,
     * structurally, before any expansion has had a chance to disguise it as
     * an ordinary wide row. A sparse student row can never match this test,
     * because a sparse row has real sibling cells — merely empty ones, not
     * absent ones; ExtractedTable::columnCount() and every extractor in this
     * namespace produce one ExtractedCell per occupied column, so a genuine
     * single-cell row is exactly what a merge produces and nothing else does.
     *
     * @return array<int, true> keyed by ExtractedRow::$index
     */
    private function structuralGroupRowNumbers(ExtractedTable $table, int $columnCount): array
    {
        $rowNumbers = [];

        foreach ($table->rows as $row) {
            if (count($row->cells) !== 1) {
                continue;
            }

            $cell = $row->cells[0];

            if (trim($cell->text) === '') {
                continue;
            }

            // "Nearly all": a caption occasionally leaves the very last
            // column outside its merge (a trailing decorative cell), so the
            // width is allowed to fall one short of the full column count —
            // but a table with only one or two columns has no room for that
            // slack, or every ordinary single-column cell would qualify.
            if ($columnCount >= 3 && $cell->colspan >= $columnCount - 1) {
                $rowNumbers[$row->index] = true;
            } elseif ($columnCount < 3 && $cell->colspan >= $columnCount) {
                $rowNumbers[$row->index] = true;
            }
        }

        return $rowNumbers;
    }

    /**
     * Every colspan/rowspan cell repeated across the cells it covers, so no
     * column ever shifts silently for the rows underneath a merge. Repetition
     * is deliberate: a hole here would misalign every later column for that
     * one row, and there would be nothing in the data itself to say so.
     *
     * Group rows must be read from $table BEFORE this runs — see the class
     * comment. By the time a row has been through here, a full-width merged
     * caption and an ordinary fully-filled row are indistinguishable.
     *
     * @return list<list<string>>
     */
    private function expand(ExtractedTable $table, int $columnCount): array
    {
        $totalRows = 0;

        foreach ($table->rows as $row) {
            foreach ($row->cells as $cell) {
                $totalRows = max($totalRows, $cell->row + $cell->rowspan - 1);
            }
        }

        $matrix = array_fill(0, $totalRows, array_fill(0, $columnCount, ''));

        foreach ($table->rows as $row) {
            foreach ($row->cells as $cell) {
                for ($r = $cell->row; $r < $cell->row + $cell->rowspan; $r++) {
                    for ($c = $cell->column; $c < $cell->column + $cell->colspan; $c++) {
                        if ($r - 1 >= 0 && $r - 1 < $totalRows && $c - 1 >= 0 && $c - 1 < $columnCount) {
                            $matrix[$r - 1][$c - 1] = $cell->text;
                        }
                    }
                }
            }
        }

        // array_fill/array assignment-in-place already produce contiguous
        // integer keys for the outer array; each row is routed through
        // array_values too so its keys are known-contiguous the same way.
        return array_map('array_values', $matrix);
    }

    /**
     * The indices making up the header, in order. A single index for an
     * ordinary table; several consecutive indices when the header spans more
     * than one printed row — «Apoio» over «Ing.» becomes column «Apoio Ing.».
     *
     * @param  list<list<string>>  $matrix
     * @return list<int>
     */
    private function headerLevels(array $matrix): array
    {
        $primary = (new FindHeaderRow)->find($matrix);
        $levels = [$primary];

        // Walk upward while the row above still looks like a header level
        // rather than report preamble: short labels, more than one of them,
        // none reading like a sentence.
        $index = $primary - 1;

        while ($index >= 0 && $primary - $index <= 2 && $this->looksLikeHeaderLevel($matrix[$index])) {
            array_unshift($levels, $index);
            $index--;
        }

        return $levels;
    }

    /**
     * @param  list<string>  $row
     */
    private function looksLikeHeaderLevel(array $row): bool
    {
        $nonEmpty = array_values(array_filter($row, fn (string $cell) => trim($cell) !== ''));

        if (count($nonEmpty) < 2) {
            return false;
        }

        foreach ($nonEmpty as $cell) {
            if (mb_strlen($cell) > 40) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<list<string>>  $matrix
     * @param  list<int>  $levels
     * @return list<string>
     */
    private function joinHeaderLevels(array $matrix, array $levels, int $columnCount): array
    {
        $headers = [];

        for ($column = 0; $column < $columnCount; $column++) {
            $fragments = [];

            foreach ($levels as $levelIndex) {
                $text = trim($matrix[$levelIndex][$column] ?? '');

                if ($text !== '') {
                    $fragments[] = $text;
                }
            }

            $headers[] = implode(' ', $fragments);
        }

        return $headers;
    }

    /**
     * Data rows only — Group and Legend rows are dropped here, after being
     * counted into warnings().
     *
     * @param  list<array{number: int, cells: list<string>}>  $bodyEntries
     * @param  array<int, true>  $structuralGroupRows  row numbers already known to be a merged caption — see structuralGroupRowNumbers()
     * @return list<list<string>>
     */
    private function classifyBody(array $bodyEntries, array $structuralGroupRows): array
    {
        $kinds = array_fill(0, count($bodyEntries), ExtractedRowKind::Data);

        // Legend rows trail the table: walk backward from the end while rows
        // keep looking like a caption/key, and stop at the first one that
        // does not — a legend does not have a data row hiding beneath it.
        for ($index = count($bodyEntries) - 1; $index >= 0; $index--) {
            if ($this->looksLikeLegend($bodyEntries[$index]['cells'])) {
                $kinds[$index] = ExtractedRowKind::Legend;

                continue;
            }

            break;
        }

        foreach ($bodyEntries as $index => $entry) {
            if ($kinds[$index] === ExtractedRowKind::Legend) {
                continue;
            }

            // The structural test (read before expansion, from the merge
            // itself) always wins where it applies. It is only silent for
            // sources with no merge information, which is when the content
            // fallback gets a say — and only a caption-shaped text, never
            // mere isolation in an early column, is enough for it to act.
            if (isset($structuralGroupRows[$entry['number']]) || $this->looksLikeGroupByContent($entry['cells'])) {
                $kinds[$index] = ExtractedRowKind::Group;
            }
        }

        $groupCount = count(array_filter($kinds, fn (ExtractedRowKind $kind) => $kind === ExtractedRowKind::Group));
        $legendCount = count(array_filter($kinds, fn (ExtractedRowKind $kind) => $kind === ExtractedRowKind::Legend));

        if ($groupCount > 0) {
            $this->warnings[] = trans_choice(
                ':count linha de agrupamento não foi importada como aluno.|:count linhas de agrupamento não foram importadas como alunos.',
                $groupCount,
                ['count' => $groupCount],
            );
        }

        if ($legendCount > 0) {
            $this->warnings[] = trans_choice(
                ':count linha de legenda foi ignorada.|:count linhas de legenda foram ignoradas.',
                $legendCount,
                ['count' => $legendCount],
            );
        }

        $data = [];

        foreach ($bodyEntries as $index => $entry) {
            if ($kinds[$index] === ExtractedRowKind::Data) {
                $data[] = $entry['cells'];
            }
        }

        return $data;
    }

    /**
     * The fallback for sources with no merge information to read — a TSV or
     * CSV paste, an XLSX without merged cells — where a caption like «Alunos
     * com RTP» arrives as an ordinary row with every other column empty,
     * indistinguishable IN SHAPE from a quiet student who simply has nothing
     * in the later columns, which is the common case for most of a class.
     *
     * The distinction has to be made on CONTENT, not shape: the row's one
     * non-empty cell must actually read like a caption, never merely be
     * alone in an early column. «Ana Silva» alone in column 0 stays Data.
     * «Alunos com RTP» alone in column 0 becomes Group. A student whose name
     * happens to start with the word «Alunos» — «Alunos Ferreira», say —
     * stays Data too, because the pattern requires «com»/«sem» right after
     * it, which an actual surname will not produce.
     *
     * Where this is unsure, the row is left as Data on purpose: a caption
     * imported as a row is a single click to untick in the preview; a child
     * this test wrongly swallowed is a silent, unrecoverable loss.
     *
     * @param  list<string>  $row
     */
    private function looksLikeGroupByContent(array $row): bool
    {
        $nonEmpty = [];

        foreach ($row as $index => $cell) {
            if (trim($cell) !== '') {
                $nonEmpty[] = ['index' => $index, 'text' => trim($cell)];
            }
        }

        if (count($nonEmpty) !== 1) {
            return false;
        }

        $candidate = $nonEmpty[0];

        if ($candidate['index'] > 1) {
            return false;
        }

        $text = $candidate['text'];

        if (preg_match('/^alunos\s+(com|sem)\b/iu', $text) === 1) {
            return true;
        }

        return str_ends_with($text, ':');
    }

    /**
     * A trailing caption/key: a " - " glossary pair («MU - Medidas
     * Universais»), or a row starting with a recognised legend marker.
     *
     * @param  list<string>  $row
     */
    private function looksLikeLegend(array $row): bool
    {
        $nonEmpty = array_values(array_filter($row, fn (string $cell) => trim($cell) !== ''));

        if ($nonEmpty === []) {
            return false;
        }

        foreach ($nonEmpty as $cell) {
            $folded = mb_strtolower($cell);

            if (str_starts_with($folded, 'legenda') || str_starts_with($folded, 'nota:') || str_starts_with($folded, 'key:')) {
                return true;
            }

            if (str_contains($cell, ' - ')) {
                return true;
            }
        }

        return false;
    }
}
