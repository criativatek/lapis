<?php

namespace App\Services\Export;

use App\Domain\Export\InovarTemplate;
use App\Domain\Export\InovarTemplateColumn;
use App\Domain\Export\InovarTemplateStudent;
use App\Support\Export\InovarTemplateException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the grid INOVAR exports, and refuses anything it does not recognise.
 *
 * THE SHAPE, confirmed against a real export before a line of this was written:
 * one sheet named after the subject; a merged «Avaliação Qualitativa» banner; a
 * header row whose cells name the domains; and below it one row per student,
 * carrying an order number, the N.º de processo and the name. No formulas, no
 * validations, no macros, no second sheet.
 *
 * FAIL CLOSED. Everything here is located by CONTENT — the header row by having
 * several non-empty cells to the right of the name column, the student rows by
 * carrying a process number — and the moment something does not add up this
 * throws instead of guessing. A grid filled in the wrong columns is worse than
 * one not filled at all: the school would upload it.
 *
 * It reads and never writes. Formulas are never calculated: the file is
 * untrusted input and `getValue()` returns what is stored, never a computed
 * result.
 */
class InovarTemplateReader
{
    /** Where the order number, the process number and the name live. */
    public const COLUMN_ORDER = 'A';

    public const COLUMN_PROCESS_NUMBER = 'B';

    public const COLUMN_NAME = 'C';

    /** The first column that can carry a qualitative mention. */
    public const FIRST_DOMAIN_COLUMN = 'D';

    /** Below this many named domains, the sheet is not the grid. */
    protected const MINIMUM_DOMAINS = 1;

    /** Nothing sane has more; a runaway scan means the file is not what it claims. */
    protected const MAXIMUM_SCANNED_ROWS = 2000;

    /** Enough of a column's contents for a teacher to recognise it, and no more. */
    protected const SAMPLED_VALUES = 3;

    public function read(string $path): InovarTemplate
    {
        $spreadsheet = $this->load($path);

        try {
            $sheet = $spreadsheet->getSheetCount() === 1
                ? $spreadsheet->getSheet(0)
                : throw InovarTemplateException::notRecognized(
                    'O ficheiro tem mais do que uma folha e a grelha do INOVAR tem apenas uma.',
                );

            $headerRow = $this->locateHeaderRow($sheet);
            $domainColumns = $this->domainColumns($sheet, $headerRow);

            if (count($domainColumns) < self::MINIMUM_DOMAINS) {
                throw InovarTemplateException::notRecognized(
                    'Não foi possível encontrar as colunas de avaliação qualitativa nesta grelha.',
                );
            }

            $students = $this->students($sheet, $headerRow);

            return new InovarTemplate(
                sheet: $sheet->getTitle(),
                headerRow: $headerRow,
                students: $students,
                domainColumns: $domainColumns,
                candidateColumns: $this->candidateColumns($sheet, $headerRow, $domainColumns, $students),
            );
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * The header row is the one that names the domains: the first row with at
     * least one non-empty cell from D onwards AND nothing in the name column,
     * scanning downwards. The banner above it spans the same columns but sits
     * in a merged cell whose value lives in D.
     */
    protected function locateHeaderRow(Worksheet $sheet): int
    {
        $lastRow = min($sheet->getHighestDataRow(), self::MAXIMUM_SCANNED_ROWS);

        for ($row = 1; $row <= $lastRow; $row++) {
            // A student's row carries a name; a header row does not.
            if ($this->cell($sheet, self::COLUMN_NAME, $row) !== null) {
                break;
            }

            $named = $this->domainColumns($sheet, $row);

            // The banner row has exactly one filled cell across the whole span;
            // the header row names every domain it covers.
            if (count($named) >= 2) {
                return $row;
            }
        }

        throw InovarTemplateException::notRecognized(
            'Não foi possível encontrar a linha com os nomes dos domínios nesta grelha.',
        );
    }

    /**
     * The domain columns of a row, by letter — every non-empty cell from the
     * first domain column to the last one the sheet uses.
     *
     * A column inside the banner's span but with no name is NOT a domain: the
     * real grid has one, and what it means was never confirmed, so it is left
     * exactly as it is.
     *
     * @return array<string, string>
     */
    protected function domainColumns(Worksheet $sheet, int $row): array
    {
        $first = Coordinate::columnIndexFromString(self::FIRST_DOMAIN_COLUMN);
        $last = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $columns = [];

        for ($index = $first; $index <= $last; $index++) {
            $letter = Coordinate::stringFromColumnIndex($index);
            $value = $this->cell($sheet, $letter, $row);

            if ($value !== null) {
                $columns[$letter] = $value;
            }
        }

        return $columns;
    }

    /**
     * The columns that are NOT domains — the only places a level could be
     * written, reported so a person can choose one.
     *
     * NOTHING IS INFERRED HERE, and that is the entire point. Neither real grid
     * audited before this was written names a column for the level: the `.xlsx`
     * has no such column at all, and in the `.xls` the one carrying 2/3/4/5 has
     * no header. Taking «the column after the last domain» would be inventing a
     * mapping, and a grid filled in the wrong column is worse than an empty one
     * because the school uploads it either way.
     *
     * A COLUMN HAS TO SHOW ITSELF TO BE OFFERED: either it names itself in the
     * header row, or it already carries values on the students' own rows. A
     * column that does neither is not a place — it is the blank space past the
     * end of the grid, which a stray style can extend by several columns, and
     * offering that would be the same guess wearing a different hat.
     *
     * `header` is normally null, because a column with a header is a domain
     * column by the rule above. It is read and carried anyway: the day INOVAR
     * does name that column, it is a name and not a guess, and the preparation
     * screen can pre-select it.
     *
     * @param  array<string, string>  $domainColumns
     * @param  list<InovarTemplateStudent>  $students
     * @return list<InovarTemplateColumn>
     */
    protected function candidateColumns(Worksheet $sheet, int $headerRow, array $domainColumns, array $students): array
    {
        $first = Coordinate::columnIndexFromString(self::FIRST_DOMAIN_COLUMN);
        $last = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $candidates = [];

        for ($index = $first; $index <= $last; $index++) {
            $letter = Coordinate::stringFromColumnIndex($index);

            if (isset($domainColumns[$letter])) {
                continue;
            }

            $header = $this->cell($sheet, $letter, $headerRow);
            $samples = [];

            foreach ($students as $student) {
                if (count($samples) >= self::SAMPLED_VALUES) {
                    break;
                }

                $value = $this->cell($sheet, $letter, $student->row);

                if ($value !== null) {
                    $samples[] = $value;
                }
            }

            if ($header === null && $samples === []) {
                continue;
            }

            $candidates[] = new InovarTemplateColumn($letter, $header, $samples);
        }

        return $candidates;
    }

    /**
     * Every student line below the header: contiguous rows carrying a process
     * number, stopping at the first that does not.
     *
     * @return list<InovarTemplateStudent>
     */
    protected function students(Worksheet $sheet, int $headerRow): array
    {
        $students = [];
        $lastRow = min($sheet->getHighestDataRow(), self::MAXIMUM_SCANNED_ROWS);

        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $processNumber = $this->cell($sheet, self::COLUMN_PROCESS_NUMBER, $row);
            $name = $this->cell($sheet, self::COLUMN_NAME, $row);

            // Neither a number nor a name: the roll has ended.
            if ($processNumber === null && $name === null) {
                break;
            }

            $students[] = new InovarTemplateStudent(
                row: $row,
                processNumber: $processNumber,
                name: $name ?? '',
            );
        }

        if ($students === []) {
            throw InovarTemplateException::notRecognized('Não foi encontrado nenhum aluno nesta grelha.');
        }

        return $students;
    }

    /**
     * A cell's stored value as a trimmed string, or null when it is empty.
     *
     * ALWAYS A STRING. The grid writes some process numbers as text and some as
     * numbers — a real export contained both — and casting to an integer would
     * eat the leading zero of somebody's identifier. `getValue()` returns what
     * is stored and never calculates: the file is untrusted input.
     */
    protected function cell(Worksheet $sheet, string $column, int $row): ?string
    {
        $value = $sheet->getCell($column.$row)->getValue();

        if ($value === null || is_bool($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    protected function load(string $path): Spreadsheet
    {
        try {
            $reader = IOFactory::createReader(IOFactory::identify($path));
            // Styles and merges are needed by the writer later; formulas are
            // never calculated either way.
            $reader->setReadDataOnly(false);

            // @ suppresses the legacy .xls reader's harmless warnings around
            // structures it does not fully model — the same suppression the
            // roster parser has carried since it met a real export.
            return @$reader->load($path);
        } catch (\Throwable $exception) {
            throw InovarTemplateException::unreadable($exception->getMessage());
        }
    }
}
