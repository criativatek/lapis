<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\Extraction\ExtractedCell;
use App\Services\Characterisation\Import\Extraction\ExtractedRow;
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

        $normaliser = new NormaliseExtractedTable;
        $grid = $normaliser->normalise($table);

        $this->assertSame(['Nome', 'Observações'], $grid->headers);
        $this->assertCount(2, $grid->rows);
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[0], 0));
        $this->assertSame('Bruno Costa', $grid->cell($grid->rows[1], 0));

        $this->assertNotEmpty($normaliser->warnings());
        $this->assertStringContainsString('2', $normaliser->warnings()[0]);
    }

    public function test_a_trailing_legend_is_dropped_and_counted(): void
    {
        $table = $this->tableFrom([
            ['Nome', 'Medidas'],
            ['Ana Silva', 'MU'],
            ['MU - Medidas Universais', ''],
        ]);

        $normaliser = new NormaliseExtractedTable;
        $grid = $normaliser->normalise($table);

        $this->assertCount(1, $grid->rows);
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[0], 0));
        $this->assertNotEmpty($normaliser->warnings());
    }

    public function test_multi_level_headers_join_top_to_bottom(): void
    {
        $table = $this->tableFrom([
            ['', 'Apoio', 'Apoio'],
            ['Nome', 'Ing.', 'Mat.'],
            ['Ana Silva', 'ACNS', ''],
        ]);

        $grid = (new NormaliseExtractedTable)->normalise($table);

        $this->assertSame(['Nome', 'Apoio Ing.', 'Apoio Mat.'], $grid->headers);
        $this->assertCount(1, $grid->rows);
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

        $grid = (new NormaliseExtractedTable)->normalise($table);

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

        $grid = (new NormaliseExtractedTable)->normalise($table);

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

        $normaliser = new NormaliseExtractedTable;
        $grid = $normaliser->normalise($table);

        $this->assertCount(1, $grid->rows);
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[0], 0));
        $this->assertNotEmpty($normaliser->warnings());
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

        $normaliser = new NormaliseExtractedTable;
        $grid = $normaliser->normalise($table);

        $this->assertCount(1, $grid->rows);
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[0], 0));
        $this->assertNotEmpty($normaliser->warnings());
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

        $grid = (new NormaliseExtractedTable)->normalise($table);

        $this->assertCount(2, $grid->rows);
        $this->assertSame('Alunos Ferreira', $grid->cell($grid->rows[0], 0));
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[1], 0));
    }
}
