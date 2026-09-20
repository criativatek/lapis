<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\Extraction\DocxTableExtractor;
use App\Services\Characterisation\Import\Extraction\HtmlTableExtractor;
use App\Services\Characterisation\Import\Extraction\NormaliseExtractedTable;
use App\Services\Characterisation\Import\Extraction\SpreadsheetTableExtractor;
use App\Services\Characterisation\Import\Extraction\TsvTableExtractor;
use App\Services\Characterisation\Import\TableGrid;
use Illuminate\Http\UploadedFile;
use Tests\Fixtures\Characterisation\CharacterisationFixture;
use Tests\TestCase;

/**
 * ONE dataset (CharacterisationFixture), fed through every extraction path
 * the import feature accepts, proving they all converge on an equivalent
 * TableGrid — same students, same order, group captions and the legend
 * dropped everywhere, the quiet "name only" student kept everywhere, a
 * multiline observation surviving as one cell with an embedded newline
 * everywhere it can be carried at all.
 *
 * Image fixtures are OUT OF SCOPE (OCR is a later slice) — see the note atop
 * CharacterisationFixture for the documented seam it would slot into.
 */
class FormatConvergenceTest extends TestCase
{
    public function test_all_formats_converge(): void
    {
        $normaliser = new NormaliseExtractedTable;

        $grids = [
            'TSV' => $this->gridFromTsv($normaliser),
            'CSV' => $this->gridFromCsv($normaliser),
            'Word clipboard HTML' => $this->gridFromHtml($normaliser, CharacterisationFixture::toWordClipboardHtml()),
            'Excel clipboard HTML' => $this->gridFromHtml($normaliser, CharacterisationFixture::toExcelClipboardHtml()),
            'Google Sheets clipboard HTML' => $this->gridFromHtml($normaliser, CharacterisationFixture::toGoogleSheetsClipboardHtml()),
            'docx' => $this->gridFromDocx($normaliser),
            'xlsx' => $this->gridFromXlsx($normaliser),
        ];

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

    private function gridFromTsv(NormaliseExtractedTable $normaliser): TableGrid
    {
        $extractor = new TsvTableExtractor;
        $tables = $extractor->extract(CharacterisationFixture::toTsv());
        $this->assertCount(1, $tables);

        return $normaliser->normalise($tables[0]);
    }

    private function gridFromCsv(NormaliseExtractedTable $normaliser): TableGrid
    {
        $path = tempnam(sys_get_temp_dir(), 'characterisation').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".CharacterisationFixture::toCsv());

        try {
            $extractor = new SpreadsheetTableExtractor;
            $upload = new UploadedFile($path, 'caracterizacao.csv', null, null, true);
            $tables = $extractor->extract($upload);
            $this->assertCount(1, $tables);

            return $normaliser->normalise($tables[0]);
        } finally {
            @unlink($path);
        }
    }

    private function gridFromHtml(NormaliseExtractedTable $normaliser, string $html): TableGrid
    {
        $extractor = new HtmlTableExtractor;
        $tables = $extractor->extract($html);
        $this->assertCount(1, $tables);

        return $normaliser->normalise($tables[0]);
    }

    private function gridFromDocx(NormaliseExtractedTable $normaliser): TableGrid
    {
        $path = tempnam(sys_get_temp_dir(), 'characterisation').'.docx';
        CharacterisationFixture::writeDocx($path);

        try {
            $extractor = new DocxTableExtractor;
            $upload = new UploadedFile($path, 'caracterizacao.docx', null, null, true);
            $tables = $extractor->extract($upload);
            $this->assertNotEmpty($tables);

            return $normaliser->normalise($tables[0]);
        } finally {
            @unlink($path);
        }
    }

    private function gridFromXlsx(NormaliseExtractedTable $normaliser): TableGrid
    {
        $path = tempnam(sys_get_temp_dir(), 'characterisation').'.xlsx';
        CharacterisationFixture::writeXlsx($path);

        try {
            $extractor = new SpreadsheetTableExtractor;
            $upload = new UploadedFile($path, 'caracterizacao.xlsx', null, null, true);
            $tables = $extractor->extract($upload);
            $this->assertCount(1, $tables);

            return $normaliser->normalise($tables[0]);
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
