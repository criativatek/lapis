<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A synthetic INOVAR grid, faithful to the structure of a real export and
 * containing none of its data.
 *
 * The shape was read off a real file: one sheet named after the subject, a
 * merged «Avaliação Qualitativa» banner on row 2, the domain names on row 3
 * from column D, and one student per row from row 4 — order, N.º de processo,
 * name. Column I sits inside the banner's span with no name of its own, which
 * is exactly what the real grid does and exactly what must be left alone.
 *
 * The process numbers deliberately mix a text cell and a numeric one, because
 * the real export does: five were strings and one was an integer.
 *
 * Every name and number here is invented.
 */
class InovarGridFixture
{
    /** The synthetic roll: process number → name. Text first, numeric last. */
    public const STUDENTS = [
        '001234' => 'Aurora Pimentel',
        '001235' => 'Belmiro Tavares',
        '4471' => 'Custódia Nogueira',
    ];

    public const DOMAINS = ['D' => 'Oralidade', 'E' => 'Leitura', 'F' => 'Escrita'];

    /**
     * @param  array<string, mixed>  $options
     *                                         - sheet: the sheet title (default «Português»)
     *                                         - domains: column letter → header text
     *                                         - students: process number → name
     *                                         - numeric_last: write the last process number as a number (default true)
     *                                         - extra_sheet: add a second sheet, which the reader must refuse
     *                                         - no_header: leave the domain names out
     *                                         - level_column: an UNNAMED column carrying a number per student, as one real
     *                                         grid has (column I, values 2/3/4/5, no header of its own). Off by
     *                                         default, because the other real grid has no such column at all.
     *                                         - level_values: the values to write down that column, cycled over the roll
     *                                         (default 3, 4, 5)
     *                                         - format: 'xls' (default, what INOVAR itself produces) or 'xlsx', the
     *                                         shape a school hands over after opening and re-saving the grid
     *                                         - formula_cell: coordinate → formula, to prove the writer never
     *                                         evaluates or flattens one
     */
    public function build(array $options = []): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($options['sheet'] ?? 'Português');

        $domains = $options['domains'] ?? self::DOMAINS;
        $students = $options['students'] ?? self::STUDENTS;
        $numericLast = $options['numeric_last'] ?? true;

        // The banner: one value, merged across the domain columns and one more.
        $sheet->setCellValue('D2', 'Avaliação Qualitativa');
        $sheet->mergeCells('D2:I2');

        if (($options['no_header'] ?? false) !== true) {
            foreach ($domains as $column => $name) {
                $sheet->setCellValue($column.'3', $name);
            }
        }

        $row = 4;
        $order = 1;
        $last = array_key_last($students);

        // The unnamed column one real grid carries: a number per student and no
        // header, which is precisely why it can never be identified by name.
        $levelColumn = $options['level_column'] ?? null;
        /** @var list<string> $levelValues */
        $levelValues = $options['level_values'] ?? ['3', '4', '5'];
        $levelIndex = 0;

        foreach ($students as $processNumber => $name) {
            $sheet->setCellValueExplicit('A'.$row, (string) $order, DataType::TYPE_STRING);

            // A numeric cell cannot hold a leading zero — in Excel either, which
            // is why a school with zero-padded numbers gets them as text. The
            // real grid mixed the two, and so does this.
            $fitsANumericCell = ctype_digit((string) $processNumber) && ! str_starts_with((string) $processNumber, '0');

            if ($numericLast && $processNumber === $last && $fitsANumericCell) {
                $sheet->setCellValueExplicit('B'.$row, (int) $processNumber, DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValueExplicit('B'.$row, (string) $processNumber, DataType::TYPE_STRING);
            }

            $sheet->setCellValueExplicit('C'.$row, $name, DataType::TYPE_STRING);

            if ($levelColumn !== null && $levelValues !== []) {
                $sheet->setCellValueExplicit(
                    $levelColumn.$row,
                    $levelValues[$levelIndex % count($levelValues)],
                    DataType::TYPE_STRING,
                );
                $levelIndex++;
            }

            $row++;
            $order++;
        }

        // Styling worth preserving, so a fidelity test has something to check.
        $sheet->getColumnDimension('C')->setWidth(39.42578125);
        $sheet->getStyle('D3:H3')->getFont()->setBold(true);

        // A formula, so a fidelity test can prove it comes back as a formula
        // and never as the number it happened to evaluate to.
        /** @var array<string, string> $formulas */
        $formulas = $options['formula_cell'] ?? [];

        foreach ($formulas as $coordinate => $formula) {
            $sheet->setCellValueExplicit($coordinate, $formula, DataType::TYPE_FORMULA);
        }

        if (($options['extra_sheet'] ?? false) === true) {
            $spreadsheet->createSheet()->setTitle('Outra');
        }

        // INOVAR itself produces .xls, so that stays the default. A school that
        // opens the grid and saves it hands over .xlsx instead, and the export
        // has to carry that difference all the way to the file name — which is
        // only provable if a fixture can be either.
        $xlsx = ($options['format'] ?? 'xls') === 'xlsx';

        $path = tempnam(sys_get_temp_dir(), 'inovar_fixture_').($xlsx ? '.xlsx' : '.xls');
        ($xlsx ? new Xlsx($spreadsheet) : new Xls($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
