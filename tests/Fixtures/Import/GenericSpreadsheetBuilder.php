<?php

namespace Tests\Fixtures\Import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Builds the teacher's own spreadsheet, in either container, from nothing.
 *
 * The generic importer's whole premise is that it has never seen the file
 * before, so the fixtures cannot be «a real export with the names changed» —
 * they have to be arbitrary shapes, built to order, including the awkward ones:
 * duplicate headings, a title row above the table, a decimal comma, a column of
 * formulas, three sheets where only one has anything in it.
 *
 * Every person in here is invented. Ana Exemplo, Bruno Teste, Carla Fictícia and
 * Diogo Inventado have no originals.
 *
 * The same file can be written as CSV or as XLSX from one description, which is
 * what lets a test assert that both arrive at the same grid — the claim the
 * tabular abstraction exists to make (§40).
 */
class GenericSpreadsheetBuilder
{
    /** @var array<string, list<list<mixed>>> sheet name => rows */
    protected array $sheets = ['Folha 1' => []];

    protected string $current = 'Folha 1';

    protected string $delimiter = ';';

    protected bool $byteOrderMark = false;

    protected ?string $encoding = null;

    /** @var array<string, true> column letters formatted as percentages */
    protected array $percentageColumns = [];

    /** @var array<string, string> coordinate => formula */
    protected array $formulas = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * The ordinary case: names down column A, one mark column.
     */
    public static function overall(): self
    {
        return self::make()->rows([
            ['Nome', 'Nota'],
            ['Ana Exemplo', 78],
            ['Bruno Teste', 64],
            ['Carla Fictícia', 91],
            ['Diogo Inventado', 55],
        ]);
    }

    /**
     * Several columns, each of which the teacher may point at a domain. The
     * headings NAME domains on purpose — they must still not become domains (§18).
     */
    public static function multiDomain(): self
    {
        return self::make()->rows([
            ['Nome', 'Leitura', 'Gramática', 'Escrita'],
            ['Ana Exemplo', 14, 12, 18],
            ['Bruno Teste', 16, 10, 17],
            ['Carla Fictícia', 11, 15, 13],
            ['Diogo Inventado', 8, 9, 12],
        ]);
    }

    /**
     * Question by question, with two columns deliberately sharing a heading.
     */
    public static function perItem(): self
    {
        return self::make()->rows([
            ['Nome', 'Item 1', 'Item 1', 'Q3', 'Q4'],
            ['Ana Exemplo', 2, 3, 4, 1],
            ['Bruno Teste', 1, 3, 2, 4],
            ['Carla Fictícia', 2, 2, 3, 3],
        ]);
    }

    /**
     * A zero and a blank in the same column, which is the distinction the whole
     * import exists to preserve (§29).
     */
    public static function blankAgainstZero(): self
    {
        return self::make()->rows([
            ['Nome', 'Leitura', 'Escrita'],
            ['Ana Exemplo', 0, 12],
            ['Bruno Teste', null, 14],
            ['Carla Fictícia', 8, null],
        ]);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    public function rows(array $rows): self
    {
        $this->sheets[$this->current] = $rows;

        return $this;
    }

    /**
     * @param  list<mixed>  $row
     */
    public function row(array $row): self
    {
        $this->sheets[$this->current][] = $row;

        return $this;
    }

    public function sheet(string $name): self
    {
        $this->current = $name;
        $this->sheets[$name] ??= [];

        return $this;
    }

    /** Renames the sheet the builder starts with, without adding another. */
    public function onlySheetNamed(string $name): self
    {
        $rows = $this->sheets[$this->current];
        unset($this->sheets[$this->current]);

        $this->sheets[$name] = $rows;
        $this->current = $name;

        return $this;
    }

    public function separatedBy(string $delimiter): self
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    public function withByteOrderMark(): self
    {
        $this->byteOrderMark = true;

        return $this;
    }

    /** Writes the CSV in something other than UTF-8, to prove it is refused. */
    public function encodedAs(string $encoding): self
    {
        $this->encoding = $encoding;

        return $this;
    }

    /** Formats a whole column as a percentage, the way Excel stores 0,75 as 75%. */
    public function percentageColumn(string $letter): self
    {
        $this->percentageColumns[strtoupper($letter)] = true;

        return $this;
    }

    public function formulaAt(string $coordinate, string $formula): self
    {
        $this->formulas[strtoupper($coordinate)] = $formula;

        return $this;
    }

    public function writeCsv(string $path): string
    {
        $lines = [];

        foreach ($this->sheets[$this->current] as $row) {
            $fields = [];

            foreach ($row as $value) {
                $fields[] = $this->csvField($value);
            }

            $lines[] = implode($this->delimiter, $fields);
        }

        $contents = implode("\r\n", $lines)."\r\n";

        if ($this->encoding !== null) {
            $converted = @iconv('UTF-8', $this->encoding.'//TRANSLIT', $contents);
            $contents = $converted === false ? $contents : $converted;
        }

        file_put_contents($path, ($this->byteOrderMark ? "\xEF\xBB\xBF" : '').$contents);

        return $path;
    }

    public function writeXlsx(string $path): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($this->sheets as $name => $rows) {
            $worksheet = $spreadsheet->createSheet();
            $worksheet->setTitle($name);

            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $columnIndex => $value) {
                    if ($value === null) {
                        continue;
                    }

                    $worksheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
                }
            }

            foreach (array_keys($this->percentageColumns) as $letter) {
                $worksheet->getStyle($letter.':'.$letter)
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
            }

            foreach ($this->formulas as $coordinate => $formula) {
                $worksheet->setCellValue($coordinate, $formula);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new XlsxWriter($spreadsheet))->save($path);

        // Cells reference the sheet which references the workbook. Left
        // connected, a suite that builds forty of these meets the memory limit.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $path;
    }

    protected function csvField(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        $needsQuoting = str_contains($text, $this->delimiter)
            || str_contains($text, '"')
            || str_contains($text, "\n");

        return $needsQuoting ? '"'.str_replace('"', '""', $text).'"' : $text;
    }
}
