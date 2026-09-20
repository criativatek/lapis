<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\Extraction\ExtractedCell;
use App\Services\Characterisation\Import\Extraction\ExtractedRow;
use App\Services\Characterisation\Import\Extraction\ExtractedRowKind;
use App\Services\Characterisation\Import\Extraction\ExtractedTable;
use App\Services\Characterisation\Import\Extraction\ExtractedTableSource;
use App\Services\Characterisation\Import\Extraction\NormaliseExtractedTable;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use Tests\TestCase;

class NormaliseExtractedTableTest extends TestCase
{
    /**
     * @param  list<list<string>>  $matrix
     */
    private function tableFrom(array $matrix): ExtractedTable
    {
        $rows = [];

        foreach ($matrix as $rowIndex => $line) {
            $cells = [];

            foreach ($line as $columnIndex => $text) {
                $cells[] = new ExtractedCell($text, $rowIndex + 1, $columnIndex + 1);
            }

            $rows[] = new ExtractedRow($rowIndex + 1, $cells);
        }

        return new ExtractedTable($rows, ExtractedTableSource::PastedTsv);
    }

    public function test_group_rows_are_dropped_and_counted(): void
    {
        $table = $this->tableFrom([
            ['Nome', 'Observações'],
            ['Alunos com RTP', ''],
            ['Ana Silva', 'Participa'],
            ['Alunos sem RTP', ''],
            ['Bruno Costa', 'Falta muito'],
        ]);

        $result = (new NormaliseExtractedTable)->normalise($table);
        $grid = $result->grid;

        $this->assertSame(['Nome', 'Observações'], $grid->headers);
        $this->assertCount(2, $grid->rows);
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[0], 0));
        $this->assertSame('Bruno Costa', $grid->cell($grid->rows[1], 0));

        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('2', $result->warnings[0]);
    }

    public function test_a_trailing_legend_is_dropped_and_counted(): void
    {
        $table = $this->tableFrom([
            ['Nome', 'Medidas'],
            ['Ana Silva', 'MU'],
            ['MU - Medidas Universais', ''],
        ]);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertCount(1, $result->grid->rows);
        $this->assertSame('Ana Silva', $result->grid->cell($result->grid->rows[0], 0));
        $this->assertNotEmpty($result->warnings);
    }

    public function test_a_real_student_row_with_a_free_text_hyphen_is_not_dropped_as_a_legend(): void
    {
        // "Apoio tutorial - 2x por semana" contains " - " just like a real
        // legend does, but it names no acronym and it is the last row of a
        // real class — dropping it would silently lose a student.
        $table = $this->tableFrom([
            ['Nome', 'Observações'],
            ['Ana Silva', 'Participa'],
            ['Bruno Costa', 'Apoio tutorial - 2x por semana'],
        ]);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertCount(2, $result->grid->rows);
        $this->assertSame('Bruno Costa', $result->grid->cell($result->grid->rows[1], 0));
        $this->assertSame([], $result->warnings);
    }

    public function test_an_actual_legend_row_is_still_dropped(): void
    {
        $table = $this->tableFrom([
            ['Nome', 'Medidas'],
            ['Ana Silva', 'AAA'],
            ['AAA - Apoio ao Aluno', ''],
        ]);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertCount(1, $result->grid->rows);
        $this->assertSame('Ana Silva', $result->grid->cell($result->grid->rows[0], 0));
        $this->assertNotEmpty($result->warnings);
    }

    /**
     * F13 (investigation): a school-letterhead row directly above the real
     * header — two short, capitalised cells, exactly the shape
     * looksLikeHeaderLevel() otherwise accepts as a joinable header level —
     * must NOT be folded into the header text. If this fails, the letterhead
     * leaks into every column's label («Escola Básica de Miraflores Nome»).
     */
    public function test_a_letterhead_row_above_the_header_is_not_joined_into_it(): void
    {
        $table = $this->tableFrom([
            ['Escola Básica de Miraflores', '2026/2027'],
            ['Nome', 'Medidas'],
            ['Ana Silva', 'MU'],
        ]);

        $grid = (new NormaliseExtractedTable)->normalise($table)->grid;

        $this->assertSame(['Nome', 'Medidas'], $grid->headers);
        $this->assertCount(1, $grid->rows);
    }

    public function test_multi_level_headers_join_top_to_bottom(): void
    {
        $table = $this->tableFrom([
            ['', 'Apoio', 'Apoio'],
            ['Nome', 'Ing.', 'Mat.'],
            ['Ana Silva', 'ACNS', ''],
        ]);

        $grid = (new NormaliseExtractedTable)->normalise($table)->grid;

        $this->assertSame(['Nome', 'Apoio Ing.', 'Apoio Mat.'], $grid->headers);
        $this->assertCount(1, $grid->rows);
    }

    /**
     * §13 / defect 1 (2026-09-20 structural review report): a two-level
     * header — «Apoios» merged over two columns in the primary header row,
     * split into «P»/«Ing.» one printed row down via ROWSPAN on the OTHER
     * columns («Aluno», «RTP/PEI», «Observações») rather than via a second
     * merged cell in the row above. FindHeaderRow scores the PRIMARY row
     * (row 1) as the header, because the second-level row only "looks like
     * a header" once its rowspan-inherited cells are stripped away — before
     * this fix, headerLevels() only ever walked UPWARD from the row
     * FindHeaderRow picked, so this second level was never even considered,
     * and it fell through as an ordinary Data row: a teacher could
     * associate a real child to it and overwrite that child's record with
     * "P"/"Ing.".
     */
    public function test_a_second_header_level_split_by_rowspan_is_joined_not_offered_as_data(): void
    {
        $rows = [
            new ExtractedRow(1, [
                new ExtractedCell('Aluno', 1, 1, rowspan: 2),
                new ExtractedCell('RTP/PEI', 1, 2, rowspan: 2),
                new ExtractedCell('Apoios', 1, 3, colspan: 2),
                new ExtractedCell('Observações', 1, 5, rowspan: 2),
            ]),
            new ExtractedRow(2, [
                new ExtractedCell('P', 2, 3),
                new ExtractedCell('Ing.', 2, 4),
            ]),
            new ExtractedRow(3, [
                new ExtractedCell('Maria Santos', 3, 1),
                new ExtractedCell('RTP', 3, 2),
                new ExtractedCell('X', 3, 3),
                new ExtractedCell('', 3, 4),
                new ExtractedCell('Boa evolução.', 3, 5),
            ]),
        ];

        $table = new ExtractedTable($rows, ExtractedTableSource::PastedHtml);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertSame(
            ['Aluno', 'RTP/PEI', 'Apoios P', 'Apoios Ing.', 'Observações'],
            $result->grid->headers,
        );

        // Row 2 ("P"/"Ing.") must NEVER reach the data grid — that is
        // exactly the row that used to be offered to the teacher as an
        // unmatched student.
        $this->assertCount(1, $result->grid->rows);
        $this->assertSame('Maria Santos', $result->grid->cell($result->grid->rows[0], 0));

        // Both header rows are visible in the structural review, as header —
        // never as data, group or legend.
        $headerKinds = array_column($result->structuralRows, 'kind', 'number');
        $this->assertSame('header', $headerKinds[1]);
        $this->assertSame('header', $headerKinds[2]);
    }

    public function test_merged_cells_are_expanded_by_repetition_not_left_as_holes(): void
    {
        $rows = [
            new ExtractedRow(1, [
                new ExtractedCell('Nome', 1, 1),
                new ExtractedCell('Observações', 1, 2),
            ]),
            new ExtractedRow(2, [
                new ExtractedCell('Turma A', 2, 1, rowspan: 2),
                new ExtractedCell('Ana Silva', 2, 2),
            ]),
        ];

        // Row 3 has no cell in column 1 at all — it is covered purely by the
        // rowspan above — which is exactly the shape a DocxTableExtractor or
        // HtmlTableExtractor output would have for a vertically-merged cell.
        $rows[] = new ExtractedRow(3, [
            new ExtractedCell('Bruno Costa', 3, 2),
        ]);

        $table = new ExtractedTable($rows, ExtractedTableSource::Docx);

        $grid = (new NormaliseExtractedTable)->normalise($table)->grid;

        $this->assertSame(['Nome', 'Observações'], $grid->headers);
        $this->assertCount(2, $grid->rows);
        // The rowspan's text is repeated into the second column-1 cell,
        // never left blank.
        $this->assertSame('Turma A', $grid->cell($grid->rows[1], 0));
    }

    public function test_an_empty_table_is_refused(): void
    {
        $this->expectException(UnreadableSpreadsheet::class);

        (new NormaliseExtractedTable)->normalise(new ExtractedTable([], ExtractedTableSource::PastedTsv));
    }

    /**
     * A quiet student — a name and nothing else — is the common case for
     * most of a class, not the exception. Nothing about having blank cells
     * past the name may drop the row.
     */
    public function test_a_row_with_only_a_student_name_survives_as_data(): void
    {
        $table = $this->tableFrom([
            ['Nome', 'Medidas', 'Observações'],
            ['Ana Silva', '', ''],
            ['Bruno Costa', 'MU', 'Participa'],
        ]);

        $grid = (new NormaliseExtractedTable)->normalise($table)->grid;

        $this->assertCount(2, $grid->rows);
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[0], 0));
        $this->assertSame('Bruno Costa', $grid->cell($grid->rows[1], 0));
    }

    /**
     * In a real Word table, «Alunos com RTP» is one cell merged across the
     * full width of the table — colspan equal to the column count. Once
     * expanded, that row would look like an ordinary row with every column
     * filled (all with the same repeated text), which is why this has to be
     * caught from the merge structure BEFORE expansion, not from the shape
     * of the expanded row.
     */
    public function test_a_full_width_merged_caption_is_classified_group_and_dropped(): void
    {
        $rows = [
            new ExtractedRow(1, [
                new ExtractedCell('Nome', 1, 1),
                new ExtractedCell('Medidas', 1, 2),
                new ExtractedCell('Observações', 1, 3),
            ]),
            new ExtractedRow(2, [
                new ExtractedCell('Alunos com RTP', 2, 1, colspan: 3),
            ]),
            new ExtractedRow(3, [
                new ExtractedCell('Ana Silva', 3, 1),
                new ExtractedCell('MU', 3, 2),
                new ExtractedCell('Participa', 3, 3),
            ]),
        ];

        $table = new ExtractedTable($rows, ExtractedTableSource::Docx);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertCount(1, $result->grid->rows);
        $this->assertSame('Ana Silva', $result->grid->cell($result->grid->rows[0], 0));
        $this->assertNotEmpty($result->warnings);
    }

    /**
     * §36 / defect 3 (2026-09-20 structural review report): a full-width
     * merged row is a caption of SOME kind, but not necessarily a Group —
     * «X (continua) - N (novo)» is a legend, explaining abbreviations used
     * above it, not a grouping caption naming the rows that follow. Before
     * this fix, the structural test (which fires first, from the merge
     * itself) always classified ANY full-width merge as Group, so this
     * legend was dropped with a warning that lied about both the count and
     * the reason.
     */
    public function test_a_full_width_merged_legend_is_classified_legend_not_group(): void
    {
        $rows = [
            new ExtractedRow(1, [
                new ExtractedCell('Nome', 1, 1),
                new ExtractedCell('Medidas', 1, 2),
                new ExtractedCell('Observações', 1, 3),
            ]),
            new ExtractedRow(2, [
                new ExtractedCell('Ana Silva', 2, 1),
                new ExtractedCell('MU', 2, 2),
                new ExtractedCell('Participa', 2, 3),
            ]),
            new ExtractedRow(3, [
                new ExtractedCell('X (continua) - N (novo)', 3, 1, colspan: 3),
            ]),
        ];

        $table = new ExtractedTable($rows, ExtractedTableSource::PastedHtml);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertCount(1, $result->grid->rows);
        $this->assertSame('Ana Silva', $result->grid->cell($result->grid->rows[0], 0));

        $kinds = array_column($result->structuralRows, 'kind', 'number');
        $this->assertSame('legend', $kinds[3]);

        $this->assertContains('1 linha de legenda foi ignorada.', $result->warnings);
        $this->assertNotContains('1 linha de agrupamento não foi importada como aluno.', $result->warnings);
    }

    /**
     * The same caption, but arriving from a TSV paste — no colspan at all,
     * just an ordinary row where every column but the first is empty. The
     * structural test cannot see it (there is no merge to read), so the
     * content test has to catch it instead.
     */
    public function test_the_same_caption_from_a_tsv_is_still_classified_group_by_content(): void
    {
        $table = $this->tableFrom([
            ['Nome', 'Medidas', 'Observações'],
            ['Alunos com RTP', '', ''],
            ['Ana Silva', 'MU', 'Participa'],
        ]);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertCount(1, $result->grid->rows);
        $this->assertSame('Ana Silva', $result->grid->cell($result->grid->rows[0], 0));
        $this->assertNotEmpty($result->warnings);
    }

    /**
     * Guards against the content test overreaching: a real student whose
     * name happens to start with the word «Alunos» must not be mistaken for
     * a group caption. The pattern only matches «Alunos com/sem …», which an
     * actual surname will not produce.
     */
    public function test_a_student_name_beginning_with_alunos_is_not_dropped(): void
    {
        $table = $this->tableFrom([
            ['Nome', 'Medidas', 'Observações'],
            ['Alunos Ferreira', '', ''],
            ['Ana Silva', 'MU', 'Participa'],
        ]);

        $grid = (new NormaliseExtractedTable)->normalise($table)->grid;

        $this->assertCount(2, $grid->rows);
        $this->assertSame('Alunos Ferreira', $grid->cell($grid->rows[0], 0));
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[1], 0));
    }

    /**
     * F12: warnings() used to be instance state, set during normalise() and
     * read back afterwards — safe only while nothing ever reused the same
     * instance for two imports. This proves the SAME instance, called twice
     * with different tables (one with a warning-producing group row, one
     * with none), never lets the first call's warnings bleed into the
     * second's result, or vice versa — because each call's warnings now
     * travel WITH that call's grid, on the object normalise() returns,
     * rather than being read back from the normaliser afterwards.
     */
    public function test_warnings_do_not_leak_between_calls_on_the_same_instance(): void
    {
        $withGroupRow = $this->tableFrom([
            ['Nome', 'Observações'],
            ['Alunos com RTP', ''],
            ['Ana Silva', 'Participa'],
        ]);

        $withoutAnyDroppedRow = $this->tableFrom([
            ['Nome', 'Observações'],
            ['Bruno Costa', 'Falta pouco'],
        ]);

        $normaliser = new NormaliseExtractedTable;

        $first = $normaliser->normalise($withGroupRow);
        $this->assertNotEmpty($first->warnings);

        $second = $normaliser->normalise($withoutAnyDroppedRow);
        $this->assertSame([], $second->warnings);

        // Interleaved the other way round too: calling normalise() again
        // for the group-row table must still produce its own warning, not
        // an empty list inherited from the call that ran in between.
        $third = $normaliser->normalise($withGroupRow);
        $this->assertNotEmpty($third->warnings);
        $this->assertSame($first->warnings, $third->warnings);
    }

    /**
     * F7: when nothing in the first 15 rows scores as a confident header,
     * this used to declare row 0 the header anyway and slice it off — which,
     * on a table that genuinely has no header row (or whose header this
     * scorer cannot recognise), silently discarded the first real student.
     * Every row must survive instead, with a warning telling the teacher to
     * check the columns, rather than a guess eating a child's row.
     */
    public function test_a_table_with_no_recognisable_header_keeps_every_row(): void
    {
        // No cell here reads as a student-name-shaped column — every row is
        // a pair of short, generic tokens, so ClassifyColumns never scores
        // any of them as identifying a student, and FindHeaderRow finds
        // nothing to call a header within the first 15 rows.
        $table = $this->tableFrom([
            ['abc', '123'],
            ['def', '456'],
            ['ghi', '789'],
        ]);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertCount(3, $result->grid->rows);
        $this->assertSame('abc', $result->grid->cell($result->grid->rows[0], 0));
        $this->assertSame('def', $result->grid->cell($result->grid->rows[1], 0));
        $this->assertSame('ghi', $result->grid->cell($result->grid->rows[2], 0));
        $this->assertNotEmpty($result->warnings);
    }

    /**
     * F15: a fabricated table whose only cell sits at a sparse, very high
     * row number must be refused promptly — not turned into an
     * array_fill() allocation sized to that row number. 60000 is bounded
     * enough to run fast in a test while still being far past MAX_ROWS
     * (500), which is what should trigger the refusal.
     */
    public function test_a_sparse_high_row_number_is_refused_rather_than_allocated(): void
    {
        $table = new ExtractedTable([
            new ExtractedRow(1, [new ExtractedCell('Nome', 1, 1)]),
            new ExtractedRow(2, [new ExtractedCell('Ana Silva', 60000, 1)]),
        ], ExtractedTableSource::PastedTsv);

        $this->expectException(UnreadableSpreadsheet::class);

        (new NormaliseExtractedTable)->normalise($table);
    }

    /**
     * §38: the structural table must carry EVERY row NormaliseExtractedTable
     * saw — including the group row that never reaches $grid — tagged with
     * the kind it was classified as, so the teacher can see why a row is
     * missing rather than only a count.
     */
    public function test_structural_rows_include_the_dropped_group_row_with_its_kind(): void
    {
        $table = $this->tableFrom([
            ['Nome', 'Observações'],
            ['Alunos com RTP', ''],
            ['Ana Silva', 'Participa'],
        ]);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertSame(['Nome', 'Observações'], $result->structuralHeaders);
        // The header row itself is included too (kind='header'), so the
        // teacher sees the whole recognised table, not just its body.
        $this->assertCount(3, $result->structuralRows);
        $this->assertSame('header', $result->structuralRows[0]['kind']);
        $this->assertSame('group', $result->structuralRows[1]['kind']);
        $this->assertSame('Alunos com RTP', $result->structuralRows[1]['cells'][0]);
        $this->assertSame('data', $result->structuralRows[2]['kind']);
        $this->assertSame('Ana Silva', $result->structuralRows[2]['cells'][0]);
        $this->assertFalse($result->wasPreClassified);
    }

    public function test_had_merged_cells_is_true_only_when_a_cell_spans_more_than_one_row_or_column(): void
    {
        $plain = $this->tableFrom([
            ['Nome', 'Observações'],
            ['Ana Silva', 'Participa'],
        ]);

        $this->assertFalse((new NormaliseExtractedTable)->normalise($plain)->hadMergedCells);

        $merged = new ExtractedTable([
            new ExtractedRow(1, [
                new ExtractedCell('Nome', 1, 1),
                new ExtractedCell('Observações', 1, 2),
            ]),
            new ExtractedRow(2, [
                new ExtractedCell('Ana Silva', 2, 1, colspan: 2),
            ]),
        ], ExtractedTableSource::PastedHtml);

        $this->assertTrue((new NormaliseExtractedTable)->normalise($merged)->hadMergedCells);
    }

    /**
     * §39: once a row carries an EXPLICIT kind (as a table resubmitted from
     * the structural correction step does), NormaliseExtractedTable must
     * honour it instead of re-running its own header/group/legend guesses —
     * a row the teacher marked Data must not silently flip back to Legend
     * just because its text still has the shape looksLikeLegend() matches.
     */
    public function test_an_explicit_kind_is_honoured_instead_of_being_reclassified(): void
    {
        $rows = [
            new ExtractedRow(1, [
                new ExtractedCell('Nome', 1, 1),
                new ExtractedCell('Medidas', 1, 2),
            ], ExtractedRowKind::Header),
            new ExtractedRow(2, [
                new ExtractedCell('Ana Silva', 2, 1),
                new ExtractedCell('MU', 2, 2),
            ], ExtractedRowKind::Data),
            // Legend-shaped text ("MU - Medidas Universais"), but the
            // teacher explicitly marked it Data in the structural step —
            // that decision must stick.
            new ExtractedRow(3, [
                new ExtractedCell('MU - Medidas Universais', 3, 1),
                new ExtractedCell('', 3, 2),
            ], ExtractedRowKind::Data),
        ];

        $table = new ExtractedTable($rows, ExtractedTableSource::PastedTsv);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertSame(['Nome', 'Medidas'], $result->grid->headers);
        $this->assertCount(2, $result->grid->rows);
        $this->assertSame('MU - Medidas Universais', $result->grid->cell($result->grid->rows[1], 0));
        $this->assertTrue($result->wasPreClassified);
        $this->assertSame([], $result->warnings);
    }

    /**
     * The inverse: a row explicitly marked Group in the correction step is
     * dropped even though nothing about its shape would otherwise flag it.
     */
    public function test_an_explicit_group_kind_drops_a_row_that_would_otherwise_look_like_a_student(): void
    {
        $rows = [
            new ExtractedRow(1, [
                new ExtractedCell('Nome', 1, 1),
                new ExtractedCell('Medidas', 1, 2),
            ], ExtractedRowKind::Header),
            new ExtractedRow(2, [
                new ExtractedCell('Turma Piloto', 2, 1),
                new ExtractedCell('n/a', 2, 2),
            ], ExtractedRowKind::Group),
            new ExtractedRow(3, [
                new ExtractedCell('Ana Silva', 3, 1),
                new ExtractedCell('MU', 3, 2),
            ], ExtractedRowKind::Data),
        ];

        $table = new ExtractedTable($rows, ExtractedTableSource::PastedTsv);

        $result = (new NormaliseExtractedTable)->normalise($table);

        $this->assertCount(1, $result->grid->rows);
        $this->assertSame('Ana Silva', $result->grid->cell($result->grid->rows[0], 0));
        $this->assertNotEmpty($result->warnings);
    }
}
