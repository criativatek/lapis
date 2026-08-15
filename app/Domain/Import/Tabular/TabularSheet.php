<?php

namespace App\Domain\Import\Tabular;

/**
 * One sheet, as a rectangle of cells.
 *
 * Rows and columns are addressed the way the teacher sees them in Excel — row 1
 * is the first row, column A is the first column — because every question the
 * wizard asks («qual é a linha dos títulos?») is asked in those terms. An
 * off-by-one between what the screen says and what the importer reads would put
 * marks on the wrong students, so there is exactly one convention and it is the
 * spreadsheet's own.
 */
final readonly class TabularSheet
{
    /**
     * @param  list<list<TabularCell>>  $rows  In file order; index 0 is row 1.
     */
    public function __construct(
        public string $name,
        public array $rows = [],
        public int $columnCount = 0,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * @return list<TabularCell>
     */
    public function row(int $rowNumber): array
    {
        return $this->rows[$rowNumber - 1] ?? [];
    }

    public function cell(int $rowNumber, int $columnIndex): TabularCell
    {
        return $this->row($rowNumber)[$columnIndex - 1] ?? TabularCell::empty();
    }

    public function cellAt(int $rowNumber, string $columnLetter): TabularCell
    {
        return $this->cell($rowNumber, TabularColumn::index($columnLetter));
    }

    /**
     * A row nobody wrote anything in. Skipped wherever rows are walked: a blank
     * line between the title and the table, or trailing empty rows a spreadsheet
     * left behind, are not students (§12).
     */
    public function rowIsBlank(int $rowNumber): bool
    {
        foreach ($this->row($rowNumber) as $cell) {
            if (! $cell->isBlank()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Row numbers that carry something, in order.
     *
     * @return list<int>
     */
    public function occupiedRows(): array
    {
        $rows = [];

        for ($rowNumber = 1; $rowNumber <= $this->rowCount(); $rowNumber++) {
            if (! $this->rowIsBlank($rowNumber)) {
                $rows[] = $rowNumber;
            }
        }

        return $rows;
    }

    public function isEmpty(): bool
    {
        return $this->occupiedRows() === [];
    }

    /**
     * @return list<string>
     */
    public function columnLetters(): array
    {
        $letters = [];

        for ($index = 1; $index <= $this->columnCount; $index++) {
            $letters[] = TabularColumn::letter($index);
        }

        return $letters;
    }

    /**
     * The first `$limit` occupied rows, for showing the teacher what the file
     * looks like without putting a whole sheet in the browser (§10).
     *
     * @return list<array{row: int, cells: list<array<string, mixed>>}>
     */
    public function sample(int $limit): array
    {
        $sample = [];

        foreach ($this->occupiedRows() as $rowNumber) {
            if (count($sample) >= $limit) {
                break;
            }

            $cells = [];

            for ($index = 1; $index <= $this->columnCount; $index++) {
                $cells[] = $this->cell($rowNumber, $index)->toArray();
            }

            $sample[] = ['row' => $rowNumber, 'cells' => $cells];
        }

        return $sample;
    }
}
