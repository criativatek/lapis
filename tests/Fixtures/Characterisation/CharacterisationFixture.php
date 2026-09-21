<?php

namespace Tests\Fixtures\Characterisation;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\Word2007 as Word2007Writer;

/**
 * ONE fictional pedagogical characterisation dataset, expressed in every
 * format the import feature accepts (image fixtures excepted — see the note
 * on that below). Every render method here derives from the SAME arrays
 * (self::HEADERS_FLAT, self::studentsWithRtp(), …), on purpose: two
 * hand-written fixtures claiming to be "the same table" always drift apart
 * the first time one of them is edited and the other is forgotten. There is
 * exactly one definition of the dataset in this file, and everything else in
 * it is a rendering of that definition.
 *
 * NEVER A REAL STUDENT. Every name below is invented — common Portuguese
 * given names paired with surnames that do not, between them, spell out any
 * actual pupil this application has ever held data about. This is a
 * data-protection rule, not a style preference: a fixture with a real
 * child's name in it is an incident, not a test detail.
 *
 * COLUMN ORDER (15 columns, matching the paper form this feature replaces):
 * Aluno | RTP/PEI | MU | MS | MA | Coadjuvação | Apoio P | Apoio M |
 * Apoio Ing. | Apoio Outros | Tutoria | ATE | Apoio Ed. Especial |
 * Psicologia | Outras medidas/recursos / Observações
 *
 * THE HEADER JOIN IS DELIBERATELY ASYMMETRIC, and that is documented here
 * rather than smoothed over: the four "Apoio" columns are named so that a
 * merged top-level "Apoio" cell over "P"/"M"/"Ing."/"Outros" joins (see
 * NormaliseExtractedTable::joinHeaderLevels) to EXACTLY the same string the
 * flat CSV/TSV header already uses — "Apoio Ing." both ways. The "Medidas"
 * group over MU/MS/MA is NOT symmetric this way: the flat header is the bare
 * "MU", but a merged format would join it to "Medidas MU". That mismatch is
 * real, not a bug in this fixture — it is why FormatConvergenceTest matches
 * the MU/MS/MA columns by substring rather than exact equality, with a
 * comment at the point it does so.
 *
 * IMAGE FIXTURES ARE OUT OF SCOPE HERE. OCR is a later slice; when it lands,
 * an image rendering of this exact dataset belongs in this class too, as
 * e.g. `writePng(string $path)`, so the convergence test can grow one more
 * `assertConverges()` call rather than a parallel dataset.
 */
final class CharacterisationFixture
{
    /**
     * The flat, already-joined header labels — what TSV/CSV carry directly,
     * and what a merged format's "Apoio …" columns join back down to.
     *
     * @return list<string>
     */
    public static function headersFlat(): array
    {
        return [
            'Aluno',
            'RTP/PEI',
            'MU',
            'MS',
            'MA',
            'Coadjuvação',
            'Apoio P',
            'Apoio M',
            'Apoio Ing.',
            'Apoio Outros',
            'Tutoria',
            'ATE',
            'Apoio Ed. Especial',
            'Psicologia',
            'Outras medidas/recursos / Observações',
        ];
    }

    /**
     * The bottom level of the two-row header used by the Word/Excel/Sheets
     * clipboard, the .docx and the .xlsx: the column's own name where it
     * carries no group, or the short label that sits under a merged group
     * cell (see headerTopLevelMerges()).
     *
     * @return list<string>
     */
    public static function headerSubLevel(): array
    {
        return [
            'Aluno',
            'RTP/PEI',
            'MU',
            'MS',
            'MA',
            'Coadjuvação',
            'P',
            'M',
            'Ing.',
            'Outros',
            'Tutoria',
            'ATE',
            'Apoio Ed. Especial',
            'Psicologia',
            'Outras medidas/recursos / Observações',
        ];
    }

    /**
     * The merged cells making up the top row of the two-row header,
     * 0-indexed. "Medidas" spans MU/MS/MA (columns 2-4); "Apoio" spans the
     * four Apoio columns (columns 6-9). Every other top-row cell is blank.
     *
     * @return list<array{column: int, span: int, label: string}>
     */
    public static function headerTopLevelMerges(): array
    {
        return [
            ['column' => 2, 'span' => 3, 'label' => 'Medidas'],
            ['column' => 6, 'span' => 4, 'label' => 'Apoio'],
        ];
    }

    public static function columnCount(): int
    {
        return count(self::headersFlat());
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
     * Four students under «Alunos com RTP». Between them these carry every
     * token the brief calls for: "MU a) b) e)", "MS b) ACNS", "MS c) d)", an
     * "MA" case, "CRI", "SPO", "X", "M", "1R 25/26", "Particular", "PLNM" —
     * plus the multiline observation the task requires (Tiago Nogueira's).
     *
     * @return list<list<string>> each row already in column order, matching headersFlat()
     */
    public static function studentsWithRtp(): array
    {
        return [
            [
                'Mariana Fonseca', 'X', 'MU a) b) e)', '', '', 'CRI',
                '', 'M', '', '', '', 'X', '', 'SPO',
                'Observação simples sobre o aluno.',
            ],
            [
                'Tiago Nogueira', 'X', '', 'MS b) ACNS', '', '',
                '1R 25/26', '', '', 'Particular', 'X', '', 'X', '',
                "Primeira linha da observação.\nSegunda linha da observação.",
            ],
            [
                'Beatriz Carvalho', 'X', '', 'MS c) d)', '', '',
                '', '', 'PLNM', '', '', '', '', '',
                '',
            ],
            [
                'Rodrigo Esteves', 'X', '', '', 'MA a)', '',
                '', '', '', '', '', '', '', '',
                '',
            ],
        ];
    }

    /**
     * Two students under «Alunos sem RTP». Leonor Machado is the deliberate
     * common case: a name and NOTHING else — every other cell empty — which
     * is the row most likely to be dropped by a careless extractor.
     *
     * @return list<list<string>>
     */
    public static function studentsWithoutRtp(): array
    {
        return [
            [
                'Leonor Machado', '', '', '', '', '',
                '', '', '', '', '', '', '', '',
                '',
            ],
            [
                'Guilherme Antunes', '', '', '', '', '',
                '', '', '', '', '', '', '', '',
                'Aluno novo este ano letivo.',
            ],
        ];
    }

    /**
     * Every student name, across both groups, in the order the whole table
     * lists them — the canonical order FormatConvergenceTest checks every
     * format reproduces.
     *
     * @return list<string>
     */
    public static function studentNames(): array
    {
        return array_map(
            fn (array $row) => $row[0],
            [...self::studentsWithRtp(), ...self::studentsWithoutRtp()],
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function allStudentRows(): array
    {
        return [...self::studentsWithRtp(), ...self::studentsWithoutRtp()];
    }

    /**
     * The trailing legend, two lines, each one a " - " glossary pair so
     * NormaliseExtractedTable::looksLikeLegend() catches both no matter which
     * format carried them.
     *
     * @return list<string>
     */
    public static function legendLines(): array
    {
        return [
            'X (continua) - N (novo)',
            'Coadjuvação - trabalho articulado em sala de aula; ATE - assistente técnico de educação; Psicologia/Terapias - apoio de psicologia ou terapias externas.',
        ];
    }

    // ------------------------------------------------------------------
    // TSV / CSV — single-level header, group captions and legend arrive as
    // ordinary rows with one non-empty cell, exactly what a real copy from a
    // spreadsheet produces.
    // ------------------------------------------------------------------

    public static function toTsv(): string
    {
        return self::delimited("\t");
    }

    public static function toCsv(): string
    {
        return self::delimited(',');
    }

    private static function delimited(string $delimiter): string
    {
        $lines = [];
        $lines[] = self::delimitedRow(self::headersFlat(), $delimiter);

        $lines[] = self::delimitedRow(self::captionRow(self::groupCaptionWithRtp()), $delimiter);

        foreach (self::studentsWithRtp() as $row) {
            $lines[] = self::delimitedRow($row, $delimiter);
        }

        $lines[] = self::delimitedRow(self::captionRow(self::groupCaptionWithoutRtp()), $delimiter);

        foreach (self::studentsWithoutRtp() as $row) {
            $lines[] = self::delimitedRow($row, $delimiter);
        }

        foreach (self::legendLines() as $legendLine) {
            $lines[] = self::delimitedRow(self::captionRow($legendLine), $delimiter);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * A caption occupies column 0 only; every other column is present but
     * empty, which is exactly the shape a teacher's spreadsheet produces
     * when a caption is typed into the name column and nothing else.
     *
     * @return list<string>
     */
    private static function captionRow(string $caption): array
    {
        $row = array_fill(0, self::columnCount(), '');
        $row[0] = $caption;

        return $row;
    }

    /**
     * @param  list<string>  $fields
     */
    private static function delimitedRow(array $fields, string $delimiter): string
    {
        return implode($delimiter, array_map(
            fn (string $field) => self::quoteIfNeeded($field, $delimiter),
            $fields,
        ));
    }

    private static function quoteIfNeeded(string $field, string $delimiter): string
    {
        if (str_contains($field, $delimiter) || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"'.str_replace('"', '""', $field).'"';
        }

        return $field;
    }

    // ------------------------------------------------------------------
    // Clipboard HTML — each application's own flavour of what it puts
    // alongside the plain-text paste. Deliberately dirty: mso-* styling,
    // <o:p>, conditional comments, a google-sheets-html-origin marker —
    // because surviving that mess is the extractor's actual job.
    // ------------------------------------------------------------------

    public static function toWordClipboardHtml(): string
    {
        $headerRows = self::htmlHeaderRows(paragraphWrap: true);
        $body = self::htmlBodyRows(paragraphWrap: true, cellTag: 'td');

        return <<<HTML
            <html xmlns:o="urn:schemas-microsoft-com:office:office"
                  xmlns:w="urn:schemas-microsoft-com:office:word"
                  xmlns="http://www.w3.org/TR/REC-html40">
            <head>
            <meta charset="utf-8">
            <!--[if gte mso 9]><xml>
             <w:WordDocument>
              <w:View>Normal</w:View>
              <w:TrackChanges/>
             </w:WordDocument>
            </xml><![endif]-->
            <style>
            <!--
             @page Section1 {size:842.0pt 595.0pt; mso-page-orientation:landscape;}
             span.SpellE {mso-style-name:""; mso-spl-e:yes;}
             table.MsoTableGrid {mso-table-lspace:0pt; mso-table-rspace:0pt;}
            -->
            </style>
            </head>
            <body lang=PT style='tab-interval:35.4pt'>
            <div class=WordSection1>
            <table class=MsoTableGrid border=1 cellspacing=0 cellpadding=0
             style='border-collapse:collapse;border:none;mso-border-alt:solid windowtext .5pt;
             mso-yfti-tbllook:1184;mso-padding-alt:0cm 5.4pt 0cm 5.4pt'>
            {$headerRows}
            {$body}
            </table>
            <p class=MsoNormal><o:p>&nbsp;</o:p></p>
            </div>
            </body>
            </html>
            HTML;
    }

    public static function toExcelClipboardHtml(): string
    {
        $headerRows = self::htmlHeaderRows(paragraphWrap: false, cellClass: 'xl65');
        $body = self::htmlBodyRows(paragraphWrap: false, cellTag: 'td', cellClass: 'xl65');

        return <<<HTML
            <html xmlns:v="urn:schemas-microsoft-com:vml"
                  xmlns:o="urn:schemas-microsoft-com:office:office"
                  xmlns:x="urn:schemas-microsoft-com:office:excel"
                  xmlns="http://www.w3.org/TR/REC-html40">
            <head>
            <meta charset="utf-8">
            <!--[if gte mso 9]><xml>
             <x:ExcelWorkbook>
              <x:ExcelWorksheets>
               <x:ExcelWorksheet>
                <x:Name>Caracterização</x:Name>
                <x:WorksheetOptions></x:WorksheetOptions>
               </x:ExcelWorksheet>
              </x:ExcelWorksheets>
             </x:ExcelWorkbook>
            </xml><![endif]-->
            <style>
            <!--table {mso-displayed-decimal-separator:"\\,"; mso-displayed-thousand-separator:"\\.";}
            .xl65 {mso-number-format:"@"; text-align:general;}
            -->
            </style>
            </head>
            <body link="#0563C1" vlink="#954F72">
            <table border=0 cellpadding=0 cellspacing=0 width=1900 style='border-collapse:
             collapse;table-layout:fixed;width:1425pt'>
            <col width=180 style='mso-width-source:userset;width:135pt'>
            {$headerRows}
            {$body}
            </table>
            </body>
            </html>
            HTML;
    }

    public static function toGoogleSheetsClipboardHtml(): string
    {
        $headerRows = self::htmlHeaderRows(paragraphWrap: false);
        $body = self::htmlBodyRows(paragraphWrap: false, cellTag: 'td');

        return <<<HTML
            <google-sheets-html-origin>
            <style type="text/css"><!--td {border: 1px solid #ccc;}br {mso-data-placement:same-cell;}--></style>
            <table xmlns="http://www.w3.org/1999/xhtml" cellspacing="0" cellpadding="0" dir="ltr"
             border="1" style="table-layout:fixed;font-size:10pt;font-family:Arial;width:0px;
             border-collapse:collapse;border:none">
            <tbody>
            {$headerRows}
            {$body}
            </tbody>
            </table>
            HTML;
    }

    /**
     * The two-row header shared by all three clipboard flavours: a merged
     * top row ("Medidas", "Apoio") over a full bottom row of short labels.
     */
    private static function htmlHeaderRows(bool $paragraphWrap, ?string $cellClass = null): string
    {
        $classAttr = $cellClass !== null ? " class={$cellClass}" : '';

        $topCells = [];
        $skipUntil = -1;

        for ($column = 0; $column < self::columnCount(); $column++) {
            if ($column <= $skipUntil) {
                continue;
            }

            $merge = self::mergeStartingAt($column);

            if ($merge !== null) {
                $topCells[] = '<td'.$classAttr.' colspan="'.$merge['span'].'">'.self::htmlCell($merge['label'], $paragraphWrap).'</td>';
                $skipUntil = $column + $merge['span'] - 1;

                continue;
            }

            $topCells[] = '<td'.$classAttr.'>'.self::htmlCell('', $paragraphWrap).'</td>';
        }

        $bottomCells = array_map(
            fn (string $label) => '<td'.$classAttr.'>'.self::htmlCell($label, $paragraphWrap).'</td>',
            self::headerSubLevel(),
        );

        return '<tr>'.implode('', $topCells)."</tr>\n".'<tr>'.implode('', $bottomCells).'</tr>';
    }

    /**
     * @return array{span: int, label: string}|null
     */
    private static function mergeStartingAt(int $column): ?array
    {
        foreach (self::headerTopLevelMerges() as $merge) {
            if ($merge['column'] === $column) {
                return ['span' => $merge['span'], 'label' => $merge['label']];
            }
        }

        return null;
    }

    private static function htmlBodyRows(bool $paragraphWrap, string $cellTag, ?string $cellClass = null): string
    {
        $rows = [];

        $rows[] = self::htmlCaptionRow(self::groupCaptionWithRtp(), $paragraphWrap, $cellTag);

        foreach (self::studentsWithRtp() as $studentRow) {
            $rows[] = self::htmlDataRow($studentRow, $paragraphWrap, $cellTag, $cellClass);
        }

        $rows[] = self::htmlCaptionRow(self::groupCaptionWithoutRtp(), $paragraphWrap, $cellTag);

        foreach (self::studentsWithoutRtp() as $studentRow) {
            $rows[] = self::htmlDataRow($studentRow, $paragraphWrap, $cellTag, $cellClass);
        }

        foreach (self::legendLines() as $legendLine) {
            $rows[] = self::htmlLegendRow($legendLine, $paragraphWrap, $cellTag);
        }

        return implode("\n", $rows);
    }

    /**
     * A group caption: ONE cell, colspan across the full table width — the
     * structural shape NormaliseExtractedTable::structuralGroupRowNumbers()
     * looks for, before anything is expanded.
     */
    private static function htmlCaptionRow(string $caption, bool $paragraphWrap, string $cellTag): string
    {
        return '<tr><'.$cellTag.' colspan="'.self::columnCount().'">'.self::htmlCell($caption, $paragraphWrap).'</'.$cellTag.'></tr>';
    }

    /**
     * The legend carries no merge — it is an ordinary row whose one non-empty
     * cell is caught by NormaliseExtractedTable::looksLikeLegend() on
     * content (the " - " pair), same as the TSV/CSV shape.
     */
    private static function htmlLegendRow(string $legendLine, bool $paragraphWrap, string $cellTag): string
    {
        $cells = ['<'.$cellTag.'>'.self::htmlCell($legendLine, $paragraphWrap).'</'.$cellTag.'>'];

        for ($column = 1; $column < self::columnCount(); $column++) {
            $cells[] = '<'.$cellTag.'>'.self::htmlCell('', $paragraphWrap).'</'.$cellTag.'>';
        }

        return '<tr>'.implode('', $cells).'</tr>';
    }

    /**
     * @param  list<string>  $row
     */
    private static function htmlDataRow(array $row, bool $paragraphWrap, string $cellTag, ?string $cellClass = null): string
    {
        $classAttr = $cellClass !== null ? " class={$cellClass}" : '';

        $cells = array_map(
            fn (string $value) => '<'.$cellTag.$classAttr.'>'.self::htmlCell($value, $paragraphWrap).'</'.$cellTag.'>',
            $row,
        );

        return '<tr>'.implode('', $cells).'</tr>';
    }

    /**
     * A cell's inner markup. Word wraps every paragraph in <p class=MsoNormal>
     * with a trailing <o:p></o:p> — which is how Word's OWN clipboard HTML
     * carries a multiline observation, one <p> per line — while Excel and
     * Google Sheets instead put a bare <br> between lines. Both shapes join
     * back to a single "\n" through HtmlTableExtractor::textOf(), which is
     * exactly the point being proven.
     */
    private static function htmlCell(string $text, bool $paragraphWrap): string
    {
        if ($text === '') {
            return $paragraphWrap ? '<p class=MsoNormal><o:p></o:p></p>' : '';
        }

        $lines = explode("\n", $text);
        $encoded = array_map(fn (string $line) => htmlspecialchars($line, ENT_QUOTES), $lines);

        if ($paragraphWrap) {
            return implode('', array_map(
                fn (string $line) => '<p class=MsoNormal>'.$line.'<o:p></o:p></p>',
                $encoded,
            ));
        }

        return implode('<br>', $encoded);
    }

    // ------------------------------------------------------------------
    // .docx — a real OOXML document, built with phpoffice/phpword (already a
    // dependency: DocxRenderer and the correction-grid importer both use
    // it), with w:gridSpan actually present on the merged header/caption
    // cells. No vertical merge appears in this dataset — nothing here needs
    // one — so no w:vMerge cell is manufactured just to tick a box; gridSpan
    // is the merge feature this dataset genuinely exercises.
    // ------------------------------------------------------------------

    public static function writeDocx(string $path): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection(['orientation' => 'landscape']);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);

        $columnWidth = 900;

        // Top header row: blanks, with "Medidas" and "Apoio" spanning their
        // columns via w:gridSpan — a real merged cell, not a wide one.
        $table->addRow();

        for ($column = 0; $column < self::columnCount(); $column++) {
            $merge = self::mergeStartingAt($column);

            if ($merge !== null) {
                $table->addCell($columnWidth * $merge['span'], ['gridSpan' => $merge['span']])
                    ->addText($merge['label'], ['bold' => true]);
                $column += $merge['span'] - 1;

                continue;
            }

            $table->addCell($columnWidth)->addText('');
        }

        // Bottom header row: every column's own short label.
        $table->addRow();

        foreach (self::headerSubLevel() as $label) {
            $table->addCell($columnWidth)->addText($label, ['bold' => true]);
        }

        self::writeDocxCaptionRow($table, self::groupCaptionWithRtp(), $columnWidth);

        foreach (self::studentsWithRtp() as $studentRow) {
            self::writeDocxDataRow($table, $studentRow, $columnWidth);
        }

        self::writeDocxCaptionRow($table, self::groupCaptionWithoutRtp(), $columnWidth);

        foreach (self::studentsWithoutRtp() as $studentRow) {
            self::writeDocxDataRow($table, $studentRow, $columnWidth);
        }

        foreach (self::legendLines() as $legendLine) {
            self::writeDocxLegendRow($table, $legendLine, $columnWidth);
        }

        (new Word2007Writer($phpWord))->save($path);

        // Releases this fixture's heavy objects explicitly, the same
        // housekeeping writeXlsx() already does with disconnectWorksheets()
        // and for the same shape of reason: PhpWord's
        // document/section/table/row/cell graph holds parent references back
        // up the tree, so those objects form cycles that leaving scope does
        // not free — only the cycle collector does. The whole suite runs in
        // ONE PHP process and this fixture is rebuilt for every test that
        // uses it, so each document would otherwise stay resident for the
        // rest of the run with nothing pointing at it.
        //
        // That is the entire claim. This is not a fix for anything: no
        // measurement here attributes any behaviour of the suite to these two
        // lines.
        unset($table, $section, $phpWord);
        gc_collect_cycles();
    }

    /**
     * @param  Table  $table
     */
    private static function writeDocxCaptionRow($table, string $caption, int $columnWidth): void
    {
        $table->addRow();
        $table->addCell($columnWidth * self::columnCount(), ['gridSpan' => self::columnCount()])
            ->addText($caption, ['bold' => true]);
    }

    /**
     * @param  Table  $table
     */
    private static function writeDocxLegendRow($table, string $legendLine, int $columnWidth): void
    {
        $table->addRow();
        $table->addCell($columnWidth)->addText($legendLine);

        for ($column = 1; $column < self::columnCount(); $column++) {
            $table->addCell($columnWidth)->addText('');
        }
    }

    /**
     * @param  Table  $table
     * @param  list<string>  $row
     */
    private static function writeDocxDataRow($table, array $row, int $columnWidth): void
    {
        $table->addRow();

        foreach ($row as $value) {
            $cell = $table->addCell($columnWidth);

            // Each line of a multiline observation is its own w:p — joined
            // back with "\n" by DocxTableExtractor::cellText(), the same way
            // a real Word table stores a paragraph break inside a cell.
            $lines = $value === '' ? [''] : explode("\n", $value);

            foreach ($lines as $line) {
                $cell->addText($line);
            }
        }
    }

    // ------------------------------------------------------------------
    // .xlsx — built with phpoffice/phpspreadsheet (already a dependency),
    // with the same two-row header, this time merged with mergeCells().
    // ------------------------------------------------------------------

    public static function writeXlsx(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Caracterização');

        $columnCount = self::columnCount();

        // Row 1: top-level header, with "Medidas" and "Apoio" merged across
        // their columns.
        foreach (self::headerTopLevelMerges() as $merge) {
            $startLetter = self::columnLetter($merge['column']);
            $endLetter = self::columnLetter($merge['column'] + $merge['span'] - 1);
            $sheet->setCellValue($startLetter.'1', $merge['label']);
            $sheet->mergeCells($startLetter.'1:'.$endLetter.'1');
        }

        // Row 2: every column's own short label.
        foreach (self::headerSubLevel() as $index => $label) {
            $sheet->setCellValue(self::columnLetter($index).'2', $label);
        }

        $row = 3;

        $row = self::writeXlsxCaptionRow($sheet, self::groupCaptionWithRtp(), $row, $columnCount);

        foreach (self::studentsWithRtp() as $studentRow) {
            $row = self::writeXlsxDataRow($sheet, $studentRow, $row);
        }

        $row = self::writeXlsxCaptionRow($sheet, self::groupCaptionWithoutRtp(), $row, $columnCount);

        foreach (self::studentsWithoutRtp() as $studentRow) {
            $row = self::writeXlsxDataRow($sheet, $studentRow, $row);
        }

        foreach (self::legendLines() as $legendLine) {
            $row = self::writeXlsxCaptionRow($sheet, $legendLine, $row, 1);
        }

        (new XlsxWriter($spreadsheet))->save($path);

        // Same reason IntuitivoWorkbookBuilder disconnects: cells hold a
        // reference back to the worksheet/workbook, and a suite building
        // several of these per run should not carry every one of them until
        // the memory limit says stop.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    /**
     * A group caption merged across the full table width — the same
     * structural shape the .docx and the clipboard HTML use, this time via
     * PhpSpreadsheet's mergeCells(). Passing $span = 1 (as the legend rows
     * do) writes an ordinary unmerged single-column row instead, matching
     * the legend's shape everywhere else: caught by content, not structure.
     */
    private static function writeXlsxCaptionRow($sheet, string $caption, int $row, int $span): int
    {
        $sheet->setCellValue('A'.$row, $caption);

        if ($span > 1) {
            $sheet->mergeCells('A'.$row.':'.self::columnLetter($span - 1).$row);
        }

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
