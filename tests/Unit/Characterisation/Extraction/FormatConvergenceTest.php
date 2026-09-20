<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\ReadCharacterisationTable;
use App\Services\Characterisation\Import\TableGrid;
use Illuminate\Http\UploadedFile;
use Tests\Fixtures\Characterisation\CharacterisationFixture;
use Tests\TestCase;

/**
 * ONE dataset (CharacterisationFixture), fed through every entry point
 * ReadCharacterisationTable exposes — the SAME public API
 * CharacterisationImportController actually calls — proving they all
 * converge on an equivalent TableGrid — same students, same order, group
 * captions and the legend dropped everywhere, the quiet "name only" student
 * kept everywhere, a multiline observation surviving as one cell with an
 * embedded newline everywhere it can be carried at all.
 *
 * THIS USED TO CALL THE EXTRACTOR CLASSES DIRECTLY (HtmlTableExtractor,
 * SpreadsheetTableExtractor, …) plus a raw NormaliseExtractedTable, which is
 * exactly the shape ReadCharacterisationTable::fromPastedText()/
 * fromUploadedFile() had BEFORE this fix — a private toGrid() that never
 * touched an extractor or the normaliser at all. A test built the same way
 * would have passed even while production's CSV/plain-text paths silently
 * skipped every warning and drop this class exists to produce, because the
 * test was never exercising them. Going through ReadCharacterisationTable's
 * own methods — fromPastedText(), fromUploadedFile(), fromPastedHtml() — is
 * what makes this test actually prove the wiring the controller depends on,
 * not just the extractors in isolation.
 *
 * WARNINGS ARE ASSERTED FOR EVERY FORMAT TOO — see the loop below — because
 * the whole point of routing every source through the same pipeline is that
 * a caption or legend row produces the SAME warning whether it arrived as a
 * CSV upload, a plain-text paste, or Word clipboard HTML.
 *
 * Image fixtures are OUT OF SCOPE (OCR is a later slice) — see the note atop
 * CharacterisationFixture for the documented seam it would slot into.
 */
class FormatConvergenceTest extends TestCase
{
    public function test_all_formats_converge(): void
    {
        $reader = new ReadCharacterisationTable;

        // lastWarnings() reflects only the MOST RECENT call, so each
        // format's warnings are captured right after the grid that produced
        // them — not read back afterwards, once a later format may already
        // have overwritten them.
        $grids = [];
        $warningsByFormat = [];

        $capture = function (string $label, TableGrid $grid) use ($reader, &$grids, &$warningsByFormat): void {
            $grids[$label] = $grid;
            $warningsByFormat[$label] = $reader->lastWarnings();
        };

        $capture('TSV', $this->gridFromTsv($reader));
        $capture('CSV', $this->gridFromCsv($reader));
        $capture('Word clipboard HTML', $this->gridFromHtml($reader, CharacterisationFixture::toWordClipboardHtml()));
        $capture('Excel clipboard HTML', $this->gridFromHtml($reader, CharacterisationFixture::toExcelClipboardHtml()));
        $capture('Google Sheets clipboard HTML', $this->gridFromHtml($reader, CharacterisationFixture::toGoogleSheetsClipboardHtml()));
        $capture('docx', $this->gridFromDocx($reader));
        $capture('xlsx', $this->gridFromXlsx($reader));

        // Every format dropped a group caption and a legend row — the
        // dataset has both — so every format must have produced a warning
        // about it, through the exact same ReadCharacterisationTable that
        // just produced the grid.
        foreach ($warningsByFormat as $label => $warnings) {
            $this->assertNotEmpty($warnings, "{$label}: expected a warning about dropped rows.");
        }

        $expectedNames = CharacterisationFixture::studentNames();
        $expectedCount = count($expectedNames);

        foreach ($grids as $label => $grid) {
            $nameColumn = $this->findColumn($grid, 'Aluno');
            $this->assertNotNull($nameColumn, "{$label}: no «Aluno» column found.");

            // Same number of data rows, in every format — the group captions
            // and the legend must all have been dropped, nothing more and
            // nothing less.
            $this->assertCount($expectedCount, $grid->rows, "{$label}: expected exactly {$expectedCount} data rows.");

            $names = array_map(fn (array $row) => $grid->cell($row, $nameColumn), $grid->rows);
            $this->assertSame($expectedNames, $names, "{$label}: student names/order diverged.");

            // The group captions are gone: neither caption string survives as
            // a name in this list.
            $this->assertNotContains(CharacterisationFixture::groupCaptionWithRtp(), $names, "{$label}: group caption leaked into the data.");
            $this->assertNotContains(CharacterisationFixture::groupCaptionWithoutRtp(), $names, "{$label}: group caption leaked into the data.");

            // The legend is gone too: its distinctive text does not appear
            // anywhere in any row.
            foreach ($grid->rows as $row) {
                foreach ($row as $cellText) {
                    $this->assertStringNotContainsString('(continua)', $cellText, "{$label}: legend leaked into the data.");
                }
            }

            // Leonor Machado — name and nothing else — is present, in every
            // format: the row most likely to be dropped by mistake.
            $leonorIndex = array_search('Leonor Machado', $names, true);
            $this->assertNotFalse($leonorIndex, "{$label}: the name-only student was dropped.");
            $leonorRow = $grid->rows[$leonorIndex];

            foreach ($leonorRow as $column => $cellText) {
                if ($column === $nameColumn) {
                    continue;
                }

                $this->assertSame('', $grid->cell($leonorRow, $column), "{$label}: Leonor Machado's row grew a value it never had, at column {$column}.");
            }

            // Tiago Nogueira's multiline observation survives as ONE cell
            // containing a newline, in every format — TSV/CSV via a quoted
            // field, HTML via <p> (Word) or <br> (Excel/Sheets), .docx via
            // two w:p paragraphs, .xlsx via a literal embedded newline.
            $observationColumn = $this->findColumnBySubstring($grid, 'Observações');
            $this->assertNotNull($observationColumn, "{$label}: no observations column found.");

            $tiagoIndex = array_search('Tiago Nogueira', $names, true);
            $this->assertNotFalse($tiagoIndex, "{$label}: Tiago Nogueira is missing.");
            $observation = $grid->cell($grid->rows[$tiagoIndex], $observationColumn);

            $this->assertStringContainsString("\n", $observation, "{$label}: the multiline observation lost its line break.");
            $this->assertStringContainsString('Primeira linha da observação.', $observation, "{$label}: multiline observation content changed.");
            $this->assertStringContainsString('Segunda linha da observação.', $observation, "{$label}: multiline observation content changed.");

            // The MU/MS/MA columns are matched by SUBSTRING, not exact
            // equality — see the header-join note atop CharacterisationFixture.
            // The merged formats join "Medidas" + "MU" into "Medidas MU";
            // TSV/CSV carry the bare "MU". Both are correct for their format;
            // neither is a bug. Only the header for a merged format's "Apoio"
            // columns is asserted for EXACT equality below, because that join
            // is deliberately symmetric with the flat header.
            $this->assertNotNull($this->findColumnBySubstring($grid, 'MU'), "{$label}: no MU-ish column found.");
            $this->assertNotNull($this->findColumnBySubstring($grid, 'MS'), "{$label}: no MS-ish column found.");
            $this->assertNotNull($this->findColumnBySubstring($grid, 'MA'), "{$label}: no MA-ish column found.");
        }

        // The multi-level header join: "Apoio" (top) + "Ing." (bottom) joins
        // to EXACTLY "Apoio Ing." — the same string the flat CSV/TSV header
        // already uses — in every merged format, .xlsx included.
        // SpreadsheetTableExtractor reads the worksheet's merge ranges
        // (Worksheet::getMergeCells()) the same way HtmlTableExtractor reads
        // colspan/rowspan and DocxTableExtractor reads w:gridSpan/w:vMerge,
        // so a merged "Apoio" header cell survives on every column it spans,
        // not just its anchor. This is the one header this dataset
        // guarantees converges byte-for-byte across ALL formats, merged or
        // flat, per the task's own example.
        foreach ($grids as $label => $grid) {
            $this->assertContains('Apoio Ing.', $grid->headers, "{$label}: «Apoio Ing.» header did not join correctly.");
        }
    }

    /**
     * ReadCharacterisationTable::fromPastedText() — the plain-text paste
     * entry point the controller calls when no HTML fragment is on the
     * clipboard.
     */
    private function gridFromTsv(ReadCharacterisationTable $reader): TableGrid
    {
        return $reader->fromPastedText(CharacterisationFixture::toTsv());
    }

    /**
     * ReadCharacterisationTable::fromUploadedFile() — the SAME method the
     * controller calls for a CSV/XLSX upload, whichever extension it is.
     */
    private function gridFromCsv(ReadCharacterisationTable $reader): TableGrid
    {
        $path = tempnam(sys_get_temp_dir(), 'characterisation').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".CharacterisationFixture::toCsv());

        try {
            $upload = new UploadedFile($path, 'caracterizacao.csv', null, null, true);

            return $reader->fromUploadedFile($upload);
        } finally {
            @unlink($path);
        }
    }

    /**
     * ReadCharacterisationTable::fromPastedHtml() — the controller's entry
     * point for a `pasted_html` request field.
     */
    private function gridFromHtml(ReadCharacterisationTable $reader, string $html): TableGrid
    {
        return $reader->fromPastedHtml($html);
    }

    /**
     * .docx goes through tablesFromUploadedFile() + fromExtractedTable(),
     * exactly like CharacterisationImportController::resolveDocxTable() does
     * for the single-table case (this fixture always produces exactly one
     * table).
     */
    private function gridFromDocx(ReadCharacterisationTable $reader): TableGrid
    {
        $path = tempnam(sys_get_temp_dir(), 'characterisation').'.docx';
        CharacterisationFixture::writeDocx($path);

        try {
            $upload = new UploadedFile($path, 'caracterizacao.docx', null, null, true);
            $tables = $reader->tablesFromUploadedFile($upload);
            $this->assertNotEmpty($tables);

            return $reader->fromExtractedTable($tables[0]);
        } finally {
            @unlink($path);
        }
    }

    private function gridFromXlsx(ReadCharacterisationTable $reader): TableGrid
    {
        $path = tempnam(sys_get_temp_dir(), 'characterisation').'.xlsx';
        CharacterisationFixture::writeXlsx($path);

        try {
            $upload = new UploadedFile($path, 'caracterizacao.xlsx', null, null, true);

            return $reader->fromUploadedFile($upload);
        } finally {
            @unlink($path);
        }
    }

    private function findColumn(TableGrid $grid, string $exactHeader): ?int
    {
        foreach ($grid->headers as $index => $header) {
            if (trim($header) === $exactHeader) {
                return $index;
            }
        }

        return null;
    }

    private function findColumnBySubstring(TableGrid $grid, string $needle): ?int
    {
        foreach ($grid->headers as $index => $header) {
            if (str_contains($header, $needle)) {
                return $index;
            }
        }

        return null;
    }
}
