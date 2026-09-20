<?php

namespace App\Services\Characterisation\Import\Extraction;

use App\Domain\Import\Tabular\TabularSheet;
use App\Services\Import\Tabular\CsvTabularReader;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use App\Services\Import\Tabular\XlsxTabularReader;
use Illuminate\Http\UploadedFile;

/**
 * Wraps the EXISTING CsvTabularReader/XlsxTabularReader rather than calling
 * PhpSpreadsheet directly. Those readers already refuse zip bombs
 * (SpreadsheetZipSafety), verify the encoding instead of guessing it,
 * measure the delimiter rather than assuming a comma, and decline to trust a
 * formula's cached value. A second door into the same building, opened
 * straight onto PhpSpreadsheet from here, would have none of that.
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

        return [new ExtractedTable(
            rows: $this->rowsFrom($sheet),
            sourceType: $isXlsx ? ExtractedTableSource::Xlsx : ExtractedTableSource::Csv,
            sourceFilename: $name,
        )];
    }

    /**
     * @return list<ExtractedRow>
     */
    private function rowsFrom(TabularSheet $sheet): array
    {
        $rows = [];

        foreach ($sheet->occupiedRows() as $rowNumber) {
            $cells = [];

            foreach ($sheet->row($rowNumber) as $columnIndex => $cell) {
                $cells[] = new ExtractedCell(
                    // A formula's text is kept as text: this importer never
                    // reads a cell as a number, so an unevaluated formula is
                    // simply a string nobody recognises — which is the
                    // preview's job to show, not this reader's to resolve.
                    text: trim((string) ($cell->text ?? '')),
                    row: $rowNumber,
                    column: $columnIndex + 1,
                );
            }

            $rows[] = new ExtractedRow($rowNumber, $cells);
        }

        return $rows;
    }
}
