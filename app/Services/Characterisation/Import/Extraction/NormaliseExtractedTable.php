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
 *     Legend rows are dropped but COUNTED, and the count is returned alongside
 *     the grid (see NormalisedTable) so the controller can tell the teacher
 *     what was left out and why, rather than the row simply vanishing.
 *
 * NORMALISE() RETURNS ITS WARNINGS RATHER THAN STORING THEM. It used to set
 * an instance property during normalise() and expose it through a separate
 * warnings() call — safe only as long as this class is built fresh per
 * import, and silently wrong the day it is ever bound as a singleton
 * (Octane, or an accidental container change): a second, concurrent
 * normalise() call on the same instance would leak one organization's
 * dropped rows into another's response before the first caller ever reads
 * warnings(). Returning a NormalisedTable — the grid AND its warnings,
 * together, from the one call that produced them — makes that impossible
 * rather than a convention to remember. This class keeps no state between
 * calls at all.
 */
class NormaliseExtractedTable
{
    private const MAX_ROWS = 500;

    private const MAX_COLUMNS = 40;

    private const MAX_CELLS = 20000;

    public function normalise(ExtractedTable $table): NormalisedTable
    {
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
        $hadMergedCells = $this->hasMergedCells($table);

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

        // §39: a row already carries an explicit kind (Header/Data/Group/
        // Legend) only when it came BACK from the structural correction step
        // — every other source hands NormaliseExtractedTable rows tagged
        // Unknown, exactly as it always did (see ExtractedRow's own
        // docblock). Once the teacher herself has said what a row is, this
        // class must not re-guess it: the whole point of §39 is that a
        // correction sticks, so a "MU - Medidas Universais" row she marked
        // Data does not silently flip back to Legend on the next preview.
        $explicitKinds = $this->explicitRowKinds($table);

        // Header entries too — separately from $bodyEntries — purely so
        // structuralRows (below) can show the header row(s) as editable rows
        // in §38's grid, kind='header', exactly as a Group/Legend row is
        // shown. $headers itself (the joined column captions) is unaffected.
        $headerEntries = [];

        if ($explicitKinds !== []) {
            [$headers, $headerEntries, $bodyEntries, $bodyKinds, $bodyWarnings] = $this->splitPreClassified($entries, $explicitKinds, $columnCount);
            $headerWarnings = [];
        } else {
            $plainMatrix = array_map(fn (array $entry) => $entry['cells'], $entries);

            $primaryHeaderIndex = (new FindHeaderRow)->find($plainMatrix);

            $headerWarnings = [];

            if ($primaryHeaderIndex === null) {
                // F7: FindHeaderRow could not confidently name a header row in
                // the first 15 — this used to fall back to declaring row 0 the
                // header anyway and slicing it off, which silently discarded
                // whatever that row actually was (very often the first real
                // student, on a header-less export). Refusing the whole import
                // here would be the OTHER extreme: a table this scorer simply
                // does not recognise the header shape of is not the same thing
                // as a table with nothing useful in it. So EVERY row is kept as
                // data instead — nothing is guessed away — with generic column
                // labels standing in for a header nobody could identify, and a
                // warning telling the teacher to check the columns landed right.
                $headers = $this->genericHeaders($columnCount);
                $bodyEntries = $entries;
                $headerWarnings[] = __('Não foi possível identificar a linha de títulos desta tabela — todas as linhas foram importadas como alunos. Confirme se as colunas ficaram corretas.');
            } else {
                $headerLevels = $this->headerLevels($plainMatrix, $primaryHeaderIndex);
                // headerLevels() always seeds itself with the primary header row
                // before optionally prefixing earlier levels, so the last
                // element — the highest index — is always present.
                $headerEndIndex = $headerLevels[count($headerLevels) - 1];
                $headers = $this->joinHeaderLevels($plainMatrix, $headerLevels, $columnCount);
                $bodyEntries = array_slice($entries, $headerEndIndex + 1);
                $headerEntries = array_slice($entries, 0, $headerEndIndex + 1);
            }

            // classifyBody()'s own first return value (the Data-only cells) is
            // recomputed below, uniformly for both branches, from $bodyEntries
            // + $bodyKinds — so only the warnings and the per-row kinds are
            // taken from it here.
            [, $bodyWarnings, $bodyKinds] = $this->classifyBody($bodyEntries, $structuralGroupRows);
        }

        $warnings = [...$headerWarnings, ...$bodyWarnings];

        if (count($headers) > self::MAX_COLUMNS) {
            throw new UnreadableSpreadsheet(__('A tabela tem colunas a mais para ser lida com segurança.'));
        }

        $dataRows = [];

        foreach ($bodyEntries as $index => $entry) {
            if ($bodyKinds[$index] === ExtractedRowKind::Data) {
                $dataRows[] = $entry['cells'];
            }
        }

        if (count($dataRows) > self::MAX_ROWS) {
            throw new UnreadableSpreadsheet(__('A tabela tem :count linhas — mais do que esta importação aceita de uma vez. Importe uma turma de cada vez.', [
                'count' => count($dataRows),
            ]));
        }

        $grid = new TableGrid($headers, array_map(
            fn (array $row) => array_map('strval', array_slice($row, 0, count($headers))),
            $dataRows,
        ));

        $structuralRows = [];

        foreach ($headerEntries as $entry) {
            $structuralRows[] = [
                'number' => $entry['number'],
                'kind' => ExtractedRowKind::Header->value,
                'cells' => array_slice($entry['cells'], 0, $columnCount),
            ];
        }

        foreach ($bodyEntries as $index => $entry) {
            $structuralRows[] = [
                'number' => $entry['number'],
                'kind' => $bodyKinds[$index]->value,
                'cells' => array_slice($entry['cells'], 0, $columnCount),
            ];
        }

        // Rows must reach the UI in their ORIGINAL table order — header
        // first, then body — never grouped by kind, or the structural grid
        // would no longer read top-to-bottom like the source table it is
        // reviewing.
        usort($structuralRows, fn (array $a, array $b) => $a['number'] <=> $b['number']);

        return new NormalisedTable(
            grid: $grid,
            warnings: $warnings,
            structuralHeaders: $headers,
            structuralRows: $structuralRows,
            hadMergedCells: $hadMergedCells,
            wasPreClassified: $explicitKinds !== [],
        );
    }

    /**
     * Whether any cell of the source table spans more than one row/column —
     * the trigger §38 uses to decide a pasted-HTML table is "complex" enough
     * to warrant the structural review step (see the controller). A .docx or
     * an OCR'd image are always shown that step regardless of this flag; a
     * plain paste/CSV/XLSX never carries merge information at all, so this is
     * always false for them.
     */
    private function hasMergedCells(ExtractedTable $table): bool
    {
        foreach ($table->rows as $row) {
            foreach ($row->cells as $cell) {
                if ($cell->colspan > 1 || $cell->rowspan > 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, ExtractedRowKind> keyed by ExtractedRow::$index — see explicitKinds' use in normalise()
     */
    private function explicitRowKinds(ExtractedTable $table): array
    {
        $kinds = [];

        foreach ($table->rows as $row) {
            if ($row->kind !== ExtractedRowKind::Unknown) {
                $kinds[$row->index] = $row->kind;
            }
        }

        return $kinds;
    }

    /**
     * Partitions an already-classified table (every row explicitly tagged by
     * the §39 structural correction step) directly by the kind the teacher
     * gave it — no FindHeaderRow, no content-based group/legend guessing.
     * Header rows are joined the same way joinHeaderLevels() already does for
     * an auto-detected header, so a corrected table's columns read exactly
     * like an ordinary one's.
     *
     * @param  list<array{number: int, cells: list<string>}>  $entries
     * @param  array<int, ExtractedRowKind>  $explicitKinds
     * @return array{0: list<string>, 1: list<array{number: int, cells: list<string>}>, 2: list<array{number: int, cells: list<string>}>, 3: list<ExtractedRowKind>, 4: list<string>}
     */
    private function splitPreClassified(array $entries, array $explicitKinds, int $columnCount): array
    {
        $headerEntries = [];
        $bodyEntries = [];
        $bodyKinds = [];

        foreach ($entries as $entry) {
            $kind = $explicitKinds[$entry['number']] ?? ExtractedRowKind::Data;

            if ($kind === ExtractedRowKind::Header) {
                $headerEntries[] = $entry;

                continue;
            }

            $bodyEntries[] = $entry;
            $bodyKinds[] = $kind;
        }

        $headers = $headerEntries === []
            ? $this->genericHeaders($columnCount)
            : $this->joinCellLevels(array_map(fn (array $entry) => $entry['cells'], $headerEntries), $columnCount);

        $warnings = [];

        $groupCount = count(array_filter($bodyKinds, fn (ExtractedRowKind $kind) => $kind === ExtractedRowKind::Group));
        $legendCount = count(array_filter($bodyKinds, fn (ExtractedRowKind $kind) => $kind === ExtractedRowKind::Legend));

        if ($groupCount > 0) {
            $warnings[] = trans_choice(
                ':count linha de agrupamento não foi importada como aluno.|:count linhas de agrupamento não foram importadas como alunos.',
                $groupCount,
                ['count' => $groupCount],
            );
        }

        if ($legendCount > 0) {
            $warnings[] = trans_choice(
                ':count linha de legenda foi ignorada.|:count linhas de legenda foram ignoradas.',
                $legendCount,
                ['count' => $legendCount],
            );
        }

        return [$headers, $headerEntries, $bodyEntries, $bodyKinds, $warnings];
    }

    /**
     * @param  list<list<string>>  $levels
     * @return list<string>
     */
    private function joinCellLevels(array $levels, int $columnCount): array
    {
        $headers = [];

        for ($column = 0; $column < $columnCount; $column++) {
            $fragments = [];

            foreach ($levels as $level) {
                $text = trim($level[$column] ?? '');

                if ($text !== '') {
                    $fragments[] = $text;
                }
            }

            $headers[] = implode(' ', $fragments);
        }

        return $headers;
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

        // Bounded BEFORE allocating, not after. Every extractor already
        // refuses a source with more than MAX_ROWS <tr>/w:tr/… elements, but
        // that check never sees this method's input: it runs on the SOURCE's
        // own row count, while this reads ExtractedCell::$row/$rowspan, which
        // is a plain int a caller could construct with any value at all (an
        // ExtractedTable built by hand, or a future source whose row numbers
        // come from somewhere sparse). A row number in the tens of thousands
        // would otherwise turn array_fill() below into a multi-hundred-
        // megabyte allocation for a handful of actual cells — refused here,
        // at the one place that is about to make that allocation, rather
        // than relying on every future caller to have already checked.
        if ($totalRows > self::MAX_ROWS) {
            throw new UnreadableSpreadsheet(__('A tabela tem :count linhas — mais do que esta importação aceita de uma vez. Importe uma turma de cada vez.', [
                'count' => $totalRows,
            ]));
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
    private function headerLevels(array $matrix, int $primary): array
    {
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
     * Stand-in column labels for a table FindHeaderRow could not identify a
     * header row in at all (F7) — «Coluna 1», «Coluna 2», … — so downstream
     * (the preview, ClassifyColumns) still has a non-empty string per column
     * rather than needing a special case for "no header". The teacher sees
     * these in the preview and can rename what matters; what they must never
     * see is one of their own students silently missing instead.
     *
     * @return list<string>
     */
    private function genericHeaders(int $columnCount): array
    {
        $headers = [];

        for ($column = 1; $column <= $columnCount; $column++) {
            $headers[] = __('Coluna :number', ['number' => $column]);
        }

        return $headers;
    }

    /**
     * F13: this used to accept ANY row with 2+ short (<=40 char) non-empty
     * cells as a joinable header level — which is also exactly the shape a
     * school's own letterhead takes above the real header: «Escola Básica de
     * Miraflores» next to «2026/2027», both short, both non-empty. Joined the
     * same way a real «Apoio»/«Ing.» pair is, that turns every column's
     * label into «Escola Básica de Miraflores Nome» — the institution's name
     * leaking into data the preview, and eventually the class record,
     * actually stores.
     *
     * A genuine header level — «Apoio», «Medidas», «Ing.» — is a LABEL: one
     * or two words, never a digit in sight. A letterhead line is a
     * SENTENCE-ISH FRAGMENT (a school's full name tends to run three words
     * or more) or carries a year («2026/2027», «2026»), which a column
     * label never does. Both are now enough on their own to disqualify the
     * whole row from being folded into the header.
     *
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

            if (preg_match('/\d/u', $cell) === 1) {
                return false;
            }

            if (count(preg_split('/\s+/u', trim($cell)) ?: []) > 3) {
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
     * counted into the warnings returned alongside the data rows.
     *
     * @param  list<array{number: int, cells: list<string>}>  $bodyEntries
     * @param  array<int, true>  $structuralGroupRows  row numbers already known to be a merged caption — see structuralGroupRowNumbers()
     * @return array{0: list<list<string>>, 1: list<string>, 2: list<ExtractedRowKind>}
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

        $warnings = [];

        if ($groupCount > 0) {
            $warnings[] = trans_choice(
                ':count linha de agrupamento não foi importada como aluno.|:count linhas de agrupamento não foram importadas como alunos.',
                $groupCount,
                ['count' => $groupCount],
            );
        }

        if ($legendCount > 0) {
            $warnings[] = trans_choice(
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

        return [$data, $warnings, array_values($kinds)];
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
     * A trailing caption/key: a «MU - Medidas Universais»-style glossary
     * line, or a row starting with a recognised legend marker («Legenda»,
     * «Nota:», «Key:»).
     *
     * THIS USED TO MATCH ANY CELL CONTAINING " - " AT ALL, which also
     * matches ordinary free-text observations a teacher writes about a real
     * student — «Apoio tutorial - 2x por semana» reads exactly like that, and
     * the backward legend-trim walk above would drop that student's whole
     * row as if it were a caption. A caption wrongly imported is one untick
     * in the preview; a child silently missing from it is not recoverable —
     * so this now requires the row to be STRUCTURALLY caption-like before a
     * " - " is allowed to mean anything:
     *
     *  - few non-empty cells (a real row of data — name, notes, measures —
     *    tends to fill more of the table than a one- or two-cell caption
     *    does);
     *  - no cell reads like a person's name (two or more capitalised words,
     *    no digits — the same shape a name takes everywhere else in this
     *    importer); a row that names someone is a student, never a glossary
     *    entry;
     *  - AND at least one cell has the glossary's own shape: a short
     *    acronym followed by " - " («MU - …», «AAA - …»), not merely any
     *    text that happens to contain a hyphen surrounded by spaces.
     *
     * Where none of this is met, the row stays Data — exactly the same
     * "when in doubt, do not drop" rule looksLikeGroupByContent() documents
     * for group rows.
     *
     * @param  list<string>  $row
     */
    private function looksLikeLegend(array $row): bool
    {
        $nonEmpty = array_values(array_filter($row, fn (string $cell) => trim($cell) !== ''));

        if ($nonEmpty === [] || count($nonEmpty) > 2) {
            return false;
        }

        foreach ($nonEmpty as $cell) {
            if ($this->looksLikePersonName($cell)) {
                return false;
            }
        }

        foreach ($nonEmpty as $cell) {
            $folded = mb_strtolower($cell);

            if (str_starts_with($folded, 'legenda') || str_starts_with($folded, 'nota:') || str_starts_with($folded, 'key:')) {
                return true;
            }

            // A short KEY followed by " - " — an acronym («MU - Medidas
            // Universais»), or a short label a school's own key uses («X
            // (continua) - N (novo)», «Coadjuvação - trabalho articulado…»).
            // "Short" is what does the work here, not case: a real
            // observation's lead-in before its own " - " tends to run
            // longer than a glossary's key ever does, and — more
            // importantly — this test only ever runs on a cell that already
            // survived the name check above, so a row that also names a
            // student («Bruno Costa | Apoio tutorial - 2x por semana») never
            // reaches this branch at all: it was ruled out by
            // looksLikePersonName() first, whatever this pattern matches.
            if (preg_match('/^.{1,28}?\s-\s/u', trim($cell)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two or more capitalised words, no digits — the shape a person's name
     * takes throughout this importer. Deliberately loose: it exists only to
     * RULE OUT a cell from being mistaken for a caption, so a false positive
     * here (treating some non-name text as name-shaped) merely keeps a row
     * as Data, which is always the safe direction.
     */
    private function looksLikePersonName(string $cell): bool
    {
        $trimmed = trim($cell);

        if ($trimmed === '' || preg_match('/\d/u', $trimmed) === 1) {
            return false;
        }

        return preg_match('/^\p{Lu}[\p{Ll}\'-]+(\s+(d[aeo]s?|e)\s+|\s+)\p{Lu}[\p{Ll}\'-]+/u', $trimmed) === 1;
    }
}
