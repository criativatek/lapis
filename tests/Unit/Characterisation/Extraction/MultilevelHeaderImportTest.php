<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\ClassifyColumns;
use App\Services\Characterisation\Import\Extraction\ExtractedCell;
use App\Services\Characterisation\Import\Extraction\ExtractedRow;
use App\Services\Characterisation\Import\Extraction\ExtractedTable;
use App\Services\Characterisation\Import\Extraction\ExtractedTableSource;
use App\Services\Characterisation\Import\Extraction\HtmlTableExtractor;
use App\Services\Characterisation\Import\Extraction\NormalisedTable;
use App\Services\Characterisation\Import\Extraction\NormaliseExtractedTable;
use App\Services\Characterisation\Import\Extraction\SpreadsheetTableExtractor;
use Illuminate\Http\UploadedFile;
use Tests\Fixtures\Characterisation\MultilevelHeaderFixture;
use Tests\TestCase;

/**
 * JANELA J: the realistic shape that used to send FindHeaderRow to the
 * «Alunos com RTP» group caption instead of the real header — because the
 * real header's OWN name column prints no label at all — and, separately, a
 * "spine" column that let a group/legend row leak through as an extra
 * student. See MultilevelHeaderFixture's own docblock for the dataset and
 * NormaliseExtractedTable::wideCaptionCellOf()/captionRowIndices() for the
 * production fix.
 *
 * Cases A-K below are named to match the JANELA J brief 1:1.
 */
class MultilevelHeaderImportTest extends TestCase
{
    private function normalise(ExtractedTable $table): NormalisedTable
    {
        return (new NormaliseExtractedTable)->normalise($table);
    }

    private function extractHtml(): ExtractedTable
    {
        $tables = (new HtmlTableExtractor)->extract(MultilevelHeaderFixture::toWordClipboardHtml());

        $this->assertCount(1, $tables);

        return $tables[0];
    }

    private function extractXlsx(): ExtractedTable
    {
        $path = sys_get_temp_dir().'/'.uniqid('janela_j_').'.xlsx';
        MultilevelHeaderFixture::writeXlsx($path);

        try {
            $tables = (new SpreadsheetTableExtractor)->extract(
                new UploadedFile($path, 'caracterizacao.xlsx', null, null, true),
            );
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $tables);

        return $tables[0];
    }

    private function expectedNames(): array
    {
        return MultilevelHeaderFixture::studentNames();
    }

    /**
     * A) a group row that appears AFTER the header never becomes the header.
     */
    public function test_a_group_row_after_the_header_never_becomes_the_header(): void
    {
        $result = $this->normalise($this->extractHtml());

        $this->assertNotContains(MultilevelHeaderFixture::groupCaptionWithRtp(), $result->grid->headers);
        $this->assertNotContains(MultilevelHeaderFixture::groupCaptionWithoutRtp(), $result->grid->headers);
    }

    /**
     * B) the merged full-width group row itself never becomes the header —
     * THE CENTRAL BUG: before the fix, «Alunos com RTP», repeated across
     * every column by expand(), out-scored the real (unlabelled) header row
     * and FindHeaderRow returned ITS index.
     */
    public function test_the_group_row_itself_never_becomes_the_header(): void
    {
        $result = $this->normalise($this->extractHtml());

        foreach ($result->grid->headers as $header) {
            $this->assertStringNotContainsString('Alunos com RTP', $header);
            $this->assertStringNotContainsString('Alunos sem RTP', $header);
        }
    }

    /**
     * C) the multi-level header still joins to the correct semantic column
     * names, despite column 0 printing no label on either header row.
     */
    public function test_the_multilevel_header_joins_to_the_correct_column_names(): void
    {
        $result = $this->normalise($this->extractHtml());

        $this->assertSame(
            MultilevelHeaderFixture::expectedHeadersFromColumn1(),
            array_slice($result->grid->headers, 1),
        );
    }

    /**
     * D) realistic XLSX -> content columns correctly recognised.
     */
    public function test_xlsx_content_columns_are_recognised(): void
    {
        $result = $this->normalise($this->extractXlsx());

        $this->assertSame(
            MultilevelHeaderFixture::expectedHeadersFromColumn1(),
            array_slice($result->grid->headers, 1),
        );

        $names = array_map(fn ($row) => $result->grid->cell($row, 0), $result->grid->rows);
        $this->assertSame($this->expectedNames(), $names);
    }

    /**
     * E) realistic clipboard HTML -> same result as XLSX (parity).
     */
    public function test_html_and_xlsx_converge_on_the_same_result(): void
    {
        $htmlResult = $this->normalise($this->extractHtml());
        $xlsxResult = $this->normalise($this->extractXlsx());

        $this->assertSame($htmlResult->grid->headers, $xlsxResult->grid->headers);

        $htmlNames = array_map(fn ($row) => $htmlResult->grid->cell($row, 0), $htmlResult->grid->rows);
        $xlsxNames = array_map(fn ($row) => $xlsxResult->grid->cell($row, 0), $xlsxResult->grid->rows);

        $this->assertSame($htmlNames, $xlsxNames);
        $this->assertSame($this->expectedNames(), $htmlNames);
    }

    /**
     * F) one student row -> exactly one logical candidate row, no
     * duplicates. Mariana Fonseca's row must appear exactly once.
     */
    public function test_one_student_row_produces_exactly_one_data_row(): void
    {
        $result = $this->normalise($this->extractHtml());

        $names = array_map(fn ($row) => $result->grid->cell($row, 0), $result->grid->rows);

        $this->assertSame(1, count(array_filter($names, fn ($name) => $name === 'Mariana Fonseca')));
    }

    /**
     * G) students below the SECOND group row ("Alunos sem RTP") survive and
     * are still classified correctly — including Leonor Machado, who has
     * nothing but her name.
     */
    public function test_students_below_the_second_group_row_survive(): void
    {
        $result = $this->normalise($this->extractHtml());

        $names = array_map(fn ($row) => $result->grid->cell($row, 0), $result->grid->rows);

        $this->assertContains('Leonor Machado', $names);
        $this->assertContains('Guilherme Antunes', $names);
        $this->assertSame($this->expectedNames(), $names);
    }

    /**
     * H) legend rows are ignored, never become header or data.
     */
    public function test_legend_rows_are_ignored(): void
    {
        $result = $this->normalise($this->extractHtml());

        $names = array_map(fn ($row) => $result->grid->cell($row, 0), $result->grid->rows);

        $this->assertNotContains(MultilevelHeaderFixture::legendLine(), $names);
        foreach ($result->grid->headers as $header) {
            $this->assertStringNotContainsString('continua', $header);
        }

        $kinds = array_column($result->structuralRows, 'kind');
        $this->assertContains('legend', $kinds);
    }

    /**
     * I) MU/MS/MA columns get the correct SupportMeasureLevel.
     */
    public function test_mu_ms_ma_get_the_correct_support_measure_level(): void
    {
        $result = $this->normalise($this->extractHtml());

        $columns = (new ClassifyColumns)->classify($result->grid);
        $byHeader = collect($columns)->keyBy('header');

        $this->assertSame('measures', $byHeader['MU']->role->value);
        $this->assertSame('universal', $byHeader['MU']->level?->value);

        $this->assertSame('measures', $byHeader['MS']->role->value);
        $this->assertSame('selective', $byHeader['MS']->level?->value);

        $this->assertSame('measures', $byHeader['MA']->role->value);
        $this->assertSame('additional', $byHeader['MA']->level?->value);
    }

    /**
     * J) the "Apoios" sub-columns (P, M, Ing., Outros) get resource/support
     * roles.
     */
    public function test_the_apoios_sub_columns_get_resource_roles(): void
    {
        $result = $this->normalise($this->extractHtml());

        $columns = (new ClassifyColumns)->classify($result->grid);
        $byHeader = collect($columns)->keyBy('header');

        foreach (['Apoios P', 'Apoios M', 'Apoios Ing.', 'Apoios Outros'] as $header) {
            $this->assertSame('resources', $byHeader[$header]->role->value, "Column \"{$header}\" should classify as resources.");
        }
    }

    /**
     * K) a mixed "Outras medidas/recursos / Observações" column keeps
     * existing (already-fixed 0.152.x) behaviour: it classifies as Measures
     * AND is flagged as also naming free text — must not regress.
     */
    public function test_the_mixed_observations_column_keeps_measures_and_free_text_behaviour(): void
    {
        $result = $this->normalise($this->extractHtml());

        $columns = (new ClassifyColumns)->classify($result->grid);
        $byHeader = collect($columns)->keyBy('header');

        $header = 'Outras medidas/recursos / Observações';

        $this->assertSame('measures', $byHeader[$header]->role->value);
        $this->assertTrue($byHeader[$header]->alsoFreeText);
    }

    /**
     * THE REAL 0/0 BUG, reproduced directly: before the fix, FindHeaderRow
     * landed on the group caption row, headerLevels() joined garbage header
     * text from it, and no content column was recognised at all.
     */
    public function test_the_header_is_found_and_not_a_single_header_reads_as_a_group_caption(): void
    {
        $result = $this->normalise($this->extractHtml());

        $this->assertNotEmpty(array_filter($result->grid->headers, fn ($h) => $h !== ''));

        foreach ($result->grid->headers as $header) {
            $this->assertStringNotContainsString('Alunos', $header);
        }

        $this->assertNotEmpty($result->grid->rows);
    }

    // ------------------------------------------------------------------
    // SECOND DEFECT: a vertical "spine" column letting a group/legend row
    // leak through as Data.
    // ------------------------------------------------------------------

    /**
     * Unit-level: structuralCaptionRowNumbers()/wideCaptionCellOf() must
     * recognise a caption row that sits UNDER a spine's rowspan (and so owns
     * one fewer cell than the table's full width), not just a row that is a
     * single cell covering the whole width.
     */
    public function test_a_caption_row_under_a_spine_column_is_recognised_structurally(): void
    {
        $rows = [
            new ExtractedRow(1, [
                new ExtractedCell('Turma', 1, 1),
                new ExtractedCell('Nome', 1, 2),
                new ExtractedCell('Observações', 1, 3),
            ]),
            new ExtractedRow(2, [
                new ExtractedCell('8.º A', 2, 1, rowspan: 4),
                new ExtractedCell('Alunos com RTP', 2, 2, colspan: 2),
            ]),
            new ExtractedRow(3, [
                new ExtractedCell('Ana Silva', 3, 2),
                new ExtractedCell('Participa', 3, 3),
            ]),
            new ExtractedRow(4, [
                new ExtractedCell('Bruno Costa', 4, 2),
                new ExtractedCell('Falta muito', 4, 3),
            ]),
            new ExtractedRow(5, [
                new ExtractedCell('X (continua) - N (novo)', 5, 2, colspan: 2),
            ]),
        ];

        $table = new ExtractedTable($rows, ExtractedTableSource::PastedHtml);

        $result = $this->normalise($table);

        // Exactly 2 real data rows — Ana Silva and Bruno Costa — never 4
        // (which is what "Alunos com RTP" and the legend leaking through as
        // Data used to produce alongside them).
        $this->assertCount(2, $result->grid->rows);

        // Column 0 is "Turma" — the spine — repeated by expand() into
        // every row it covers; the student's own name is column 1.
        $names = array_map(fn ($row) => $result->grid->cell($row, 1), $result->grid->rows);
        $this->assertSame(['Ana Silva', 'Bruno Costa'], $names);

        $kinds = array_column($result->structuralRows, 'kind', 'number');
        $this->assertSame('group', $kinds[2]);
        $this->assertSame('legend', $kinds[5]);
    }

    /**
     * The same spine defect, through the real HtmlTableExtractor entry
     * point — proving the fix holds once HTML rowspan semantics (a
     * continuation row owns no cell at all for the spanned column, never a
     * placeholder) reach NormaliseExtractedTable via the real parser, not a
     * hand-built ExtractedTable.
     */
    public function test_the_spine_defect_is_fixed_through_the_real_html_extractor(): void
    {
        $tables = (new HtmlTableExtractor)->extract(MultilevelHeaderFixture::toSpineClipboardHtml());
        $this->assertCount(1, $tables);

        $result = $this->normalise($tables[0]);

        $this->assertCount(2, $result->grid->rows);

        $names = array_map(fn ($row) => $result->grid->cell($row, 1), $result->grid->rows);
        $this->assertSame(['Ana Silva', 'Bruno Costa'], $names);
    }
}
