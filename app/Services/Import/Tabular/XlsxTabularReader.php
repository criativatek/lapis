<?php

namespace App\Services\Import\Tabular;

use App\Domain\Import\Correction\LapisGridContract;
use App\Domain\Import\Tabular\TabularCell;
use App\Domain\Import\Tabular\TabularColumn;
use App\Domain\Import\Tabular\TabularNumber;
use App\Domain\Import\Tabular\TabularSheet;
use App\Domain\Import\Tabular\TabularSourceSnapshot;
use App\Support\Import\SpreadsheetZipSafety;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * A workbook, read as sheets of cells, with every optional behaviour switched off.
 *
 * FORMULAS ARE NEVER EVALUATED, and the cached value is not used either. That
 * second half is a deliberate decision rather than an oversight: PhpSpreadsheet
 * can hand back `getOldCalculatedValue()` without computing anything, so reading
 * it would be SAFE — but it would not be TRUE. That number is whatever the last
 * application to save the file happened to compute, there is no way to tell from
 * the file whether it is still current, and a stale one would become a mark on a
 * child's record with nothing to indicate it was stale. So a formula in a column
 * the teacher chose as results is refused, with a sentence telling them to export
 * the values (§28).
 *
 * NUMBER FORMATS ARE READ, and are the only reason this does not run in
 * data-only mode. A cell holding 0.75 means three quarters or seventy-five per
 * cent depending entirely on its format, and that distinction cannot be
 * recovered afterwards (§24). The cost is bounded by the package checks and the
 * row and column ceilings below.
 *
 * NOTHING ABOUT THE DOCUMENT IS READ. Not the author, not the company, not the
 * path it was saved from. A workbook a teacher exported carries all three, and
 * none of them is needed to read marks (§13).
 */
class XlsxTabularReader implements TabularReader
{
    protected const MAX_SHEETS = 20;

    protected const MAX_ROWS = 2000;

    protected const MAX_COLUMNS = 200;

    public function __construct(protected SpreadsheetZipSafety $zipSafety = new SpreadsheetZipSafety) {}

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        // .xls is a different reader entirely and .xlsm is a macro container.
        // Neither is supported, and neither is silently attempted (§8).
        return ['xlsx'];
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ];
    }

    public function supports(string $absolutePath, string $originalFilename): bool
    {
        if (strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION)) !== 'xlsx') {
            return false;
        }

        return $this->zipSafety->workbookXml($absolutePath) !== null;
    }

    public function read(string $absolutePath): TabularSourceSnapshot
    {
        if (! $this->zipSafety->isSafe($absolutePath)) {
            throw new UnreadableSpreadsheet(__('Este ficheiro Excel não pode ser aberto em segurança. Ficheiros com macros ou ligações a outros documentos não são suportados.'));
        }

        try {
            $reader = new XlsxReader;
            // Not data-only: the number formats are what distinguish 0,75 de 75%,
            // and they are unavailable in that mode.
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(false);
            $spreadsheet = $reader->load($absolutePath);
        } catch (Throwable) {
            throw new UnreadableSpreadsheet(__('Não foi possível ler este ficheiro Excel. Confirme que é um .xlsx válido.'));
        }

        try {
            if ($spreadsheet->getSheetCount() > self::MAX_SHEETS) {
                throw new UnreadableSpreadsheet(__('O ficheiro tem mais de :folhas folhas.', ['folhas' => self::MAX_SHEETS]));
            }

            $sheets = [];

            foreach ($spreadsheet->getAllSheets() as $worksheet) {
                $sheets[] = $this->sheet($worksheet);
            }

            return new TabularSourceSnapshot(
                sheets: $sheets,
                metadata: [
                    'kind' => 'xlsx',
                    'sheets' => count($sheets),
                    'defined_names' => $this->lapisDefinedNames($spreadsheet),
                ],
            );
        } finally {
            // Cells reference the sheet which references the workbook — a cycle
            // the collector will not break. Left connected, reading several files
            // in one process walks into the memory limit, which is exactly how
            // this was found while building the Intuitivo reader.
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * The workbook's defined names, filtered to the ones LÁPIS writes.
     *
     * A NARROW read, and deliberately so. Defined names are the only part of a
     * workbook's structure this reader looks at beyond the cells, and it looks
     * at exactly the names carrying our own contract — anything a teacher or
     * another application defined is skipped without being examined (§6).
     *
     * Nothing is evaluated: a constant defined name is stored as the text of a
     * string literal, and unwrapping `="x"` to `x` is string handling.
     *
     * @return array<string, string>
     */
    protected function lapisDefinedNames(Spreadsheet $spreadsheet): array
    {
        $names = [];

        foreach ($spreadsheet->getDefinedNames() as $definedName) {
            $name = strtoupper($definedName->getName());

            if (! str_starts_with($name, 'LAPIS_')) {
                continue;
            }

            $value = LapisGridContract::unwrap($definedName->getValue());

            if ($value !== null) {
                $names[$name] = $value;
            }
        }

        return $names;
    }

    protected function sheet(Worksheet $worksheet): TabularSheet
    {
        $lastColumn = TabularColumn::index($worksheet->getHighestDataColumn());
        $lastRow = $worksheet->getHighestDataRow();

        if ($lastColumn > self::MAX_COLUMNS) {
            throw new UnreadableSpreadsheet(__('A folha «:folha» tem mais de :colunas colunas.', [
                'folha' => $worksheet->getTitle(),
                'colunas' => self::MAX_COLUMNS,
            ]));
        }

        if ($lastRow > self::MAX_ROWS) {
            throw new UnreadableSpreadsheet(__('A folha «:folha» tem mais de :linhas linhas. Divida-a antes de importar.', [
                'folha' => $worksheet->getTitle(),
                'linhas' => self::MAX_ROWS,
            ]));
        }

        // An entirely empty sheet reports a highest row of 1 and a highest column
        // of A; the resulting single blank cell is what `isEmpty()` recognises.
        $rows = [];

        for ($rowNumber = 1; $rowNumber <= $lastRow; $rowNumber++) {
            $cells = [];

            for ($columnIndex = 1; $columnIndex <= $lastColumn; $columnIndex++) {
                $coordinate = TabularColumn::letter($columnIndex).$rowNumber;

                $cells[] = $worksheet->cellExists($coordinate)
                    ? $this->cell($worksheet, $coordinate)
                    : TabularCell::empty();
            }

            $rows[] = $cells;
        }

        return new TabularSheet(
            name: $worksheet->getTitle(),
            rows: $rows,
            columnCount: $lastColumn,
        );
    }

    protected function cell(Worksheet $worksheet, string $coordinate): TabularCell
    {
        $cell = $worksheet->getCell($coordinate);
        $value = $cell->getValue();

        if ($value === null) {
            return TabularCell::empty();
        }

        if ($this->isFormula($cell, $value)) {
            // The formula text is kept so the teacher can be told which column is
            // the problem. It is never parsed and never evaluated.
            return new TabularCell(text: trim((string) $value), isFormula: true);
        }

        if (is_bool($value)) {
            return new TabularCell(text: $value ? 'VERDADEIRO' : 'FALSO');
        }

        $isPercentage = $this->isPercentageFormatted($worksheet, $coordinate);

        if (is_int($value) || is_float($value)) {
            // A percent-formatted cell stores 0.75 and shows 75%. What the
            // teacher sees is what is recorded, so that a CSV saying «75%» and a
            // workbook showing «75%» arrive here as the same number (§24).
            $number = $isPercentage
                ? TabularNumber::fromFloat((float) $value * 100)
                : TabularNumber::fromFloat((float) $value);

            return new TabularCell(
                text: $isPercentage ? $number.'%' : $number,
                number: $number,
                isPercentage: $isPercentage,
            );
        }

        $text = trim((string) $value);

        if ($text === '') {
            return TabularCell::empty();
        }

        return new TabularCell(
            text: $text,
            number: TabularNumber::normalise($text),
            // A text cell can still say «75%» itself, in a sheet somebody typed
            // by hand rather than formatted.
            isPercentage: $isPercentage || TabularNumber::looksLikeAPercentage($text),
        );
    }

    protected function isFormula(Cell $cell, mixed $value): bool
    {
        return $cell->getDataType() === DataType::TYPE_FORMULA
            || (is_string($value) && str_starts_with(trim($value), '='));
    }

    protected function isPercentageFormatted(Worksheet $worksheet, string $coordinate): bool
    {
        $format = $worksheet->getStyle($coordinate)->getNumberFormat()->getFormatCode();

        // A literal `%` inside quotes is a suffix somebody typed into the format,
        // not a percentage multiplier; everything else containing `%` is one.
        return is_string($format) && str_contains(preg_replace('/"[^"]*"/', '', $format) ?? '', '%');
    }
}
