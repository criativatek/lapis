<?php

namespace App\Services\Assessment;

use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\ResultState;
use App\Models\StudentItemScore;
use App\Services\Assessment\Analysis\BuildResultsAnalysis;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * The corrected grid as a spreadsheet: what each student was given on each
 * question, and what that adds up to.
 *
 * It is the COUNTERPART of WriteLapisGrid, not a variant of it. That one is the
 * blank form a teacher fills in offline and brings back, and its result cells
 * are empty on purpose; this one carries the saved results out and is never
 * read back in.
 *
 * NOTHING HERE IS CALCULATED. Question marks are the stored scores; the domain
 * points, percentages and the final classification are the cells the grid on
 * screen shows, taken from the same BuildResultsAnalysis::officialCells() path,
 * so the file cannot disagree with the screen. They are written as values and
 * not as SUM() formulas: a formula would be a second copy of the engine's
 * allocation, eligibility and absence rules, and the first time they differed
 * the workbook would be the one telling the teacher the wrong number.
 *
 * AN EMPTY CELL IS «POR AVALIAR» AND A ZERO IS A ZERO. A state that carries no
 * value (absent, exempt, not applicable…) is written as its label, so it is
 * neither of those.
 */
class WriteInstrumentResults
{
    public const SHEET = 'Resultados';

    private const HEADER_ROW = 1;

    private const MAX_ROW = 2;

    private const FIRST_STUDENT_ROW = 3;

    private const FIRST_ITEM_COLUMN = 3;

    public function __construct(protected BuildResultsAnalysis $analysis) {}

    /**
     * Writes the workbook to `$absolutePath` and returns it.
     */
    public function write(Instrument $instrument, string $absolutePath): string
    {
        $instrument->loadMissing([
            'items' => fn ($query) => $query->orderBy('sequence'),
            'items.domainAllocations.domain',
            'schoolClass',
        ]);

        $official = $this->analysis->officialCells($instrument);
        $label = $instrument->status->isConcluded() ? 'Classificação oficial' : 'Classificação provisória';

        $enrollments = Enrollment::query()
            ->where('class_id', $instrument->class_id)
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        $scores = StudentItemScore::query()
            ->where('instrument_id', $instrument->id)
            ->get()
            ->keyBy(fn (StudentItemScore $score): string => $score->enrollment_id.':'.$score->instrument_item_id);

        $spreadsheet = new Spreadsheet;

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(self::SHEET);

            $items = $instrument->items->values();
            $domains = $official['domains'];
            $firstDomainColumn = self::FIRST_ITEM_COLUMN + $items->count();
            $globalColumn = $firstDomainColumn + count($domains) * 3;

            $this->headings($sheet, $items, $domains, $firstDomainColumn, $globalColumn, $label);

            $row = self::FIRST_STUDENT_ROW;
            foreach ($enrollments as $enrollment) {
                $this->studentRow($sheet, $row, $instrument, $enrollment, $items, $scores, $official, $domains, $firstDomainColumn, $globalColumn);
                $row++;
            }

            $this->presentation($sheet, $items->count(), count($domains), $globalColumn, $row - 1);

            (new XlsxWriter($spreadsheet))->save($absolutePath);

            return $absolutePath;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function filename(Instrument $instrument): string
    {
        $parts = array_filter([
            'Resultados',
            $instrument->schoolClass->label ?? null,
            $instrument->title,
        ]);

        $name = (string) preg_replace('/[^\p{L}\p{N} \-.º]+/u', ' ', implode(' — ', $parts));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return ($name === '' ? 'Resultados Lapispro' : $name).'.xlsx';
    }

    /**
     * @param  Collection<int, InstrumentItem>  $items
     * @param  list<array{key: string, id: int, name: string}>  $domains
     */
    protected function headings(Worksheet $sheet, $items, array $domains, int $firstDomainColumn, int $globalColumn, string $classificationLabel): void
    {
        $this->text($sheet, 1, self::HEADER_ROW, 'N.º');
        $this->text($sheet, 2, self::HEADER_ROW, 'Nome');
        $this->text($sheet, 2, self::MAX_ROW, 'Pontuação máxima');

        foreach ($items as $index => $item) {
            $column = self::FIRST_ITEM_COLUMN + $index;
            $this->text($sheet, $column, self::HEADER_ROW, $this->itemHeading($item));

            $max = $this->decimal($item->points_possible);
            if ($item->is_bonus && $max === 0.0) {
                $this->text($sheet, $column, self::MAX_ROW, 'desconto');
            } else {
                $this->number($sheet, $column, self::MAX_ROW, $max);
            }
        }

        foreach ($domains as $index => $domain) {
            $column = $firstDomainColumn + $index * 3;
            $this->text($sheet, $column, self::HEADER_ROW, $domain['name'].' — pontos obtidos');
            $this->text($sheet, $column + 1, self::HEADER_ROW, $domain['name'].' — pontos possíveis');
            $this->text($sheet, $column + 2, self::HEADER_ROW, $domain['name'].' — %');
        }

        $this->text($sheet, $globalColumn, self::HEADER_ROW, 'Global — %');
        $this->text($sheet, $globalColumn + 1, self::HEADER_ROW, $classificationLabel);
    }

    protected function itemHeading(InstrumentItem $item): string
    {
        $label = trim((string) ($item->label ?? '')) ?: (string) $item->code;
        $identity = $label === $item->code ? (string) $item->code : $item->code.' · '.$label;
        $domains = $item->domainAllocations
            ->map(fn ($allocation): string => mb_strtoupper((string) $allocation->domain->name))
            ->implode(' + ');

        return ($domains === '' ? '' : $domains."\n").$identity.($item->is_bonus ? ' (bónus)' : '');
    }

    /**
     * @param  Collection<int, InstrumentItem>  $items
     * @param  Collection<string, StudentItemScore>  $scores
     * @param  array{domains: list<array{key: string, id: int, name: string}>, students: array<int, array<string, mixed>>}  $official
     * @param  list<array{key: string, id: int, name: string}>  $domains
     */
    protected function studentRow(
        Worksheet $sheet,
        int $row,
        Instrument $instrument,
        Enrollment $enrollment,
        $items,
        $scores,
        array $official,
        array $domains,
        int $firstDomainColumn,
        int $globalColumn,
    ): void {
        if ($enrollment->class_number !== null) {
            $this->number($sheet, 1, $row, (float) $enrollment->class_number, '0');
        }

        $this->text($sheet, 2, $row, optional($enrollment->student->identity)->display_name ?? '(sem identidade)');

        $joinedAfter = $enrollment->enrolled_on->greaterThan($instrument->applied_on);

        foreach ($items as $index => $item) {
            $column = self::FIRST_ITEM_COLUMN + $index;
            $score = $scores->get($enrollment->id.':'.$item->id);

            if ($score === null) {
                // No row at all: never decided. The one exception is someone
                // who joined after the instrument was applied — it never
                // applied to them, which is not «por avaliar».
                if ($joinedAfter) {
                    $this->text($sheet, $column, $row, ResultState::NotApplicable->label());
                }

                continue;
            }

            if ($score->result_state === ResultState::Assessed && $score->points_earned !== null) {
                $this->number($sheet, $column, $row, $this->decimal($score->points_earned));
            } elseif ($score->result_state !== ResultState::Pending) {
                $this->text($sheet, $column, $row, $score->result_state->label());
            }
        }

        $cells = $official['students'][$enrollment->id] ?? null;

        foreach ($domains as $index => $domain) {
            $column = $firstDomainColumn + $index * 3;
            $cell = $cells['domains'][$domain['key']] ?? null;

            if ($cell === null || $cell['exact'] === null) {
                continue;
            }

            if (($cell['points_earned'] ?? null) !== null && ($cell['points_possible'] ?? null) !== null) {
                $this->number($sheet, $column, $row, $this->decimal($cell['points_earned']));
                $this->number($sheet, $column + 1, $row, $this->decimal($cell['points_possible']));
            }

            $this->percent($sheet, $column + 2, $row, $cell);
        }

        if ($cells === null) {
            return;
        }

        $global = $cells['global'];

        if ($global['exact'] !== null) {
            $this->percent($sheet, $globalColumn, $row, $global);
            $this->text($sheet, $globalColumn + 1, $row, $global['band']['label'] ?? '—');
        } else {
            $this->text($sheet, $globalColumn + 1, $row, (string) $cells['status_label']);
        }
    }

    /**
     * The exact percentage as a number, shown with the precision the screen
     * shows: one decimal, or two when the screen needed two to stay on the
     * right side of a band or the threshold.
     *
     * @param  array<string, mixed>  $cell
     */
    protected function percent(Worksheet $sheet, int $column, int $row, array $cell): void
    {
        $this->number(
            $sheet,
            $column,
            $row,
            (float) $cell['exact'] / 100,
            $cell['value_precise'] !== null ? '0.00%' : '0.0%',
        );
    }

    protected function presentation(Worksheet $sheet, int $itemCount, int $domainCount, int $globalColumn, int $lastRow): void
    {
        $lastColumn = $globalColumn + 1;
        $sheet->getColumnDimensionByColumn(1)->setWidth(6);
        $sheet->getColumnDimensionByColumn(2)->setWidth(32);

        for ($column = self::FIRST_ITEM_COLUMN; $column <= $lastColumn; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setWidth(16);
        }

        $heading = $sheet->getStyle($this->coordinate(1, self::HEADER_ROW).':'.$this->coordinate($lastColumn, self::MAX_ROW));
        $heading->getFont()->setBold(true);
        $heading->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(48);

        $bandStart = self::FIRST_ITEM_COLUMN + $itemCount;
        if ($domainCount > 0 || $itemCount > 0) {
            $sheet->getStyle($this->coordinate($bandStart, self::HEADER_ROW).':'.$this->coordinate($lastColumn, self::HEADER_ROW))
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE7E6E6');
            $sheet->getStyle($this->coordinate($bandStart, self::HEADER_ROW).':'.$this->coordinate($bandStart, $lastRow))
                ->getBorders()->getLeft()->setBorderStyle(Border::BORDER_MEDIUM);
        }

        $sheet->getStyle($this->coordinate(self::FIRST_ITEM_COLUMN, self::MAX_ROW).':'.$this->coordinate($itemCount + self::FIRST_ITEM_COLUMN - 1, self::MAX_ROW))
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->freezePane($this->coordinate(self::FIRST_ITEM_COLUMN, self::FIRST_STUDENT_ROW));
    }

    protected function coordinate(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column).$row;
    }

    protected function text(Worksheet $sheet, int $column, int $row, string $value): void
    {
        $sheet->setCellValueExplicit($this->coordinate($column, $row), $value, DataType::TYPE_STRING);
    }

    protected function number(Worksheet $sheet, int $column, int $row, float $value, string $format = '0.##'): void
    {
        $coordinate = $this->coordinate($column, $row);
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);
    }

    protected function decimal(mixed $value): float
    {
        return (float) $value;
    }
}
