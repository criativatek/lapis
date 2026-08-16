<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

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

            $row++;
            $order++;
        }

        // Styling worth preserving, so a fidelity test has something to check.
        $sheet->getColumnDimension('C')->setWidth(39.42578125);
        $sheet->getStyle('D3:H3')->getFont()->setBold(true);

        if (($options['extra_sheet'] ?? false) === true) {
            $spreadsheet->createSheet()->setTitle('Outra');
        }

        $path = tempnam(sys_get_temp_dir(), 'inovar_fixture_').'.xls';
        (new Xls($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
