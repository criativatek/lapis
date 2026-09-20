<?php

namespace App\Services\Characterisation\Import\Extraction;

use App\Domain\Import\Tabular\TabularColumn;
use App\Domain\Import\Tabular\TabularSheet;
use App\Services\Import\Tabular\CsvTabularReader;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use App\Services\Import\Tabular\XlsxTabularReader;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Throwable;

/**
 * Wraps the EXISTING CsvTabularReader/XlsxTabularReader rather than calling
 * PhpSpreadsheet directly for the CELL VALUES. Those readers already refuse
 * zip bombs (SpreadsheetZipSafety), verify the encoding instead of guessing
 * it, measure the delimiter rather than assuming a comma, and decline to
 * trust a formula's cached value. A second door into the same building,
 * opened straight onto PhpSpreadsheet from here for the values, would have
 * none of that.
 *
 * MERGE RANGES ARE THE ONE THING READ SEPARATELY, AND ON PURPOSE.
 * `TabularSheet` was built for the correction-grid importer, which reads
 * grades cell by cell and has never needed to know a cell was part of a
 * merge — its whole contract is "one cell, one value, addressed by row and
 * column". Adding a merge concept to that contract would change what EVERY
 * existing caller of TabularSheet has to reason about, for a fact only this
 * one caller needs. So merges are read here instead, through a second,
 * narrow load of the same already-validated file — `setReadDataOnly(true)`
 * and restricted to the one sheet already chosen by `onlyOccupiedSheet()`,
 * so the second pass costs little and reads nothing this class does not
 * already trust (SpreadsheetZipSafety was already applied by
 * XlsxTabularReader::read() above). If reading the merges fails for any
 * reason, extraction degrades to "no merges known" rather than failing the
 * whole import — the cell VALUES, which is what a teacher's data actually
 * is, were already read successfully by the trusted reader.
 */
class SpreadsheetTableExtractor implements TableExtractor
{
    public function __construct(
        private readonly CsvTabularReader $csv = new CsvTabularReader,
        private readonly XlsxTabularReader $xlsx = new XlsxTabularReader,
    ) {}

    public function supports(mixed $source): bool
    {
        if (! $source instanceof UploadedFile) {
            return false;
        }

        $path = $source->getRealPath();
        $name = $source->getClientOriginalName();

        if ($path === false) {
            return false;
        }

        return $this->xlsx->supports($path, $name) || $this->csv->supports($path, $name);
    }

    /**
     * @return list<ExtractedTable>
     */
    public function extract(mixed $source): array
    {
        if (! $source instanceof UploadedFile) {
            return [];
        }

        $path = $source->getRealPath();
        $name = $source->getClientOriginalName();

        if ($path === false) {
            throw new UnreadableSpreadsheet(__('Não foi possível ler o ficheiro enviado.'));
        }

        $isXlsx = $this->xlsx->supports($path, $name);
        $reader = $isXlsx ? $this->xlsx : $this->csv;

        $sheet = $reader->read($path)->onlyOccupiedSheet();

        if ($sheet === null) {
            throw new UnreadableSpreadsheet(__('O ficheiro tem mais do que uma folha e nenhuma delas é claramente a tabela. Guarde só a folha que quer importar.'));
        }

        // CSV has no concept of a merged cell — this stays empty for it, and
        // rowsFrom() below produces exactly the one-cell-per-column shape it
        // always has.
        $merges = $isXlsx ? $this->mergeRanges($path, $sheet->name) : [];

        return [new ExtractedTable(
            rows: $this->rowsFrom($sheet, $merges),
            sourceType: $isXlsx ? ExtractedTableSource::Xlsx : ExtractedTableSource::Csv,
            sourceFilename: $name,
        )];
    }

    /**
     * @param  list<array{startRow: int, startCol: int, endRow: int, endCol: int}>  $merges
     * @return list<ExtractedRow>
     */
    private function rowsFrom(TabularSheet $sheet, array $merges): array
    {
        $rows = [];

        foreach ($sheet->occupiedRows() as $rowNumber) {
            $cells = [];

            foreach ($sheet->row($rowNumber) as $columnIndex => $cell) {
                $column = $columnIndex + 1;
                $merge = $this->mergeCovering($merges, $rowNumber, $column);

                if ($merge !== null && ($rowNumber !== $merge['startRow'] || $column !== $merge['startCol'])) {
                    // A cell PhpSpreadsheet leaves empty because a merge
                    // covers it but does not anchor it here — skipped rather
                    // than turned into a blank ExtractedCell, exactly as
                    // HtmlTableExtractor and DocxTableExtractor skip the
                    // cells a colspan/rowspan already accounts for.
                    // NormaliseExtractedTable::expand() repeats the anchor's
                    // text back into this position from the anchor's colspan/
                    // rowspan below.
                    continue;
                }

                $colspan = $merge !== null ? $merge['endCol'] - $merge['startCol'] + 1 : 1;
                $rowspan = $merge !== null ? $merge['endRow'] - $merge['startRow'] + 1 : 1;

                $cells[] = new ExtractedCell(
                    // A formula's text is kept as text: this importer never
                    // reads a cell as a number, so an unevaluated formula is
                    // simply a string nobody recognises — which is the
                    // preview's job to show, not this reader's to resolve.
                    text: trim((string) ($cell->text ?? '')),
                    row: $rowNumber,
                    column: $column,
                    colspan: $colspan,
                    rowspan: $rowspan,
                );
            }

            $rows[] = new ExtractedRow($rowNumber, $cells);
        }

        return $rows;
    }

    /**
     * @param  list<array{startRow: int, startCol: int, endRow: int, endCol: int}>  $merges
     * @return array{startRow: int, startCol: int, endRow: int, endCol: int}|null
     */
    private function mergeCovering(array $merges, int $row, int $column): ?array
    {
        foreach ($merges as $merge) {
            if ($row >= $merge['startRow'] && $row <= $merge['endRow']
                && $column >= $merge['startCol'] && $column <= $merge['endCol']) {
                return $merge;
            }
        }

        return null;
    }

    /**
     * The workbook's merge ranges for one sheet, read through a second,
     * narrow PhpSpreadsheet load of the SAME already-validated file — see
     * the class docblock for why this does not go through TabularSheet.
     * `setLoadSheetsOnly()` loads only the one sheet already chosen, so this
     * costs a fraction of the first, full read. `setReadDataOnly(true)` was
     * tried here first and deliberately dropped: in the PhpSpreadsheet
     * version this project pins, data-only mode discards merge-range
     * information along with the styling it is meant to skip, which is
     * exactly the one thing this second pass exists to read.
     *
     * @return list<array{startRow: int, startCol: int, endRow: int, endCol: int}>
     */
    private function mergeRanges(string $path, string $sheetName): array
    {
        try {
            $reader = new XlsxReader;
            $reader->setLoadSheetsOnly([$sheetName]);
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            // Degrades to "no merges known" rather than failing the whole
            // import — the cell values were already read successfully by
            // the trusted reader above, and that is the data that matters.
            return [];
        }

        try {
            $worksheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();
            $ranges = [];

            foreach ($worksheet->getMergeCells() as $range) {
                $parsed = $this->parseRange((string) $range);

                if ($parsed !== null) {
                    $ranges[] = $parsed;
                }
            }

            return $ranges;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * "G1:J1" -> startRow/startCol/endRow/endCol, all 1-based — the same
     * convention TabularSheet and ExtractedCell already use, deliberately
     * read without PhpSpreadsheet's own Coordinate class: TabularColumn
     * exists precisely so the tabular layer does not depend on a workbook
     * library to name a column, and that reasoning applies just as much to
     * parsing a range string as it does to a single column letter.
     *
     * @return array{startRow: int, startCol: int, endRow: int, endCol: int}|null
     */
    private function parseRange(string $range): ?array
    {
        if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $matches) !== 1) {
            return null;
        }

        return [
            'startRow' => (int) $matches[2],
            'startCol' => TabularColumn::index($matches[1]),
            'endRow' => (int) $matches[4],
            'endCol' => TabularColumn::index($matches[3]),
        ];
    }
}
