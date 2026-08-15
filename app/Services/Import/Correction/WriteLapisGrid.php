<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\LapisGridContract;
use App\Domain\Import\Tabular\TabularColumn;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedFormula;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Builds the grid a teacher fills in, from the instrument they are correcting.
 *
 * Deliberately boring to look at: one sheet, headings on row 1, students from
 * row 2, one column per item. No instructions above the table, no merged cells,
 * no second sheet explaining the first, no decorative title. Every one of those
 * is a thing the teacher has to scroll past and a thing the reader has to be
 * taught to skip — and the reason a spreadsheet made elsewhere needs five
 * questions before it can be read at all (§4).
 *
 * The students are already in it. Asking a teacher to paste their own class
 * into a file the application generated for that class would be asking them to
 * do the one part it certainly knows (§5).
 *
 * WHAT TRAVELS AND WHAT DOES NOT. The file carries identity — which instrument,
 * which class, which item is in which column, which enrolment is on which row —
 * and nothing else. Not the cotação, not the domain, not the weights. Those are
 * read back from the database on import, because a number in a workbook is a
 * claim and the database is the record (§7).
 */
class WriteLapisGrid
{
    /**
     * Writes the workbook to `$absolutePath` and returns it.
     */
    public function write(Instrument $instrument, string $absolutePath): string
    {
        $instrument->loadMissing(['items' => fn ($query) => $query->orderBy('sequence'), 'schoolClass']);

        $spreadsheet = new Spreadsheet;

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(LapisGridContract::SHEET);

            $items = $instrument->items->values();
            $this->headings($sheet, $items);
            $this->students($sheet, $instrument, $items->count());
            $this->contract($spreadsheet, $instrument, $items);
            $this->presentation($sheet, $items->count());

            (new XlsxWriter($spreadsheet))->save($absolutePath);

            return $absolutePath;
        } finally {
            // Cells reference the sheet which references the workbook. Released
            // here rather than left to a collector that will not break the cycle.
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * A filename a teacher recognises in their downloads folder.
     */
    public function filename(Instrument $instrument): string
    {
        $parts = array_filter([
            'Grelha',
            $instrument->schoolClass->label ?? null,
            $instrument->title,
        ]);

        $name = (string) preg_replace('/[^\p{L}\p{N} \-.º]+/u', ' ', implode(' — ', $parts));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return ($name === '' ? 'Grelha LÁPIS' : $name).'.xlsx';
    }

    /**
     * @param  Collection<int, InstrumentItem>  $items
     */
    protected function headings(Worksheet $sheet, $items): void
    {
        $row = LapisGridContract::HEADER_ROW;

        // The hidden one first, so the columns a teacher sees start where the
        // eye starts. Its heading is never read — the defined names carry the
        // contract — but a stray visible cell with no label reads as a mistake.
        $sheet->setCellValue(LapisGridContract::COLUMN_ENROLLMENT.$row, 'LÁPIS');
        $sheet->setCellValue(LapisGridContract::COLUMN_NUMBER.$row, 'N.º');
        $sheet->setCellValue(LapisGridContract::COLUMN_NAME.$row, 'Nome');

        foreach ($items as $index => $item) {
            $sheet->setCellValue($this->itemColumn($index).$row, $this->headingFor($item));
        }
    }

    /**
     * The item's own title, with what it is out of.
     *
     * The title comes first and unchanged, because it is the thing the teacher
     * wrote and the thing they will look for. A code like «Q7» is only used
     * when there is no title to use instead (§4).
     */
    protected function headingFor(InstrumentItem $item): string
    {
        $label = trim((string) ($item->label ?? '')) ?: (string) $item->code;
        $points = $this->decimal((string) $item->points_possible);

        if ($item->is_bonus && $this->isZero($points)) {
            // A deduction: it takes away from the domain it belongs to and
            // never changes what that domain is out of (§8).
            return $label.' (desconto)';
        }

        return $item->is_bonus
            ? $label.' (bónus, máx. '.$points.')'
            : $label.' (máx. '.$points.')';
    }

    protected function students(Worksheet $sheet, Instrument $instrument, int $itemCount): void
    {
        $enrollments = Enrollment::query()
            ->where('class_id', $instrument->class_id)
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        $row = LapisGridContract::FIRST_DATA_ROW;

        foreach ($enrollments as $enrollment) {
            $sheet->setCellValue(LapisGridContract::COLUMN_ENROLLMENT.$row, $enrollment->ulid);
            $sheet->setCellValue(LapisGridContract::COLUMN_NUMBER.$row, $enrollment->class_number);
            $sheet->setCellValueExplicit(
                LapisGridContract::COLUMN_NAME.$row,
                optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                DataType::TYPE_STRING,
            );

            $row++;
        }

        // Result cells are left genuinely empty. An empty cell is «por avaliar»
        // and a zero is a zero, and pre-filling either one would decide
        // something about a child nobody has decided yet (§10).
        $this->bounds($sheet, $instrument, $itemCount, $row - 1);
    }

    /**
     * Excel's own guard rail on what may be typed into a result cell.
     *
     * Convenience for the teacher and nothing more: the server validates every
     * value again on import, because a workbook's own validation is a setting
     * anybody can switch off.
     */
    protected function bounds(
        Worksheet $sheet,
        Instrument $instrument,
        int $itemCount,
        int $lastRow,
    ): void {
        if ($lastRow < LapisGridContract::FIRST_DATA_ROW) {
            return;
        }

        foreach ($instrument->items->values() as $index => $item) {
            $points = $this->decimal((string) $item->points_possible);
            $deduction = $item->is_bonus && $this->isZero($points);

            for ($row = LapisGridContract::FIRST_DATA_ROW; $row <= $lastRow; $row++) {
                $validation = $sheet->getCell($this->itemColumn($index).$row)->getDataValidation();
                $validation->setType(DataValidation::TYPE_DECIMAL);
                $validation->setErrorStyle(DataValidation::STYLE_STOP);
                $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
                $validation->setAllowBlank(true);
                $validation->setShowErrorMessage(true);
                $validation->setErrorTitle('Valor fora do previsto');
                $validation->setError($deduction
                    ? 'Este desconto aceita valores negativos ou zero.'
                    : 'Indique um valor entre 0 e '.$points.'.');
                $validation->setFormula1($deduction ? '-'.($points === '0' ? '100' : $points) : '0');
                $validation->setFormula2($deduction ? '0' : $points);
            }
        }

        unset($itemCount);
    }

    /**
     * @param  Collection<int, InstrumentItem>  $items
     */
    protected function contract(Spreadsheet $spreadsheet, Instrument $instrument, $items): void
    {
        $sheet = $spreadsheet->getActiveSheet();

        $named = [
            LapisGridContract::NAME_MARKER => LapisGridContract::MARKER,
            LapisGridContract::NAME_VERSION => LapisGridContract::VERSION,
            LapisGridContract::NAME_INSTRUMENT => (string) $instrument->ulid,
            LapisGridContract::NAME_CLASS => (string) $instrument->schoolClass->ulid,
        ];

        foreach ($items as $index => $item) {
            $named[LapisGridContract::itemName($this->itemColumn($index))] = (string) $item->ulid;
        }

        foreach ($named as $name => $value) {
            $spreadsheet->addNamedFormula(new NamedFormula($name, $sheet, LapisGridContract::wrap($value)));
        }
    }

    protected function presentation(Worksheet $sheet, int $itemCount): void
    {
        // The identity column is hidden rather than absent: the teacher never
        // has to see it, and deleting a column they cannot see is not something
        // that happens by accident (§5).
        $sheet->getColumnDimension(LapisGridContract::COLUMN_ENROLLMENT)->setVisible(false);
        $sheet->getColumnDimension(LapisGridContract::COLUMN_NUMBER)->setWidth(6);
        $sheet->getColumnDimension(LapisGridContract::COLUMN_NAME)->setWidth(32);

        for ($index = 0; $index < $itemCount; $index++) {
            $sheet->getColumnDimension($this->itemColumn($index))->setWidth(16);
        }

        $lastColumn = $itemCount === 0
            ? LapisGridContract::COLUMN_NAME
            : $this->itemColumn($itemCount - 1);

        $heading = $sheet->getStyle(
            LapisGridContract::COLUMN_ENROLLMENT.LapisGridContract::HEADER_ROW.':'.$lastColumn.LapisGridContract::HEADER_ROW,
        );
        $heading->getFont()->setBold(true);
        $heading->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

        // Names and numbers stay put while the teacher scrolls right through
        // sixteen items — the difference between a usable grid and a guess.
        $sheet->freezePane(LapisGridContract::FIRST_ITEM_COLUMN.LapisGridContract::FIRST_DATA_ROW);
    }

    protected function itemColumn(int $index): string
    {
        return TabularColumn::letter(
            TabularColumn::index(LapisGridContract::FIRST_ITEM_COLUMN) + $index,
        );
    }

    protected function decimal(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' || $value === '-' ? '0' : $value;
    }

    protected function isZero(string $value): bool
    {
        return is_numeric($value) && (float) $value === 0.0;
    }
}
