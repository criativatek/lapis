<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\ClassifyColumns;
use App\Services\Characterisation\Import\ColumnRole;
use App\Services\Characterisation\Import\Extraction\ExtractedCell;
use App\Services\Characterisation\Import\Extraction\ExtractedRow;
use App\Services\Characterisation\Import\Extraction\ExtractedRowKind;
use App\Services\Characterisation\Import\Extraction\ExtractedTable;
use App\Services\Characterisation\Import\Extraction\ExtractedTableSource;
use App\Services\Characterisation\Import\Extraction\HtmlTableExtractor;
use App\Services\Characterisation\Import\Extraction\NormalisedTable;
use App\Services\Characterisation\Import\Extraction\NormaliseExtractedTable;
use App\Services\Characterisation\Import\Extraction\SpreadsheetTableExtractor;
use Illuminate\Http\UploadedFile;
use Tests\Fixtures\Characterisation\CharacterisationFixture;
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
     * Rebuilds an ExtractedTable exactly as CharacterisationImportDialog does
     * when the teacher clicks "Continuar" on the structural review step: every
     * row from $result->structuralRows comes back with its (possibly
     * teacher-corrected) kind explicitly set, cells expanded (no merges left
     * to read — that information is gone by the time the dialog has a grid of
     * rows/cells to show), tagged CorrectedPastedHtml (§39).
     */
    private function resubmitAsExtractedTable(NormalisedTable $result): ExtractedTable
    {
        $rows = [];

        foreach ($result->structuralRows as $row) {
            $cells = [];

            foreach ($row['cells'] as $column => $text) {
                $cells[] = new ExtractedCell($text, $row['number'], $column + 1);
            }

            $rows[] = new ExtractedRow(
                $row['number'],
                $cells,
                ExtractedRowKind::from($row['kind']),
            );
        }

        return new ExtractedTable($rows, ExtractedTableSource::CorrectedPastedHtml);
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

    // ------------------------------------------------------------------
    // THIRD DEFECT, one stage further: column 0 of the real table prints no
    // label in EITHER header level, so header-text classification alone
    // never made it StudentName — every row failed the "identifies nobody"
    // check for the SAME structural reason, and the preview still ended at
    // "0 de 0", now one step later than the header-recognition bug above.
    // See ClassifyColumns::inferNameColumnFromContent() for the fix.
    // ------------------------------------------------------------------

    /**
     * RED-BEFORE-GREEN, via the REAL HtmlTableExtractor entry point: the
     * unlabelled name column (index 0) is inferred from its content, and the
     * real students it names come through as StudentName-classified data.
     */
    public function test_the_unlabelled_name_column_is_inferred_via_html_and_students_come_through(): void
    {
        $result = $this->normalise($this->extractHtml());

        $columns = (new ClassifyColumns)->classify($result->grid);
        $this->assertSame(ColumnRole::StudentName, $columns[0]->role, 'Column 0, which prints no title in either header level, must be inferred as the name column.');
        $this->assertTrue($columns[0]->inferredFromContent);

        $names = array_map(fn ($row) => $result->grid->cell($row, 0), $result->grid->rows);
        $this->assertSame($this->expectedNames(), $names);
    }

    /**
     * The same fix, via the REAL xlsx entry point — the fixture provides
     * both from the same source of truth, so a bug that only reproduces in
     * one format cannot hide from this test.
     */
    public function test_the_unlabelled_name_column_is_inferred_via_xlsx_and_students_come_through(): void
    {
        $result = $this->normalise($this->extractXlsx());

        $columns = (new ClassifyColumns)->classify($result->grid);
        $this->assertSame(ColumnRole::StudentName, $columns[0]->role);
        $this->assertTrue($columns[0]->inferredFromContent);

        $names = array_map(fn ($row) => $result->grid->cell($row, 0), $result->grid->rows);
        $this->assertSame($this->expectedNames(), $names);
    }

    /**
     * The inference is never a silent guess: NormaliseExtractedTable must
     * surface a warning naming what happened, so a teacher reviewing the
     * import sees it was inferred rather than discovering it only if
     * something later goes wrong.
     */
    public function test_the_inferred_name_column_produces_a_warning(): void
    {
        $result = $this->normalise($this->extractHtml());

        $hasInferenceWarning = false;

        foreach ($result->warnings as $warning) {
            if (str_contains($warning, 'nome do aluno') && str_contains($warning, 'nomes')) {
                $hasInferenceWarning = true;
            }
        }

        $this->assertTrue($hasInferenceWarning, 'Expected a warning about the inferred name column. Got: '.implode(' | ', $result->warnings));
    }

    /**
     * A table that DOES label its name column — CharacterisationFixture's
     * own realistic shape, with "Aluno" printed on the header — must behave
     * exactly as before this fallback existed: no inference, no warning
     * about one.
     */
    public function test_a_labelled_name_column_produces_no_inference_and_no_warning(): void
    {
        $tables = (new HtmlTableExtractor)->extract(CharacterisationFixture::toWordClipboardHtml());
        $this->assertCount(1, $tables);

        $result = $this->normalise($tables[0]);

        $columns = (new ClassifyColumns)->classify($result->grid);
        $nameColumn = collect($columns)->firstWhere('role', ColumnRole::StudentName);

        $this->assertNotNull($nameColumn);
        $this->assertFalse($nameColumn->inferredFromContent, 'The header already named the student — content inference must never run.');

        foreach ($result->warnings as $warning) {
            $this->assertStringNotContainsString('foi selecionada por conter nomes', $warning);
        }
    }

    // ------------------------------------------------------------------
    // JANELA J (fix/characterisation-import-multilevel-headers): the §39
    // round trip must be idempotent for a multi-level header. The title row
    // ("Medidas 3.º ciclo") is tagged kind='header' by the structural review
    // step alongside the two real header levels — see the class docblock at
    // the top of NormaliseExtractedTable for the production defect this
    // guards: resubmitting an UNMODIFIED structural table used to join the
    // title into every column's name, turn column 0 into a "Measures" match,
    // and drop every row as a footer.
    // ------------------------------------------------------------------

    /**
     * THE KEY TEST: normalise the raw fixture, resubmit the resulting
     * structural rows exactly as the dialog does, normalise again — the
     * second pass must produce the SAME headers and the SAME data rows as
     * the first.
     */
    public function test_the_multilevel_header_round_trip_is_idempotent(): void
    {
        $firstPass = $this->normalise($this->extractHtml());

        $resubmitted = $this->resubmitAsExtractedTable($firstPass);
        $secondPass = $this->normalise($resubmitted);

        $this->assertSame($firstPass->grid->headers, $secondPass->grid->headers);
        $this->assertSame($firstPass->grid->rows, $secondPass->grid->rows);
        $this->assertSame(0, count($secondPass->structuralRows) - count($firstPass->structuralRows));
        $this->assertNotEmpty($secondPass->grid->rows, 'The round trip must not turn every row into a footer.');
    }

    /**
     * The title row survives the round trip as a header row the teacher can
     * still see — it is excluded from the JOIN, not deleted or re-classified.
     */
    public function test_the_title_row_survives_the_round_trip_as_a_header_row(): void
    {
        $firstPass = $this->normalise($this->extractHtml());
        $secondPass = $this->normalise($this->resubmitAsExtractedTable($firstPass));

        $titleRows = array_filter(
            $secondPass->structuralRows,
            fn (array $row) => in_array(MultilevelHeaderFixture::titleRow(), $row['cells'], true),
        );

        $this->assertNotEmpty($titleRows);

        foreach ($titleRows as $row) {
            $this->assertSame('header', $row['kind']);
        }
    }

    /**
     * The inferred name column (§J, column 0) must survive the round trip:
     * students still come through and footer_row_count stays 0 on the second
     * pass, not just the first.
     */
    public function test_the_inferred_name_column_survives_the_round_trip(): void
    {
        $firstPass = $this->normalise($this->extractHtml());
        $secondPass = $this->normalise($this->resubmitAsExtractedTable($firstPass));

        $names = array_map(fn ($row) => $secondPass->grid->cell($row, 0), $secondPass->grid->rows);

        $this->assertSame($this->expectedNames(), $names);

        $dataKinds = array_filter(
            array_column($secondPass->structuralRows, 'kind'),
            fn (string $kind) => $kind === 'data',
        );
        $this->assertCount(count($this->expectedNames()), $dataKinds, 'No data row should have been reclassified as a footer.');
    }

    /**
     * §39's core guarantee, still holding for this shape: a teacher's
     * explicit reclassification of a row sticks across the round trip. Here
     * she marks the second group caption ("Alunos sem RTP") as Data instead
     * of Group — an unusual but legitimate correction — and it must still
     * read as Data after a second round trip, never silently reverted.
     */
    public function test_a_teachers_explicit_reclassification_still_sticks_across_the_round_trip(): void
    {
        $firstPass = $this->normalise($this->extractHtml());

        $structuralRows = $firstPass->structuralRows;

        foreach ($structuralRows as &$row) {
            if (in_array(MultilevelHeaderFixture::groupCaptionWithoutRtp(), $row['cells'], true)) {
                $row['kind'] = 'data';
            }
        }
        unset($row);

        $rows = [];

        foreach ($structuralRows as $row) {
            $cells = [];

            foreach ($row['cells'] as $column => $text) {
                $cells[] = new ExtractedCell($text, $row['number'], $column + 1);
            }

            $rows[] = new ExtractedRow(
                $row['number'],
                $cells,
                ExtractedRowKind::from($row['kind']),
            );
        }

        $corrected = new ExtractedTable($rows, ExtractedTableSource::CorrectedPastedHtml);
        $secondPass = $this->normalise($corrected);

        $names = array_map(fn ($row) => $secondPass->grid->cell($row, 0), $secondPass->grid->rows);

        $this->assertContains(MultilevelHeaderFixture::groupCaptionWithoutRtp(), $names, 'The teacher\'s explicit Data reclassification must stick.');
    }
}
