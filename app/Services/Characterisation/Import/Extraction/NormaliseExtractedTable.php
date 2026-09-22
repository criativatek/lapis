<?php

namespace App\Services\Characterisation\Import\Extraction;

use App\Services\Characterisation\Import\ClassifyColumns;
use App\Services\Characterisation\Import\ColumnRole;
use App\Services\Characterisation\Import\FindHeaderRow;
use App\Services\Characterisation\Import\LooksLikePersonName;
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
        // aligned without needing to smuggle a kind through the matrix. Holds
        // the CAPTION CELL'S OWN TEXT, not a boolean — a full-width merge is
        // structurally a caption of SOME kind, but which kind (Group or
        // Legend) is a content question classifyBody() still has to ask; see
        // its own comment for why deciding it here, as a flat "always
        // Group", was wrong.
        $structuralCaptionRows = $this->structuralCaptionRowNumbers($table, $columnCount);
        $hadMergedCells = $this->hasMergedCells($table);

        $expanded = $this->expand($table, $columnCount);
        $matrix = $expanded['cells'];
        $confidenceMatrix = $expanded['confidences'];

        $entries = [];

        foreach ($matrix as $zeroBasedIndex => $cells) {
            // §18: the confidence row travels alongside 'cells' inside the
            // SAME entry, rather than as a separate parallel array, so every
            // later step that filters/slices/reorders $entries (the blank-row
            // filter just below, splitPreClassified(), the header/body split)
            // carries it along for free instead of needing its own copy of
            // that bookkeeping.
            $entries[] = [
                'number' => $zeroBasedIndex + 1,
                'cells' => $cells,
                'confidences' => $confidenceMatrix[$zeroBasedIndex] ?? array_fill(0, $columnCount, null),
            ];
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

        // F3: all-or-nothing. explicitRowKinds() only ever sees the kind a
        // row was tagged with — it has no way to tell "the client meant this
        // row to be Data" apart from "the client never classified this row
        // at all". splitPreClassified() defaults every untagged row to Data,
        // which is correct for a table §39 itself produced (every row IS
        // tagged there) but silently wrong for a hand-built or malformed
        // `extracted_table` payload that tagged only SOME rows: the header
        // row and any legend would be swept into Data as if a teacher had
        // reviewed and approved them, and `wasPreClassified` would suppress
        // the one step that could have caught it. A partial tagging is
        // therefore refused outright rather than guessed at half of.
        if ($explicitKinds !== [] && count($explicitKinds) !== count($entries)) {
            throw new UnreadableSpreadsheet(__('A tabela enviada tem linhas classificadas e outras não — isso não é permitido. Classifique todas as linhas ou nenhuma.'));
        }

        // Header entries too — separately from $bodyEntries — purely so
        // structuralRows (below) can show the header row(s) as editable rows
        // in §38's grid, kind='header', exactly as a Group/Legend row is
        // shown. $headers itself (the joined column captions) is unaffected.
        $headerEntries = [];

        // JANELA L: the rows ABOVE the header that are not header levels —
        // a title, a "Diretor de turma: … | N.º de alunos: 24" banner split
        // into two or three merged blocks. Kept apart from $headerEntries so
        // they reach §38's grid as Legend (ignored) rather than as
        // kind='header'. See the comment at the assignment below for the
        // round-trip defect that tagging them 'header' caused.
        $aboveHeaderEntries = [];

        if ($explicitKinds !== []) {
            [$headers, $headerEntries, $bodyEntries, $bodyKinds, $bodyWarnings] = $this->splitPreClassified($entries, $explicitKinds, $columnCount);
            $headerWarnings = [];
        } else {
            $plainMatrix = array_map(fn (array $entry) => $entry['cells'], $entries);

            // Caption/legend rows must never be offered to FindHeaderRow as
            // a candidate — see captionRowIndices()'s own comment for why
            // this has to be computed here, on $entries, rather than left to
            // FindHeaderRow itself, which only ever sees the already-
            // expanded matrix a merged caption is indistinguishable in.
            $captionRowIndices = $this->captionRowIndices($entries, $structuralCaptionRows);

            $primaryHeaderIndex = (new FindHeaderRow)->find($plainMatrix, $captionRowIndices);

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
                $headerLevels = $this->headerLevels($table, $plainMatrix, $primaryHeaderIndex, $columnCount, $captionRowIndices);
                // headerLevels() always seeds itself with the primary header row
                // before optionally prefixing earlier levels or appending a
                // level below — so the LAST element is always present, but is
                // no longer guaranteed to be the primary row itself (a level
                // below sorts after it).
                $headerEndIndex = $headerLevels[count($headerLevels) - 1]['index'];
                $headers = $this->joinHeaderLevels($plainMatrix, $headerLevels, $columnCount);
                $bodyEntries = array_slice($entries, $headerEndIndex + 1);

                // JANELA L — THE ROUND-TRIP DEFECT THIS SPLIT EXISTS FOR.
                // Only the rows headerLevels() actually chose as LEVELS may
                // be handed back as kind='header'. Everything else above the
                // header was already excluded from joinHeaderLevels() right
                // here — but a plain array_slice() used to sweep it into
                // $headerEntries anyway, so it reached §38's grid tagged
                // 'header' too.
                //
                // On the §39 round trip that tag is ALL splitPreClassified()
                // has to go on: it joins every teacher-tagged header row into
                // the column names. A banner this request had correctly
                // ignored therefore came back on the NEXT request as part of
                // every single header ("N.º de alunos: 24 Medidas MU"), which
                // is enough to lose the student-name column outright — and a
                // table with no name column answers «todas as linhas foram
                // identificadas como rodapé ou totais» for a structural
                // review the teacher had just approved as correct.
                //
                // isFullWidthCaptionShape() catches only the uniform,
                // single-merge case of this; a banner in two or three blocks
                // is not uniform, and by the time expand() has run it is not
                // distinguishable from a genuine header level by shape alone.
                // So the distinction is kept where it is still KNOWN — here,
                // from headerLevels() itself — rather than re-derived later
                // from cells that no longer carry it.
                $levelIndices = array_column($headerLevels, 'index');

                foreach (array_slice($entries, 0, $headerEndIndex + 1, preserve_keys: true) as $index => $entry) {
                    if (in_array($index, $levelIndices, true)) {
                        $headerEntries[] = $entry;

                        continue;
                    }

                    $aboveHeaderEntries[] = $entry;
                }
            }

            // classifyBody()'s own first return value (the Data-only cells) is
            // recomputed below, uniformly for both branches, from $bodyEntries
            // + $bodyKinds — so only the warnings and the per-row kinds are
            // taken from it here.
            [, $bodyWarnings, $bodyKinds] = $this->classifyBody($bodyEntries, $structuralCaptionRows);
        }

        $warnings = [...$headerWarnings, ...$bodyWarnings];

        if (count($headers) > self::MAX_COLUMNS) {
            throw new UnreadableSpreadsheet(__('A tabela tem colunas a mais para ser lida com segurança.'));
        }

        $dataRows = [];
        $dataConfidences = [];

        foreach ($bodyEntries as $index => $entry) {
            if ($bodyKinds[$index] === ExtractedRowKind::Data) {
                $dataRows[] = $entry['cells'];
                $dataConfidences[] = $entry['confidences'] ?? array_fill(0, $columnCount, null);
            }
        }

        if (count($dataRows) > self::MAX_ROWS) {
            throw new UnreadableSpreadsheet(__('A tabela tem :count linhas — mais do que esta importação aceita de uma vez. Importe uma turma de cada vez.', [
                'count' => count($dataRows),
            ]));
        }

        $grid = new TableGrid(
            $headers,
            array_map(
                fn (array $row) => array_map('strval', array_slice($row, 0, count($headers))),
                $dataRows,
            ),
            array_map(
                fn (array $confidences) => array_slice($confidences, 0, count($headers)),
                $dataConfidences,
            ),
        );

        // §J: ClassifyColumns's own last-resort fallback (see
        // ClassifyColumns::inferNameColumnFromContent()) may have just
        // picked the student-name column by CONTENT rather than by a
        // printed title, because no header anywhere in this table named
        // one. That must never reach the teacher as a silent guess — it is
        // re-run here, on the finished $grid, for the SOLE purpose of
        // surfacing that fact as a warning; BuildCharacterisationPreview
        // classifies the same grid again for the preview itself, exactly as
        // FindHeaderRow already classifies a candidate row separately from
        // the classification the preview later performs on the chosen one.
        foreach ((new ClassifyColumns)->classify($grid) as $column) {
            if ($column->role === ColumnRole::StudentName && $column->inferredFromContent) {
                $warnings[] = __('Não foi encontrada uma coluna com o nome do aluno identificada por título — a coluna «:header» foi selecionada por conter nomes. Confirme se está correta.', [
                    'header' => $column->header !== '' ? $column->header : __('(sem título)'),
                ]);

                break;
            }
        }

        $structuralRows = [];

        foreach ($headerEntries as $entry) {
            $structuralRows[] = [
                'number' => $entry['number'],
                'kind' => ExtractedRowKind::Header->value,
                'cells' => array_slice($entry['cells'], 0, $columnCount),
            ];
        }

        // JANELA L: shown to the teacher, and ignored — which is exactly
        // what this request already did with them. Tagged Legend rather than
        // Header so that resubmitting the grid unchanged reproduces THIS
        // result instead of contradicting it.
        foreach ($aboveHeaderEntries as $entry) {
            $structuralRows[] = [
                'number' => $entry['number'],
                'kind' => ExtractedRowKind::Legend->value,
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

        // A row the teacher tagged 'header' can still be a full-width
        // caption/title expanded across every column by expand() — see the
        // class docblock's root-cause note. Excluding it here from the JOIN
        // is not re-classifying her row: it stays kind='header' and is still
        // shown to her in structuralRows below, exactly as she left it. It
        // simply stops contributing its (repeated) text to every column's
        // name, which is what made the round-trip in §39's own idempotency
        // guarantee non-idempotent — see joinableHeaderEntries()'s own
        // comment for the uniformity rule used to detect it.
        $joinableHeaderEntries = $this->joinableHeaderEntries($headerEntries, $columnCount);

        $headers = $joinableHeaderEntries === []
            ? $this->genericHeaders($columnCount)
            : $this->joinCellLevels(array_map(fn (array $entry) => $entry['cells'], $joinableHeaderEntries), $columnCount);

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
     * The teacher-tagged 'header' rows that may actually contribute their
     * text to the joined column names — every one of $headerEntries EXCEPT a
     * full-width caption/title row expanded by expand() into looking like an
     * ordinary header level.
     *
     * The non-pre-classified path (headerLevels()/captionRowIndices()) can
     * tell a caption apart from a real header level because it still has the
     * PRE-expansion merge structure to read (see structuralCaptionRowNumbers()
     * and the class docblock). By the time a row reaches here, on the §39
     * round trip, that structure is long gone — the dialog resubmits plain
     * cells, already expanded, with only a kind attached. So the test has to
     * work on the expanded shape itself: a row whose non-empty cells are ALL
     * the same repeated value, spanning (nearly) the full width of the table,
     * is exactly what a full-width merged cell looks like once expand() has
     * repeated its text into every column it covers — no ordinary header
     * level, single- or multi-row, produces that shape, because each of its
     * columns names a DIFFERENT thing.
     *
     * "Nearly" full width mirrors wideCaptionCellOf()'s own slack: a caption
     * occasionally leaves one trailing column outside its merge. Requiring
     * NEAR-full-width uniformity (not just "some repeated cells") is what
     * keeps this from misfiring on a genuine header level that happens to
     * repeat a value in a column or two (e.g. two adjacent sub-headers both
     * reading "Ing.") — a coincidence in two columns is not the same shape as
     * every column in the row reading identically.
     *
     * A row is excluded from the JOIN only — it is never removed from
     * $headerEntries itself, so the teacher still sees it, unmodified,
     * kind='header', in structuralRows.
     *
     * @param  list<array{number: int, cells: list<string>}>  $headerEntries
     * @return list<array{number: int, cells: list<string>}>
     */
    private function joinableHeaderEntries(array $headerEntries, int $columnCount): array
    {
        return array_values(array_filter(
            $headerEntries,
            fn (array $entry) => ! $this->isFullWidthCaptionShape($entry['cells'], $columnCount),
        ));
    }

    /**
     * @param  list<string>  $cells
     */
    private function isFullWidthCaptionShape(array $cells, int $columnCount): bool
    {
        $nonEmpty = array_values(array_filter(
            array_map('trim', $cells),
            fn (string $cell) => $cell !== '',
        ));

        if (count($nonEmpty) < 2) {
            return false;
        }

        // Mirrors wideCaptionCellOf()'s own "nearly all" slack: a caption may
        // leave the very last column outside its merge.
        if (count($nonEmpty) < $columnCount - 1) {
            return false;
        }

        return count(array_unique($nonEmpty)) === 1;
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

                if ($text === '') {
                    continue;
                }

                // A rowspan-2 header cell ("RTP/PEI") is repeated by expand()
                // into BOTH the printed row it labels and the row below it —
                // that repetition is what keeps that column from shifting for
                // the level below (see expand()'s own docblock), but by the
                // time these are separate 'header' rows on the §39 round
                // trip, joining every level verbatim would turn it into
                // "RTP/PEI RTP/PEI". A column whose header genuinely spans
                // two DIFFERENT levels ("Apoios" / "P") never repeats the
                // same text twice in a row, so skipping only an IMMEDIATE
                // repeat of the fragment just added never drops real content.
                if ($fragments !== [] && end($fragments) === $text) {
                    continue;
                }

                $fragments[] = $text;
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
     * Row numbers => the caption cell's OWN trimmed text (not a boolean):
     * classifyBody() still has to ask, by CONTENT, whether a structurally
     * detected full-width caption is a Group («Alunos com RTP», naming no
     * student) or a Legend («X (continua) - N (novo)», a glossary key). The
     * original single-cell text is what that content test needs — reading it
     * off the EXPANDED matrix instead (after expand() has repeated it across
     * every column it covers) would fail looksLikeLegend()'s own "few
     * non-empty cells" heuristic, which is tuned for the merge-less CSV/TSV
     * fallback and never expects a caption to already be N cells wide.
     *
     * @return array<int, string> keyed by ExtractedRow::$index
     */
    private function structuralCaptionRowNumbers(ExtractedTable $table, int $columnCount): array
    {
        $rowNumbers = [];

        foreach ($table->rows as $row) {
            $caption = $this->wideCaptionCellOf($row, $columnCount);

            if ($caption !== null) {
                $rowNumbers[$row->index] = trim($caption->text);
            }
        }

        return $rowNumbers;
    }

    /**
     * THE SPINE CASE: a caption row underneath a vertical "spine" column — a
     * class-name cell merged down the whole sheet (e.g. `rowspan="9"` on
     * «8.º A», anchored several rows above) — owns no cell for that column
     * AT ALL on the caption's own row; that column is simply not among
     * $row->cells, exactly as if the row genuinely had one fewer column (see
     * ExtractedCell/the extractors: a rowspan continuation is never
     * re-emitted on the covered row). The strict `count($row->cells) === 1`
     * this method used to require breaks the moment a caption row sits under
     * such a spine and ALSO happens to still own a narrow cell of its own
     * there (a source that DOES re-emit a placeholder for the spanned
     * column, unlike the ordinary HTML/.docx/.xlsx shape above) — two cells,
     * neither of them wrong, and the caption silently stopped being
     * recognised as one, leaking through as an ordinary Data row.
     *
     * So: at most ONE cell is allowed to be a spine (narrow — never wider
     * than 2 columns, which covers a short label like «8.º A» but never a
     * real second content column — and tall: rowspan > 1, anchored on an
     * earlier row, not the caption's own content). Whichever remaining cell
     * is widest is the caption candidate, and its required width is judged
     * against the table's width MINUS whatever the spine already accounts
     * for — a caption next to a spine only ever needs to cover the columns
     * the spine does not.
     */
    private function wideCaptionCellOf(ExtractedRow $row, int $columnCount): ?ExtractedCell
    {
        $nonEmpty = array_values(array_filter(
            $row->cells,
            fn (ExtractedCell $cell) => trim($cell->text) !== '',
        ));

        if ($nonEmpty === [] || count($nonEmpty) > 2) {
            return null;
        }

        usort($nonEmpty, fn (ExtractedCell $a, ExtractedCell $b) => $b->colspan <=> $a->colspan);

        $caption = $nonEmpty[0];
        $spine = $nonEmpty[1] ?? null;

        if ($spine !== null && ($spine->colspan > 2 || $spine->rowspan <= 1)) {
            // Two ordinary side-by-side cells, neither of them a spine — an
            // ordinary two-column row, not a caption.
            return null;
        }

        $spineWidth = $spine !== null ? $spine->colspan : 0;
        $requiredWidth = $columnCount - $spineWidth;

        // "Nearly all": a caption occasionally leaves the very last column
        // outside its merge (a trailing decorative cell), so the width is
        // allowed to fall one short of the full required count — but a row
        // with only one or two columns of its own has no room for that
        // slack, or every ordinary single-column cell would qualify.
        if ($requiredWidth >= 3 && $caption->colspan >= $requiredWidth - 1) {
            return $caption;
        }

        if ($requiredWidth < 3 && $caption->colspan >= $requiredWidth) {
            return $caption;
        }

        return null;
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
     * Builds the EXTRACTION-CONFIDENCE matrix (§18) in the same pass, for the
     * same reason it builds the text matrix here rather than re-walking
     * $table a second time: a merged cell's confidence, like its text, has to
     * be repeated across every cell it covers, and that repetition is exactly
     * what this loop already does. A cell with no confidence (every source
     * but OCR) simply repeats null, which is the honest answer for a hole
     * merged cell as much as it is for one read exactly.
     *
     * @return array{cells: list<list<string>>, confidences: list<list<?float>>}
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
        $confidenceMatrix = array_fill(0, $totalRows, array_fill(0, $columnCount, null));

        foreach ($table->rows as $row) {
            foreach ($row->cells as $cell) {
                for ($r = $cell->row; $r < $cell->row + $cell->rowspan; $r++) {
                    for ($c = $cell->column; $c < $cell->column + $cell->colspan; $c++) {
                        if ($r - 1 >= 0 && $r - 1 < $totalRows && $c - 1 >= 0 && $c - 1 < $columnCount) {
                            $matrix[$r - 1][$c - 1] = $cell->text;
                            $confidenceMatrix[$r - 1][$c - 1] = $cell->confidence;
                        }
                    }
                }
            }
        }

        // array_fill/array assignment-in-place already produce contiguous
        // integer keys for the outer array; each row is routed through
        // array_values too so its keys are known-contiguous the same way.
        return [
            'cells' => array_map('array_values', $matrix),
            'confidences' => array_map('array_values', $confidenceMatrix),
        ];
    }

    /**
     * Positions in $entries (0-based, aligned with $plainMatrix — see the
     * caller) that must never be offered to FindHeaderRow, or joined into
     * the header by headerLevels(), however they score or read: a row
     * already known to be a caption or legend, either structurally (a
     * full-width merge, read before expand() — see
     * structuralCaptionRowNumbers()'s own docblock) or, for a merge-less
     * source that carries no colspan to read at all, by the SAME content
     * tests classifyBody() uses for a body row of that shape. Without this,
     * a full-width «Alunos com RTP» caption — repeated across every column
     * by expand() — scores as if it named the student in every one of them
     * (roleFor() reads "aluno" out of "Alunos"), and often outscores the
     * real header, whose own name column may print no label at all. See
     * the class docblock's root-cause note for the concrete failure this
     * closes.
     *
     * @param  list<array{number: int, cells: list<string>}>  $entries
     * @param  array<int, string>  $structuralCaptionRows  keyed by ExtractedRow::$index — see structuralCaptionRowNumbers()
     * @return array<int, true>
     */
    private function captionRowIndices(array $entries, array $structuralCaptionRows): array
    {
        $indices = [];

        foreach ($entries as $index => $entry) {
            if (isset($structuralCaptionRows[$entry['number']])) {
                $indices[$index] = true;

                continue;
            }

            if ($this->looksLikeGroupByContent($entry['cells']) || $this->looksLikeLegend($entry['cells'])) {
                $indices[$index] = true;
            }
        }

        return $indices;
    }

    /**
     * The levels making up the header, in printed order. A single level for
     * an ordinary table; several when the header spans more than one printed
     * row — «Apoio» over «Ing.» becomes column «Apoio Ing.».
     *
     * `ownColumns` is null for the primary row and for any level found by
     * walking UPWARD (report preamble sits above the header and, like the
     * primary row itself, contributes to every column it has text in). It is
     * an explicit column set for a level found BELOW the primary row — see
     * the second block below for why that direction needs one.
     *
     * @param  list<list<string>>  $matrix
     * @param  array<int, true>  $captionRowIndices  see captionRowIndices() — a caption/legend row can never become a header level in either direction, walking up or looking one row down, however header-shaped its text happens to read
     * @return list<array{index: int, ownColumns: ?array<int, true>}>
     */
    private function headerLevels(ExtractedTable $table, array $matrix, int $primary, int $columnCount, array $captionRowIndices = []): array
    {
        $levels = [['index' => $primary, 'ownColumns' => null]];

        // Walk upward while the row above still looks like a header level
        // rather than report preamble: short labels, more than one of them,
        // none reading like a sentence — and never a row already known to be
        // a caption or legend, whatever it happens to read like.
        $index = $primary - 1;

        while ($index >= 0 && $primary - $index <= 2 && ! isset($captionRowIndices[$index]) && $this->looksLikeHeaderLevel($matrix[$index])) {
            array_unshift($levels, ['index' => $index, 'ownColumns' => null]);
            $index--;
        }

        // A header can also continue BELOW the primary row: a merged cell in
        // the primary row (colspan>1, «Apoios») splits into short sub-labels
        // one printed row down («P», «Ing.») — see this class's docblock for
        // why FindHeaderRow, scoring on the rowspan-EXPANDED matrix, always
        // lands on the primary row and never on this one. By the time this
        // runs, the row below reads as a "full" row too — «Aluno», «RTP/PEI»
        // and «Observações» were repeated into it by the very same rowspan —
        // so the ONLY reliable signal left is which columns that row OWNS in
        // $table itself, read before expand() ever ran. A row that owns
        // every column is an ordinary data row, however short its cells
        // happen to be, and never qualifies.
        $belowRowNumber = $primary + 2; // 1-based ExtractedRow::$index of the printed row right below the primary one.
        $ownColumns = $this->ownColumnsOf($table, $belowRowNumber);

        // F2: a second header level exists to SUBDIVIDE a colspan the
        // primary row itself declared («Apoios» split into «P»/«Ing.») —
        // never merely because some earlier row's rowspan happens to reach
        // down this far. `rowspanCoveredColumns()` used to accept a rowspan
        // from ANY earlier row, including an ordinary left "spine" column
        // (e.g. a class-name cell merged A1:A9) that has nothing to do with
        // the header at all: with a spine, row 2's own columns are a subset
        // of the header's TOTAL columns for exactly the wrong reason — the
        // spine, not a colspan — and the first real student got folded into
        // the header, silently, with no warning and (for .xlsx, whose
        // structural review step didn't exist for this shape) no way back.
        // Requiring $ownColumns to be a strict, non-empty subset of columns
        // the PRIMARY header row itself merged across with colspan>1 rules
        // that out: a plain vertical spine never has colspan>1 in the header
        // row, only rowspan, so it no longer qualifies.
        $headerColspanColumns = $this->headerColspanColumns($table, $primary + 1);

        if ($ownColumns !== null
            && $ownColumns !== []
            && count($ownColumns) < $columnCount
            && $primary + 1 < count($matrix)
            && ! isset($captionRowIndices[$primary + 1])
            && $this->isSubsetOf($ownColumns, $headerColspanColumns)
            && $this->looksLikeHeaderLevel($this->onlyOwnColumns($matrix[$primary + 1], $ownColumns))
        ) {
            $levels[] = ['index' => $primary + 1, 'ownColumns' => $ownColumns];
        }

        return $levels;
    }

    /**
     * F2: the 0-based column indices the PRIMARY header row itself merges
     * across with a colspan greater than 1 — i.e. the columns a second
     * header level would exist to subdivide. Deliberately reads only cells
     * belonging to $primaryRowNumber, and only a colspan (never a rowspan):
     * a left "spine" column merged vertically down the whole sheet (e.g. a
     * class name spanning A1:A9) never shows up here, however far its
     * rowspan reaches, because it is not a header subdivision — see
     * headerLevels()'s own comment for the concrete spine case this rules
     * out.
     *
     * @return array<int, true>
     */
    private function headerColspanColumns(ExtractedTable $table, int $primaryRowNumber): array
    {
        $columns = [];

        foreach ($table->rows as $row) {
            if ($row->index !== $primaryRowNumber) {
                continue;
            }

            foreach ($row->cells as $cell) {
                if ($cell->colspan <= 1) {
                    continue;
                }

                for ($c = $cell->column; $c < $cell->column + $cell->colspan; $c++) {
                    $columns[$c - 1] = true;
                }
            }
        }

        return $columns;
    }

    /**
     * Whether every column of $subset also appears in $superset, and $subset
     * is not itself empty — used by headerLevels() to require the row below
     * the primary header to own ONLY columns the header itself subdivided
     * (see headerColspanColumns()), never merely SOME of them alongside
     * others a colspan never touched.
     *
     * @param  array<int, true>  $subset
     * @param  array<int, true>  $superset
     */
    private function isSubsetOf(array $subset, array $superset): bool
    {
        if ($subset === []) {
            return false;
        }

        foreach (array_keys($subset) as $column) {
            if (! isset($superset[$column])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The 0-based column indices an ExtractedRow OWNS — i.e. the columns
     * covered by that row's OWN cells, never a column merely showing that
     * row's index because an earlier row's rowspan repeats into it. null
     * when no row with this number exists at all (the primary header row was
     * the table's last one, say).
     *
     * @return ?array<int, true>
     */
    private function ownColumnsOf(ExtractedTable $table, int $rowNumber): ?array
    {
        foreach ($table->rows as $row) {
            if ($row->index !== $rowNumber) {
                continue;
            }

            $columns = [];

            foreach ($row->cells as $cell) {
                for ($c = $cell->column; $c < $cell->column + $cell->colspan; $c++) {
                    $columns[$c - 1] = true;
                }
            }

            return $columns;
        }

        return null;
    }

    /**
     * $row with every column NOT in $ownColumns blanked out — so
     * looksLikeHeaderLevel() judges a continuation row purely on the short
     * labels it actually contributes («P», «Ing.»), never on text a rowspan
     * from the row above merely repeated into it («Aluno», «Observações»),
     * which would otherwise make an ordinary, un-splittable header look like
     * report preamble (too many long-ish cells) or fail the test outright.
     *
     * @param  list<string>  $row
     * @param  array<int, true>  $ownColumns
     * @return list<string>
     */
    private function onlyOwnColumns(array $row, array $ownColumns): array
    {
        $result = [];

        foreach ($row as $column => $cell) {
            $result[] = isset($ownColumns[$column]) ? $cell : '';
        }

        return $result;
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
     * @param  list<array{index: int, ownColumns: ?array<int, true>}>  $levels
     * @return list<string>
     */
    private function joinHeaderLevels(array $matrix, array $levels, int $columnCount): array
    {
        $headers = [];

        for ($column = 0; $column < $columnCount; $column++) {
            $fragments = [];

            foreach ($levels as $level) {
                // A level below the primary row only contributes to the
                // columns it OWNS — see headerLevels()'s own comment. Every
                // other level (the primary row, and any found walking
                // upward) has `ownColumns === null` and contributes to every
                // column it has text in, exactly as before.
                if ($level['ownColumns'] !== null && ! isset($level['ownColumns'][$column])) {
                    continue;
                }

                $text = trim($matrix[$level['index']][$column] ?? '');

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
     * @param  array<int, string>  $structuralCaptionRows  row numbers already known to be a full-width merged caption, keyed to the caption's own text — see structuralCaptionRowNumbers()
     * @return array{0: list<list<string>>, 1: list<string>, 2: list<ExtractedRowKind>}
     */
    private function classifyBody(array $bodyEntries, array $structuralCaptionRows): array
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
            // itself) always wins WHETHER a full-width merged row is dropped
            // at all — it is only silent for sources with no merge
            // information, which is when the content fallback
            // (looksLikeGroupByContent()) gets a say instead, and only a
            // caption-shaped text, never mere isolation in an early column,
            // is enough for it to act. But a full-width merge is a caption
            // of SOME kind, not necessarily a Group — «X (continua) - N
            // (novo)» is a legend, not a grouping caption, and importing it
            // as one tells the teacher a wrong count and a wrong reason. So
            // WHICH kind still comes from content, exactly as it always has
            // for the merge-less fallback.
            if (isset($structuralCaptionRows[$entry['number']])) {
                $kinds[$index] = $this->looksLikeLegendCaption($structuralCaptionRows[$entry['number']])
                    ? ExtractedRowKind::Legend
                    : ExtractedRowKind::Group;

                continue;
            }

            if ($this->looksLikeGroupByContent($entry['cells'])) {
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
     * The same content test looksLikeLegend() runs per-cell, applied to a
     * single string instead of a row of cells — for a row the STRUCTURE
     * already identified as a full-width merged caption (see
     * structuralCaptionRowNumbers()), where the "few non-empty cells" gate
     * looksLikeLegend() needs for the merge-less CSV/TSV fallback does not
     * apply at all: a merged caption is already known to be one cell's
     * worth of content, expanded or not.
     */
    private function looksLikeLegendCaption(string $text): bool
    {
        $trimmed = trim($text);

        if ($trimmed === '' || $this->looksLikePersonName($trimmed)) {
            return false;
        }

        $folded = mb_strtolower($trimmed);

        if (str_starts_with($folded, 'legenda') || str_starts_with($folded, 'nota:') || str_starts_with($folded, 'key:')) {
            return true;
        }

        // A short KEY followed by " - " — see looksLikeLegend()'s own
        // comment on this same pattern for what it is meant to catch and why
        // "short" is what does the work.
        return preg_match('/^.{1,28}?\s-\s/u', $trimmed) === 1;
    }

    /**
     * Deliberately loose: it exists only to RULE OUT a cell from being
     * mistaken for a caption, so a false positive here (treating some
     * non-name text as name-shaped) merely keeps a row as Data, which is
     * always the safe direction.
     *
     * Delegates to LooksLikePersonName — see that class's own docblock for
     * why this is no longer its own regex.
     */
    private function looksLikePersonName(string $cell): bool
    {
        return LooksLikePersonName::check($cell);
    }
}
