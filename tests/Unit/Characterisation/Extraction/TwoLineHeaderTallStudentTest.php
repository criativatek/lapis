<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\ClassifyColumns;
use App\Services\Characterisation\Import\ColumnRole;
use App\Services\Characterisation\Import\Extraction\ExtractedRowKind;
use App\Services\Characterisation\Import\Extraction\ExtractedTable;
use App\Services\Characterisation\Import\Extraction\HtmlTableExtractor;
use App\Services\Characterisation\Import\Extraction\NormalisedTable;
use App\Services\Characterisation\Import\Extraction\NormaliseExtractedTable;
use App\Services\Characterisation\Import\Extraction\SpreadsheetTableExtractor;
use Illuminate\Http\UploadedFile;
use Tests\Fixtures\Characterisation\TwoLineHeaderTallStudentFixture as Fixture;
use Tests\TestCase;

/**
 * JANELA N — the two structural defects a real «Medidas» workbook exposed,
 * proved on a sanitised copy of its geometry (see the fixture's docblock;
 * no real student ever reaches this repository).
 *
 *  1. The SECOND HEADER ROW came back classified as a student, because its
 *     own columns are not a subset of anything the row above merged across
 *     horizontally — the two rows are a header because they CLOSE A
 *     RECTANGLE, not because one subdivides the other.
 *
 *  2. The FIRST STUDENT came back as FOUR students, because her four
 *     printed rows — four lines of «MS» and of «Observações», every other
 *     column merged vertically across them — were never folded back into
 *     one logical row.
 *
 * Both are asserted for the .xlsx and for the Word clipboard HTML from the
 * same fixture, because a teacher hits this shape both ways and the answer
 * has to be the same one.
 */
class TwoLineHeaderTallStudentTest extends TestCase
{
    private function normalise(ExtractedTable $table): NormalisedTable
    {
        return (new NormaliseExtractedTable)->normalise($table);
    }

    private function extractXlsx(): ExtractedTable
    {
        $path = sys_get_temp_dir().'/'.uniqid('janela_n_').'.xlsx';
        Fixture::writeXlsx($path);

        try {
            $tables = (new SpreadsheetTableExtractor)->extract(
                new UploadedFile($path, 'medidas.xlsx', null, null, true),
            );
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $tables);

        return $tables[0];
    }

    private function extractHtml(): ExtractedTable
    {
        $tables = (new HtmlTableExtractor)->extract(Fixture::toWordClipboardHtml());

        $this->assertCount(1, $tables);

        return $tables[0];
    }

    /**
     * The fixture has to keep the real workbook's geometry or it proves
     * nothing — 21 printed rows, 15 columns.
     */
    public function test_the_fixture_reproduces_the_real_physical_geometry(): void
    {
        $table = $this->extractXlsx();

        $this->assertSame(Fixture::COLUMN_COUNT, $table->columnCount());

        $lastRow = 0;

        foreach ($table->rows as $row) {
            foreach ($row->cells as $cell) {
                $lastRow = max($lastRow, $cell->row + $cell->rowspan - 1);
            }
        }

        $this->assertSame(Fixture::PHYSICAL_ROW_COUNT, $lastRow);
    }

    public function test_the_second_header_row_is_a_header_and_not_a_student_in_xlsx(): void
    {
        $this->assertSecondHeaderRowIsAHeader($this->normalise($this->extractXlsx()));
    }

    public function test_the_second_header_row_is_a_header_and_not_a_student_in_word_html(): void
    {
        $this->assertSecondHeaderRowIsAHeader($this->normalise($this->extractHtml()));
    }

    public function test_a_student_printed_across_four_rows_is_one_student_in_xlsx(): void
    {
        $this->assertOneStudentPerLogicalRow($this->normalise($this->extractXlsx()));
    }

    public function test_a_student_printed_across_four_rows_is_one_student_in_word_html(): void
    {
        $this->assertOneStudentPerLogicalRow($this->normalise($this->extractHtml()));
    }

    /**
     * The point of §9 of the brief: the workbook and the Word paste of the
     * SAME table must normalise to the same logical table.
     */
    public function test_xlsx_and_word_html_agree_on_the_logical_table(): void
    {
        $fromXlsx = $this->normalise($this->extractXlsx());
        $fromHtml = $this->normalise($this->extractHtml());

        $this->assertSame($fromXlsx->grid->headers, $fromHtml->grid->headers);
        $this->assertSame($fromXlsx->grid->rows, $fromHtml->grid->rows);
    }

    /**
     * §7: the four printed lines of «MS» and «Observações» are joined back
     * into the one cell they visually are — never dropped, never spread
     * across four candidate students.
     */
    public function test_the_tall_cells_are_joined_back_into_one_multiline_value(): void
    {
        $grid = $this->normalise($this->extractXlsx())->grid;

        $first = $grid->rows[0];

        $this->assertSame(Fixture::tallStudentMeasures(), $first[3]);
        $this->assertSame(Fixture::tallStudentObservations(), $first[14]);
    }

    /**
     * §11: the students below the SECOND group caption survive — this is
     * the "o preview final termina sem alunos associados" half of the
     * report, and it is the same defect: eleven students were being pushed
     * out of the table by the first student's four copies and the header
     * row that became a student above them.
     */
    public function test_every_student_below_the_second_group_survives(): void
    {
        $grid = $this->normalise($this->extractXlsx())->grid;

        $names = array_map(fn (array $row): string => $row[0], $grid->rows);

        $this->assertSame(Fixture::studentNames(), $names);
        $this->assertCount(12, $names);
    }

    /**
     * Both full-width captions stay captions, and are shown as such in
     * §38's structural grid rather than silently vanishing.
     */
    public function test_both_group_captions_are_classified_group(): void
    {
        $structural = $this->normalise($this->extractXlsx())->structuralRows;

        $captions = array_values(array_filter(
            $structural,
            fn (array $row): bool => in_array(
                $row['cells'][0],
                [Fixture::groupCaptionWithRtp(), Fixture::groupCaptionWithoutRtp()],
                true,
            ),
        ));

        $this->assertCount(2, $captions);

        foreach ($captions as $caption) {
            $this->assertSame(ExtractedRowKind::Group->value, $caption['kind']);
        }
    }

    /**
     * The gate the teacher actually feels: a name column is recognised, so
     * the preview has students to show at all.
     */
    public function test_the_name_column_is_recognised(): void
    {
        $grid = $this->normalise($this->extractXlsx())->grid;

        $roles = (new ClassifyColumns)->classify($grid);

        $nameColumns = array_values(array_filter(
            $roles,
            fn ($column): bool => $column->role === ColumnRole::StudentName,
        ));

        $this->assertCount(1, $nameColumns);
        $this->assertSame(0, $nameColumns[0]->index);
    }

    private function assertSecondHeaderRowIsAHeader(NormalisedTable $normalised): void
    {
        // The second level's own labels are joined INTO the column captions,
        // which is only possible if it was read as a header level at all.
        $this->assertStringContainsString('Observações', $normalised->grid->headers[14]);
        $this->assertStringContainsString('Ing.', $normalised->grid->headers[8]);

        // And it is never offered back to the teacher as a body row.
        foreach ($normalised->structuralRows as $row) {
            if ($row['cells'][0] === '3.º ciclo') {
                $this->assertSame(ExtractedRowKind::Header->value, $row['kind']);
            }
        }

        foreach ($normalised->grid->rows as $row) {
            $this->assertNotSame('3.º ciclo', $row[0]);
        }
    }

    private function assertOneStudentPerLogicalRow(NormalisedTable $normalised): void
    {
        $names = array_map(fn (array $row): string => $row[0], $normalised->grid->rows);

        $tallName = Fixture::tallStudent()['merged'][0];

        $this->assertSame(1, count(array_keys($names, $tallName, true)));
        $this->assertCount(12, $names);
    }
}
