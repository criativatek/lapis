<?php

namespace App\Services\Characterisation\Import;

use App\Domain\Import\Tabular\TabularCell;
use App\Domain\Import\Tabular\TabularSheet;
use App\Services\Characterisation\Import\Extraction\DocxTableExtractor;
use App\Services\Characterisation\Import\Extraction\ExtractedTable;
use App\Services\Characterisation\Import\Extraction\HtmlTableExtractor;
use App\Services\Characterisation\Import\Extraction\NormaliseExtractedTable;
use App\Services\Import\Tabular\CsvTabularReader;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use App\Services\Import\Tabular\XlsxTabularReader;
use Illuminate\Http\UploadedFile;

/**
 * Turns whatever a teacher hands us into one TableGrid.
 *
 * Pasted text, pasted HTML (from Word/Excel/Google Sheets clipboards), CSV,
 * XLSX and .docx tables — a live image paste and an OCR'd upload would need a
 * recognition subsystem rather than a parser, and the architecture does not
 * need those to exist yet in order to be ready for them: everything downstream
 * reads a TableGrid, so a future source arrives by producing an ExtractedTable
 * and calling fromExtractedTable(), exactly like every source above already
 * does.
 *
 * FILES ARE READ BY THE READERS THAT ALREADY EXIST. CsvTabularReader and
 * XlsxTabularReader were written for the correction-grid importer and they
 * already refuse zip bombs (SpreadsheetZipSafety), verify the encoding instead
 * of guessing it, measure the delimiter rather than assuming a comma, and
 * decline to trust a formula's cached value. Calling PhpSpreadsheet directly
 * from here would mean a second, weaker door into the same building.
 *
 * Nothing in this class writes to disk. The uploaded file is read inside the
 * request that carried it and then forgotten: no staging folder to prune, and
 * no second copy of thirty children's names sitting in storage waiting for
 * someone to remember it.
 */
class ReadCharacterisationTable
{
    /**
     * Rows beyond this are refused rather than truncated. A 5000-row paste into
     * a class of thirty is a mistake, and silently reading the first few hundred
     * would hide it behind a preview that looks fine.
     */
    private const MAX_ROWS = 500;

    private const MAX_COLUMNS = 40;

    public function __construct(
        private readonly CsvTabularReader $csv = new CsvTabularReader,
        private readonly XlsxTabularReader $xlsx = new XlsxTabularReader,
        private readonly HtmlTableExtractor $html = new HtmlTableExtractor,
        private readonly DocxTableExtractor $docx = new DocxTableExtractor,
        private readonly NormaliseExtractedTable $normaliser = new NormaliseExtractedTable,
    ) {}

    /**
     * Pasted text is parsed here rather than handed to CsvTabularReader,
     * because the two questions that reader exists to answer safely — what
     * encoding is this, and is this file a zip bomb — cannot arise. The text
     * arrived as a validated UTF-8 string in a request body, so writing it to a
     * temporary file just to read it back would add a file to delete and answer
     * nothing.
     */
    public function fromPastedText(string $text): TableGrid
    {
        $lines = preg_split('/\r\n|\r|\n/u', trim($text)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line) => trim($line) !== ''));

        if ($lines === []) {
            throw new UnreadableSpreadsheet(__('Não foi possível ler nenhuma linha do texto colado. Copie a tabela incluindo a linha dos títulos.'));
        }

        $delimiter = (new SniffDelimiter)->sniff($lines);

        $matrix = array_map(
            // str_getcsv yields null for an unquoted empty trailing field, and
            // trim() would refuse it — an empty cell is '' here, not absent.
            fn (string $line) => array_map(
                fn (?string $cell) => trim((string) $cell),
                str_getcsv($line, $delimiter, '"', '\\'),
            ),
            $lines,
        );

        return $this->toGrid($matrix);
    }

    public function fromUploadedFile(UploadedFile $file): TableGrid
    {
        $path = $file->getRealPath();
        $name = $file->getClientOriginalName();

        $reader = match (true) {
            $this->xlsx->supports($path, $name) => $this->xlsx,
            $this->csv->supports($path, $name) => $this->csv,
            default => throw new UnreadableSpreadsheet(
                __('Só é possível importar ficheiros CSV ou Excel (.xlsx). Guarde a folha num destes formatos e tente de novo.'),
            ),
        };

        $sheet = $reader->read($path)->onlyOccupiedSheet();

        if ($sheet === null) {
            throw new UnreadableSpreadsheet(__('O ficheiro tem mais do que uma folha e nenhuma delas é claramente a tabela. Guarde só a folha que quer importar.'));
        }

        return $this->toGrid($this->matrixFrom($sheet));
    }

    /**
     * @return list<list<string>>
     */
    private function matrixFrom(TabularSheet $sheet): array
    {
        $matrix = [];

        foreach ($sheet->occupiedRows() as $rowNumber) {
            $matrix[] = array_map(
                // A formula's text is kept as text. This importer never reads a
                // cell as a number, so an unevaluated formula is simply a string
                // nobody recognises — which is the preview's job to show, not
                // this reader's to resolve.
                fn (TabularCell $cell) => trim((string) ($cell->text ?? '')),
                array_slice($sheet->row($rowNumber), 0, self::MAX_COLUMNS),
            );
        }

        return $matrix;
    }

    /**
     * A clipboard HTML fragment — Word, Excel and Google Sheets all put a real
     * `<table>` on the clipboard alongside their plain text, and reading it
     * instead of the plain text is strictly more information for free: a
     * merged cell, a multiline cell, a multi-level header are all visible in
     * the markup and already lost in the tab-separated text next to it.
     *
     * Routed through HtmlTableExtractor + NormaliseExtractedTable rather than
     * parsed here, because turning merged cells into a rectangle and turning
     * rows into Header/Group/Legend/Data is exactly what fromExtractedTable's
     * pipeline already does — a second copy of that classification, tuned
     * only for this entry point, is exactly the kind of drift this feature's
     * architecture (everything downstream reads one shape) exists to avoid.
     */
    public function fromPastedHtml(string $html): TableGrid
    {
        $tables = $this->html->extract($html);

        if ($tables === []) {
            throw new UnreadableSpreadsheet(__('Não foi possível reconhecer nenhuma tabela no conteúdo colado.'));
        }

        return $this->fromExtractedTable($tables[0]);
    }

    /**
     * The shared landing point for every ExtractedTable, whatever produced
     * it — pasted HTML, a spreadsheet, one table out of several read from a
     * .docx. NormaliseExtractedTable is what actually expands merges and
     * classifies rows; this method exists so callers reach it through the
     * same class that already enforces MAX_ROWS/MAX_COLUMNS elsewhere.
     */
    public function fromExtractedTable(ExtractedTable $table): TableGrid
    {
        return $this->normaliser->normalise($table);
    }

    /**
     * A .docx can hold several tables — a class characterisation and a
     * legend table, say — so this returns all of them rather than guessing
     * which one the teacher meant. The controller decides what to do with
     * more than one; this method's job stops at reading them.
     *
     * @return list<ExtractedTable>
     */
    public function tablesFromUploadedFile(UploadedFile $file): array
    {
        return $this->docx->extract($file);
    }

    /**
     * @param  list<list<string>>  $matrix
     */
    private function toGrid(array $matrix): TableGrid
    {
        $matrix = array_values(array_filter(
            $matrix,
            fn (array $row): bool => trim(implode('', $row)) !== '',
        ));

        if ($matrix === []) {
            throw new UnreadableSpreadsheet(__('A tabela não tem linhas com conteúdo.'));
        }

        $headerIndex = (new FindHeaderRow)->find($matrix);
        $headers = array_map('strval', $matrix[$headerIndex]);
        $rows = array_slice($matrix, $headerIndex + 1);

        if (count($headers) > self::MAX_COLUMNS) {
            throw new UnreadableSpreadsheet(__('A tabela tem colunas a mais para ser lida com segurança.'));
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new UnreadableSpreadsheet(__('A tabela tem :count linhas — mais do que esta importação aceita de uma vez. Importe uma turma de cada vez.', [
                'count' => count($rows),
            ]));
        }

        return new TableGrid($headers, array_map(
            fn (array $row) => array_map('strval', array_slice($row, 0, count($headers))),
            $rows,
        ));
    }
}
