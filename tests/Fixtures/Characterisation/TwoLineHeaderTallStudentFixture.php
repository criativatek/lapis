<?php

namespace Tests\Fixtures\Characterisation;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * JANELA N — the geometry of a REAL «Medidas» workbook a teacher sent in,
 * reproduced cell for cell and merge for merge, with every name, measure and
 * observation replaced by invented ones.
 *
 * NEVER A REAL STUDENT. The workbook this was measured against was inspected
 * locally and never copied, committed or quoted; what survives here is its
 * SHAPE, which is the only thing the defect was ever about — see
 * CharacterisationFixture's own docblock for why that is a data-protection
 * rule and not a style preference.
 *
 * Two things make this shape different from MultilevelHeaderFixture's, and
 * both were defects:
 *
 *  1. THE SECOND HEADER LEVEL OWNS MOST COLUMNS. The labels are typed across
 *     two printed rows («Coadju»/«vação», «Psico»/«logia»), so the second row
 *     is not the sparse "subdivide a colspan" row the earlier fixture has.
 *     Only four columns («MU», «MS», «MA», «ATE») carry their whole label on
 *     the first row with a rowspan of 2. The two rows nonetheless close a
 *     rectangle, and that is what makes them a header.
 *
 *  2. THE FIRST STUDENT OCCUPIES FOUR PRINTED ROWS. Her «MS» and her
 *     «Observações» run to four lines each, typed as four separate cells one
 *     under the other, while every other column of hers is merged vertically
 *     across all four. She is ONE student. Two later students occupy two
 *     printed rows each, the same way, with only «ATE» split.
 *
 * Physical geometry, which must stay exactly as it is for this fixture to
 * mean anything:
 *
 *   rows 1-2    header, 15 columns
 *   row 3       full-width caption «Alunos com RTP»
 *   rows 4-7    student 1 (merged r4:r7 in every column but MS and Observações)
 *   row 8       full-width caption «Alunos sem RTP»
 *   row 9       student 2
 *   rows 10-11  student 3 (merged r10:r11 in every column but ATE)
 *   row 12      student 4
 *   rows 13-14  student 5 (merged r13:r14 in every column but ATE)
 *   rows 15-21  students 6-12
 *
 *   21 physical rows, 2 captions, 12 LOGICAL students.
 */
final class TwoLineHeaderTallStudentFixture
{
    public const COLUMN_COUNT = 15;

    public const PHYSICAL_ROW_COUNT = 21;

    /**
     * The first printed header row, column by column. Columns 2, 3, 4 and 11
     * («MU», «MS», «MA», «ATE») are merged DOWN into row 2 — see
     * headerRowspanColumns().
     *
     * @return list<string>
     */
    public static function headerLevelOne(): array
    {
        return [
            'Medidas', 'RTP', 'MU', 'MS', 'MA', 'Coadju',
            'Apoios', '', '', '',
            'Tuto-', 'ATE', 'Apoio Ed.', 'Psico', 'Outras medidas/ recursos',
        ];
    }

    /**
     * The second printed header row. Blank exactly where the row above
     * merged down into it, and in the «Apoios» columns it is the row above
     * that merged ACROSS (colspan 4) and this row that names them.
     *
     * @return list<string>
     */
    public static function headerLevelTwo(): array
    {
        return [
            '3.º ciclo', 'PEI', '', '', '', 'vação',
            'P', 'M', 'Ing.', 'Outros',
            'ria', '', 'Especial', 'logia', 'Observações',
        ];
    }

    /**
     * The 0-based columns whose first-row cell is merged down into row 2.
     *
     * @return list<int>
     */
    public static function headerRowspanColumns(): array
    {
        return [2, 3, 4, 11];
    }

    public static function groupCaptionWithRtp(): string
    {
        return 'Alunos com RTP';
    }

    public static function groupCaptionWithoutRtp(): string
    {
        return 'Alunos sem RTP';
    }

    /**
     * The first student — ONE logical row printed across FOUR. Column 3
     * («MS») and column 14 («Observações») carry one line per printed row;
     * every other column is a single merged cell spanning all four.
     *
     * @return array{merged: list<string>, tallColumns: array<int, list<string>>}
     */
    public static function tallStudent(): array
    {
        return [
            'merged' => [
                '15 - Alda Varela', 'X', 'MU a) b)', '', 'MA c)', 'CRI',
                'P', '', '', '', 'X', '', 'X', 'SPO', '',
            ],
            'tallColumns' => [
                3 => ['MS a)', 'MS b)', 'c)', 'd)'],
                14 => [
                    'Observacao inventada um.',
                    'Observacao inventada dois.',
                    'Observacao inventada tres.',
                    'Observacao inventada quatro, mais longa do que as outras.',
                ],
            ],
        ];
    }

    /**
     * The MS and Observações values the tall student must end up with once
     * her four printed rows are folded back into one — the four lines
     * joined, in order, nothing lost.
     */
    public static function tallStudentMeasures(): string
    {
        return implode("\n", self::tallStudent()['tallColumns'][3]);
    }

    public static function tallStudentObservations(): string
    {
        return implode("\n", self::tallStudent()['tallColumns'][14]);
    }

    /**
     * The eleven students below the second caption. A `tallColumns` key
     * means that student is printed across two rows with only that column
     * split — the shape rows 10-11 and 13-14 have in the real workbook.
     *
     * @return list<array{merged: list<string>, tallColumns: array<int, list<string>>}>
     */
    public static function studentsWithoutRtp(): array
    {
        return [
            self::plain('01 - Bento Quaresma', ['X', 'MU a)', '', '', 'CRI', 'P', '', '', '', '', '', '', '', '']),
            [
                'merged' => ['02 - Carla Peixoto', 'X', 'MU b)', 'MS a)', '', '', 'P', 'M', '', '', 'X', '', '', '', ''],
                'tallColumns' => [11 => ['ATE 1.º per.', 'ATE 2.º per.']],
            ],
            self::plain('03 - Duarte Alves', ['', 'MU c)', '', '', '', '', '', 'Ing.', '', '', '', '', '', '']),
            [
                'merged' => ['04 - Eva Soares', 'X', 'MU a)', 'MS c)', '', 'CRI', '', '', '', 'Outros', '', '', '', '', ''],
                'tallColumns' => [11 => ['ATE 1.º per.', 'ATE 3.º per.']],
            ],
            self::plain('06 - Filipe Rego', ['', 'MU a)', '', '', '', '', '', '', '', '', '', '', '', '']),
            self::plain('08 - Gabriela Pina', ['', 'MU b)', '', '', '', '', '', '', '', '', '', '', '', '']),
            self::plain('11 - Hugo Barata', ['X', 'MU a)', 'MS b)', 'MA a)', 'CRI', 'P', 'M', 'Ing.', 'Outros', 'X', 'X', 'X', 'SPO', 'Observacao inventada cinco.']),
            self::plain('13 - Ines Correia', ['X', 'MU c)', 'MS a)', '', 'CRI', '', 'M', '', 'Outros', 'X', '', 'X', '', 'Observacao inventada seis.']),
            self::plain('16 - Joana Lemos', ['', 'MU a)', '', '', '', 'P', '', '', '', '', '', '', '', '']),
            self::plain('18 - Kevin Dias', ['', 'MU b)', '', '', '', '', '', '', '', '', '', '', '', '']),
            self::plain('19 - Luisa Prado', ['', 'MU a)', '', '', '', '', '', '', '', '', '', '', '', '']),
        ];
    }

    /**
     * Every logical student's name, in the order the table prints them —
     * twelve of them, the tall one first.
     *
     * @return list<string>
     */
    public static function studentNames(): array
    {
        return array_map(
            fn (array $student): string => $student['merged'][0],
            [self::tallStudent(), ...self::studentsWithoutRtp()],
        );
    }

    /**
     * @param  list<string>  $rest  the fourteen columns after the name
     * @return array{merged: list<string>, tallColumns: array<int, list<string>>}
     */
    private static function plain(string $name, array $rest): array
    {
        return ['merged' => [$name, ...$rest], 'tallColumns' => []];
    }

    // ------------------------------------------------------------------
    // .xlsx — a real workbook, with the real workbook's merges.
    // ------------------------------------------------------------------

    public static function writeXlsx(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Medidas');

        $levelOne = self::headerLevelOne();
        $levelTwo = self::headerLevelTwo();
        $rowspanColumns = self::headerRowspanColumns();

        foreach ($levelOne as $column => $value) {
            if ($value === '') {
                continue; // covered by the «Apoios» colspan.
            }

            $letter = self::letter($column);
            $sheet->setCellValue($letter.'1', $value);

            if ($column === 6) {
                $sheet->mergeCells($letter.'1:'.self::letter(9).'1');
            } elseif (in_array($column, $rowspanColumns, true)) {
                $sheet->mergeCells($letter.'1:'.$letter.'2');
            }
        }

        foreach ($levelTwo as $column => $value) {
            if ($value === '') {
                continue;
            }

            $sheet->setCellValue(self::letter($column).'2', $value);
        }

        $row = 3;
        $row = self::writeCaption($sheet, self::groupCaptionWithRtp(), $row);
        $row = self::writeStudent($sheet, self::tallStudent(), $row, 4);
        $row = self::writeCaption($sheet, self::groupCaptionWithoutRtp(), $row);

        foreach (self::studentsWithoutRtp() as $student) {
            $row = self::writeStudent($sheet, $student, $row, $student['tallColumns'] === [] ? 1 : 2);
        }

        (new XlsxWriter($spreadsheet))->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    private static function writeCaption(mixed $sheet, string $caption, int $row): int
    {
        $sheet->setCellValue('A'.$row, $caption);
        $sheet->mergeCells('A'.$row.':'.self::letter(self::COLUMN_COUNT - 1).$row);

        return $row + 1;
    }

    /**
     * @param  array{merged: list<string>, tallColumns: array<int, list<string>>}  $student
     */
    private static function writeStudent(mixed $sheet, array $student, int $row, int $height): int
    {
        foreach ($student['merged'] as $column => $value) {
            if (isset($student['tallColumns'][$column])) {
                continue;
            }

            $letter = self::letter($column);

            if ($value !== '') {
                $sheet->setCellValue($letter.$row, $value);
            }

            if ($height > 1) {
                $sheet->mergeCells($letter.$row.':'.$letter.($row + $height - 1));
            }
        }

        foreach ($student['tallColumns'] as $column => $lines) {
            foreach ($lines as $offset => $line) {
                $sheet->setCellValue(self::letter($column).($row + $offset), $line);
            }
        }

        return $row + $height;
    }

    private static function letter(int $zeroBasedIndex): string
    {
        return Coordinate::stringFromColumnIndex($zeroBasedIndex + 1);
    }

    // ------------------------------------------------------------------
    // Clipboard HTML — the same table as Word puts it on the clipboard,
    // rowspan for rowspan.
    // ------------------------------------------------------------------

    public static function toWordClipboardHtml(): string
    {
        $levelOne = self::headerLevelOne();
        $levelTwo = self::headerLevelTwo();
        $rowspanColumns = self::headerRowspanColumns();

        $topCells = [];

        foreach ($levelOne as $column => $value) {
            if ($value === '') {
                continue;
            }

            $attributes = '';

            if ($column === 6) {
                $attributes = ' colspan="4"';
            } elseif (in_array($column, $rowspanColumns, true)) {
                $attributes = ' rowspan="2"';
            }

            $topCells[] = '<td'.$attributes.'>'.htmlspecialchars($value, ENT_QUOTES).'</td>';
        }

        $bottomCells = [];

        foreach ($levelTwo as $column => $value) {
            if (in_array($column, $rowspanColumns, true)) {
                continue; // the rowspan above already occupies this cell.
            }

            $bottomCells[] = '<td>'.htmlspecialchars($value, ENT_QUOTES).'</td>';
        }

        $rows = [
            '<tr>'.implode('', $topCells).'</tr>',
            '<tr>'.implode('', $bottomCells).'</tr>',
            self::htmlCaption(self::groupCaptionWithRtp()),
            ...self::htmlStudent(self::tallStudent(), 4),
            self::htmlCaption(self::groupCaptionWithoutRtp()),
        ];

        foreach (self::studentsWithoutRtp() as $student) {
            $rows = [...$rows, ...self::htmlStudent($student, $student['tallColumns'] === [] ? 1 : 2)];
        }

        $body = implode("\n", $rows);

        return <<<HTML
            <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">
            <body>
            <table>
            {$body}
            </table>
            </body>
            </html>
            HTML;
    }

    private static function htmlCaption(string $caption): string
    {
        return '<tr><td colspan="'.self::COLUMN_COUNT.'">'.htmlspecialchars($caption, ENT_QUOTES).'</td></tr>';
    }

    /**
     * @param  array{merged: list<string>, tallColumns: array<int, list<string>>}  $student
     * @return list<string>
     */
    private static function htmlStudent(array $student, int $height): array
    {
        $rows = [];

        for ($line = 0; $line < $height; $line++) {
            $cells = [];

            foreach ($student['merged'] as $column => $value) {
                if (isset($student['tallColumns'][$column])) {
                    $cells[] = '<td>'.htmlspecialchars($student['tallColumns'][$column][$line] ?? '', ENT_QUOTES).'</td>';

                    continue;
                }

                if ($line > 0) {
                    continue; // the rowspan emitted on the first line covers this cell.
                }

                $attributes = $height > 1 ? ' rowspan="'.$height.'"' : '';
                $cells[] = '<td'.$attributes.'>'.htmlspecialchars($value, ENT_QUOTES).'</td>';
            }

            $rows[] = '<tr>'.implode('', $cells).'</tr>';
        }

        return $rows;
    }
}
