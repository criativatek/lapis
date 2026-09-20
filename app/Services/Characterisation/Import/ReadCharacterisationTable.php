<?php

namespace App\Services\Characterisation\Import;

use App\Domain\Import\Tabular\TabularCell;
use App\Domain\Import\Tabular\TabularSheet;
use App\Services\Import\Tabular\CsvTabularReader;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use App\Services\Import\Tabular\XlsxTabularReader;
use Illuminate\Http\UploadedFile;

/**
 * Turns the three things a teacher can hand us into one TableGrid.
 *
 * Pasted text, CSV and XLSX — and nothing else. PDF and images would need OCR,
 * which is a subsystem rather than a parser, and the architecture does not need
 * it in order to be ready for it: everything downstream reads a TableGrid, so a
 * future source arrives by producing one.
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

        $delimiter = $this->sniffDelimiter($lines);

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

        $headerIndex = $this->findHeaderRow($matrix);
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

    /**
     * School exports put a printed report above the data — school name, year, a
     * title — so the header is rarely row 1. Find it by content, the way the
     * roster importer finds «N.º MATR.» / «NOME», rather than trusting position.
     *
     * A candidate row must name the student somehow: a line of prose that
     * happens to contain the word «notas» is not a header, and treating it as
     * one would shift every student's text up by a row.
     *
     * @param  list<list<string>>  $matrix
     */
    private function findHeaderRow(array $matrix): int
    {
        $classifier = new ClassifyColumns;
        $bestIndex = 0;
        $bestScore = -1;

        foreach (array_slice($matrix, 0, 15) as $index => $row) {
            $columns = $classifier->classify(new TableGrid(array_map('strval', $row), []));

            $namesStudent = array_filter(
                $columns,
                fn (ClassifiedColumn $column) => $column->role->isIdentifying(),
            );

            if ($namesStudent === []) {
                continue;
            }

            $score = count(array_filter(
                $columns,
                fn (ClassifiedColumn $column) => $column->role !== ColumnRole::Unknown,
            ));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestScore < 0 ? 0 : $bestIndex;
    }

    /**
     * Measured, not preferred: the delimiter that yields the same field count on
     * the most lines wins. A Portuguese export separated by semicolons is at
     * least as common as a comma-separated one, and a name like «Silva, Ana»
     * makes the naive choice actively wrong.
     *
     * @param  list<string>  $lines
     */
    private function sniffDelimiter(array $lines): string
    {
        $sample = array_slice($lines, 0, 10);
        $best = "\t";
        $bestScore = 0;

        foreach (["\t", ';', ',', '|'] as $candidate) {
            $counts = array_map(
                fn (string $line) => count(str_getcsv($line, $candidate, '"', '\\')),
                $sample,
            );

            if ($counts === []) {
                continue;
            }

            $fields = max($counts);

            if ($fields < 2) {
                continue;
            }

            // Consistency is what identifies a delimiter; a character that
            // splits one line into nine fields and the next into two is
            // punctuation, not structure.
            $consistent = count(array_filter($counts, fn (int $count) => $count === $fields));
            $score = $consistent * 100 + $fields;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }
}
