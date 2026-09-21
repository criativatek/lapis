<?php

namespace Tests\Fixtures\Characterisation;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * The realistic shape §… (JANELA J) was built to fix: a title row above the
 * table, a TWO-LEVEL header whose own NAME COLUMN PRINTS NO LABEL AT ALL
 * (empty, rowspan 2 — this is what reproduces the "0 of 0 columns
 * recognised" defect: the true header row has no identifying column of its
 * own to score on), a colspan-4 "Apoios" group splitting into P/M/Ing./
 * Outros one printed row down, TWO full-width group captions ("Alunos com
 * RTP" / "Alunos sem RTP") each naming a few students, and a trailing
 * legend.
 *
 * ONE definition of the dataset, rendered two ways — a real .xlsx via
 * PhpSpreadsheet's mergeCells() and the HTML a Word clipboard paste carries
 * — so a test proving the fix holds for one format is proven for the other
 * from the SAME source of truth, exactly as CharacterisationFixture already
 * does for its own (different, single-header-row) shape.
 *
 * NEVER A REAL STUDENT — see CharacterisationFixture's own docblock for why
 * that is a data-protection rule, not a style preference; the same rule
 * applies here.
 */
final class MultilevelHeaderFixture
{
    public static function titleRow(): string
    {
        return 'Medidas 3.º ciclo';
    }

    /**
     * The bottom-level label per column — what a column's cell actually
     * prints on the SECOND header row, for the four columns that own one
     * (the "Apoios" sub-columns); every other column's own cell on this row
     * is blank, because that column's real label sits on the row above with
     * rowspan 2 (or, for column 0, sits nowhere at all — see the class
     * docblock).
     *
     * @return list<string>
     */
    public static function headerSubLevel(): array
    {
        return ['', '', '', '', '', '', 'P', 'M', 'Ing.', 'Outros', '', '', '', '', ''];
    }

    /**
     * The top-level label per column, rowspan 2 for every column except the
     * "Apoios" group (colspan 4, split one row down) — and column 0, which
     * is EMPTY on both rows, on purpose: this is the shape that used to send
     * FindHeaderRow to the "Alunos com RTP" group row instead (see the
     * production class's own docblock for the mechanism).
     *
     * @return list<string>
     */
    public static function headerTopLevel(): array
    {
        return [
            '', 'RTP/PEI', 'MU', 'MS', 'MA', 'Coadjuvação',
            'Apoios', '', '', '',
            'Tutoria', 'ATE', 'Apoio Ed. Especial', 'Psicologia',
            'Outras medidas/recursos / Observações',
        ];
    }

    public static function columnCount(): int
    {
        return count(self::headerTopLevel());
    }

    /**
     * The final, expected column headers once the two levels are joined —
     * bare "MU"/"MS"/"MA" (they never had a "Medidas" group cell above them
     * in this shape, unlike CharacterisationFixture's), "Apoios P"/"Apoios
     * M"/"Apoios Ing."/"Apoios Outros" for the split group, everything else
     * unchanged. Column 0's header is deliberately left out of this list —
     * see the class docblock: what matters is that the OTHER 14 are correct
     * and the content columns are recognised, not what placeholder column 0
     * ends up labelled.
     *
     * @return list<string>
     */
    public static function expectedHeadersFromColumn1(): array
    {
        return [
            'RTP/PEI', 'MU', 'MS', 'MA', 'Coadjuvação',
            'Apoios P', 'Apoios M', 'Apoios Ing.', 'Apoios Outros',
            'Tutoria', 'ATE', 'Apoio Ed. Especial', 'Psicologia',
            'Outras medidas/recursos / Observações',
        ];
    }

    public static function groupCaptionWithRtp(): string
    {
        return 'Alunos com RTP';
    }

    public static function groupCaptionWithoutRtp(): string
    {
        return 'Alunos sem RTP';
    }

    public static function legendLine(): string
    {
        return 'X (continua) - N (novo)';
    }

    /**
     * @return list<list<string>> each row in column order, matching headerTopLevel()
     */
    public static function studentsWithRtp(): array
    {
        return [
            [
                'Mariana Fonseca', 'X', 'MU a) b) e)', '', '', 'CRI',
                '', 'M', '', '', '', 'X', '', 'SPO', 'Observação simples.',
            ],
            [
                'Tiago Nogueira', 'X', '', 'MS b) ACNS', '', '',
                '1R 25/26', '', '', 'Particular', 'X', '', 'X', '',
                "Primeira linha.\nSegunda linha.",
            ],
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function studentsWithoutRtp(): array
    {
        return [
            [
                'Leonor Machado', '', '', '', '', '',
                '', '', '', '', '', '', '', '', '',
            ],
            [
                'Guilherme Antunes', '', '', '', '', '',
                '', '', '', '', '', '', '', '', 'Aluno novo.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function studentNames(): array
    {
        return array_map(
            fn (array $row) => $row[0],
            [...self::studentsWithRtp(), ...self::studentsWithoutRtp()],
        );
    }

    // ------------------------------------------------------------------
    // Clipboard HTML — the Word flavour, real merges (rowspan on column 0's
    // header cells and every other single-level column, colspan on the
    // "Apoios" group and on each full-width caption/title).
    // ------------------------------------------------------------------

    public static function toWordClipboardHtml(): string
    {
        $top = self::headerTopLevel();
        $columnCount = self::columnCount();

        $topCells = [];
        $bottomCells = [];
        $column = 0;

        while ($column < $columnCount) {
            if ($column === 6) {
                // "Apoios" — colspan 4 on the top row, split into its four
                // sub-labels one row down.
                $topCells[] = '<td colspan="4">Apoios</td>';
                $bottomCells[] = '<td>P</td><td>M</td><td>Ing.</td><td>Outros</td>';
                $column += 4;

                continue;
            }

            // Every other column: rowspan 2 on the top row, and — critically
            // for column 0 — NO cell of its own on the bottom row at all,
            // exactly the shape a real rowspan produces (see
            // HtmlTableExtractor's own comment: a rowspan is never
            // re-emitted on the row it covers).
            $topCells[] = '<td rowspan="2">'.htmlspecialchars($top[$column], ENT_QUOTES).'</td>';
            $column++;
        }

        $headerHtml = '<tr>'.implode('', $topCells)."</tr>\n".'<tr>'.implode('', $bottomCells).'</tr>';

        $body = [];
        $body[] = self::htmlCaptionRow(self::groupCaptionWithRtp(), $columnCount);

        foreach (self::studentsWithRtp() as $row) {
            $body[] = self::htmlDataRow($row);
        }

        $body[] = self::htmlCaptionRow(self::groupCaptionWithoutRtp(), $columnCount);

        foreach (self::studentsWithoutRtp() as $row) {
            $body[] = self::htmlDataRow($row);
        }

        $body[] = self::htmlCaptionRow(self::legendLine(), $columnCount);

        $bodyHtml = implode("\n", $body);

        // Title row goes ABOVE the header, exactly as a real printed report
        // puts it — see FindHeaderRow's own docblock on why the header is
        // rarely row 1.
        $titleHtml = '<tr><td colspan="'.$columnCount.'">'.htmlspecialchars(self::titleRow(), ENT_QUOTES).'</td></tr>';

        return <<<HTML
            <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">
            <body>
            <table>
            {$titleHtml}
            {$headerHtml}
            {$bodyHtml}
            </table>
            </body>
            </html>
            HTML;
    }

    private static function htmlCaptionRow(string $text, int $columnCount): string
    {
        return '<tr><td colspan="'.$columnCount.'">'.htmlspecialchars($text, ENT_QUOTES).'</td></tr>';
    }

    /**
     * @param  list<string>  $row
     */
    private static function htmlDataRow(array $row): string
    {
        $cells = array_map(
            fn (string $value) => '<td>'.nl2br(htmlspecialchars($value, ENT_QUOTES)).'</td>',
            $row,
        );

        return '<tr>'.implode('', $cells).'</tr>';
    }

    /**
     * A clipboard-HTML title/group/legend row WITH A SPINE: a left "Turma"
     * column merged vertically down every row that follows it (rowspan
     * covering the whole class), so a full-width caption underneath still
     * owns only columnCount-1 cells of its own — see
     * NormaliseExtractedTable::wideCaptionCellOf()'s own docblock for the
     * defect this reproduces (a caption leaking through as Data) when that
     * method does not account for the spine.
     *
     * Deliberately narrow — two students, one group caption, one legend
     * line — this exists purely to prove the row COUNT: 2 real data rows,
     * never the 4 a spine used to produce (class name + caption + 2
     * students, with the caption counted as an extra "student").
     */
    public static function toSpineClipboardHtml(): string
    {
        $columnCount = 3; // Turma | Nome | Observações

        return <<<HTML
            <html>
            <body>
            <table>
            <tr><td>Turma</td><td>Nome</td><td>Observações</td></tr>
            <tr>
              <td rowspan="4">8.\u{ba} A</td>
              <td colspan="2">Alunos com RTP</td>
            </tr>
            <tr><td>Ana Silva</td><td>Participa</td></tr>
            <tr><td>Bruno Costa</td><td>Falta muito</td></tr>
            <tr><td colspan="2">X (continua) - N (novo)</td></tr>
            </table>
            </body>
            </html>
            HTML;
    }

    // ------------------------------------------------------------------
    // .xlsx — a real workbook via PhpSpreadsheet, mergeCells() for the
    // title, the header's rowspans/colspan and both group captions.
    // ------------------------------------------------------------------

    public static function writeXlsx(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Caracterização');

        $columnCount = self::columnCount();

        // Row 1: title, merged full width.
        $sheet->setCellValue('A1', self::titleRow());
        $sheet->mergeCells('A1:'.self::columnLetter($columnCount - 1).'1');

        // Rows 2-3: the two-level header.
        $top = self::headerTopLevel();
        $column = 0;

        while ($column < $columnCount) {
            $letter = self::columnLetter($column);

            if ($column === 6) {
                $sheet->setCellValue($letter.'2', 'Apoios');
                $sheet->mergeCells($letter.'2:'.self::columnLetter($column + 3).'2');

                foreach (['P', 'M', 'Ing.', 'Outros'] as $offset => $label) {
                    $sheet->setCellValue(self::columnLetter($column + $offset).'3', $label);
                }

                $column += 4;

                continue;
            }

            $sheet->setCellValue($letter.'2', $top[$column]);
            $sheet->mergeCells($letter.'2:'.$letter.'3');
            $column++;
        }

        $row = 4;

        $row = self::writeXlsxCaptionRow($sheet, self::groupCaptionWithRtp(), $row, $columnCount);

        foreach (self::studentsWithRtp() as $studentRow) {
            $row = self::writeXlsxDataRow($sheet, $studentRow, $row);
        }

        $row = self::writeXlsxCaptionRow($sheet, self::groupCaptionWithoutRtp(), $row, $columnCount);

        foreach (self::studentsWithoutRtp() as $studentRow) {
            $row = self::writeXlsxDataRow($sheet, $studentRow, $row);
        }

        self::writeXlsxCaptionRow($sheet, self::legendLine(), $row, $columnCount);

        (new XlsxWriter($spreadsheet))->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    private static function writeXlsxCaptionRow($sheet, string $caption, int $row, int $columnCount): int
    {
        $sheet->setCellValue('A'.$row, $caption);
        $sheet->mergeCells('A'.$row.':'.self::columnLetter($columnCount - 1).$row);

        return $row + 1;
    }

    /**
     * @param  list<string>  $studentRow
     */
    private static function writeXlsxDataRow($sheet, array $studentRow, int $row): int
    {
        foreach ($studentRow as $index => $value) {
            if ($value === '') {
                continue;
            }

            $sheet->setCellValue(self::columnLetter($index).$row, $value);
        }

        return $row + 1;
    }

    private static function columnLetter(int $zeroBasedIndex): string
    {
        return Coordinate::stringFromColumnIndex($zeroBasedIndex + 1);
    }
}
