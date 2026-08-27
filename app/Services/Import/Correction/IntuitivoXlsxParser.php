<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalGroup;
use App\Domain\Import\Correction\CanonicalInstrument;
use App\Domain\Import\Correction\CanonicalItem;
use App\Domain\Import\Correction\CanonicalResult;
use App\Domain\Import\Correction\CanonicalStudent;
use App\Domain\Import\Correction\CanonicalSummary;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportIssue;
use App\Domain\Import\Correction\IssueCode;
use App\Domain\Import\Correction\IssueSeverity;
use App\Support\Import\SpreadsheetZipSafety;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads the Intuitivo «Notas» export.
 *
 * The shape, as observed on a real export and reproduced by
 * `IntuitivoWorkbookBuilder`:
 *
 *      A                B .. F        G .. J      …        AA                  AB
 *  1   Nome do        [ GRUPO I ]  [ GRUPO II ]   …   Total (Máximo: 100)  Total (%)
 *      estudante       merged        merged                 merged            merged
 *  2      —          Item 1 (Máximo: 4.00)  …                 —                —
 *  3+  «nome»            0  4  …                            37.66            37.66%
 *
 * Two things carry the structure and both are load-bearing. The MERGED cells on
 * row 1 are the only statement of where a group begins and ends — there is no
 * other marker — and the per-question maximum lives inside the row 2 header
 * text, not in a cell of its own.
 *
 * FAIL-CLOSED, deliberately. Exactly one export shape has ever been seen, so a
 * workbook that does not match it precisely is refused rather than interpreted.
 * A spreadsheet is an invitation to guess, and a guess here becomes a mark on a
 * child's record. «Not recognised» is a sentence a teacher can act on; a
 * misread grid is not.
 *
 * Three things this parser will not do:
 *
 *  - evaluate a formula. `getCalculatedValue()` is never called; a formula where
 *    a mark should be means the file is not the one this parser understands, and
 *    it says so instead of computing something.
 *  - read the workbook's metadata. A real export carries the teacher's name in
 *    `docProps/core.xml` and the local path it was saved from; none of that is
 *    needed to read marks, so none of it is loaded (§10).
 *  - decide anything pedagogical. Groups are structure, never domains.
 */
class IntuitivoXlsxParser implements CorrectionGridParser
{
    /** The one sheet an Intuitivo export has. */
    protected const SHEET = 'Notas';

    protected const HEADER_STUDENT = 'nome do estudante';

    /** How the total column names itself, whatever maximum it declares. */
    protected const HEADER_TOTAL = 'total';

    /**
     * Ceilings on the SHEET. The ceilings on the package — uncompressed size,
     * compression ratio, entry count, active content — live in
     * SpreadsheetZipSafety, which the generic reader needs word for word.
     */
    protected const MAX_COLUMNS = 200;

    protected const MAX_ROWS = 2000;

    // Defaulted rather than required: the safety check is stateless, and a
    // parser that still answers to `new IntuitivoXlsxParser` is one the tests
    // can build without a container — which is the point they make about it
    // needing nothing at all to run.
    public function __construct(protected SpreadsheetZipSafety $zipSafety = new SpreadsheetZipSafety) {}

    public function source(): CorrectionGridSource
    {
        return CorrectionGridSource::Intuitivo;
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        // .xls and .xlsm deliberately absent: the legacy format is a different
        // reader and .xlsm is a macro container. Neither has been observed.
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

    /**
     * A cheap structural look: is this a sane zip that contains a workbook with
     * a sheet called «Notas»? Read from the package, not from PhpSpreadsheet,
     * so an unreadable file is rejected before a full parser touches it.
     */
    public function supports(string $absolutePath, string $originalFilename): bool
    {
        if (strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION)) !== 'xlsx') {
            return false;
        }

        // Package checks and the refusal of active content both happen inside
        // workbookXml(), which returns null rather than let a caller read from a
        // package it rejected.
        $workbook = $this->zipSafety->workbookXml($absolutePath);

        if ($workbook === null) {
            return false;
        }

        return str_contains($workbook, 'name="'.self::SHEET.'"');
    }

    public function parse(string $absolutePath, string $originalFilename): CanonicalCorrectionGrid
    {
        $spreadsheet = $this->open($absolutePath);

        if ($spreadsheet === null) {
            return $this->refuse($originalFilename, 'Este formato de ficheiro Intuitivo ainda não é reconhecido pelo Lapispro.');
        }

        try {
            $sheet = $spreadsheet->getSheetByName(self::SHEET);

            if ($sheet === null) {
                return $this->refuse($originalFilename, 'Este formato de ficheiro Intuitivo ainda não é reconhecido pelo Lapispro.');
            }

            return $this->read($sheet, $originalFilename);
        } catch (UnreadableIntuitivoSheet $exception) {
            return $this->refuse($originalFilename, $exception->getMessage());
        } finally {
            // PhpSpreadsheet holds the whole workbook in memory and its cells
            // reference the sheet, which references the workbook — a cycle the
            // collector will not break on its own. Left undisconnected, parsing
            // several files in one process (a queue worker, a test suite) walks
            // straight into the memory limit. Released here rather than left to
            // chance, whatever happened above.
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * Opens the workbook with everything optional switched off: no charts, no
     * metadata, values only. Returns null when the file cannot be opened at all.
     */
    protected function open(string $absolutePath): ?Spreadsheet
    {
        if (! $this->zipSafety->isSafe($absolutePath)) {
            return null;
        }

        try {
            $reader = new XlsxReader;
            // NOT setReadDataOnly(true), and the reason is structural rather
            // than cosmetic: in data-only mode PhpSpreadsheet does not load the
            // merge list, and the merges on row 1 are the ONLY statement of
            // where a group begins and ends. Measured on the fixture: 7 merges
            // with it off, 0 with it on.
            //
            // The alternative — reading `<mergeCell>` straight out of the sheet
            // XML alongside the reader — was rejected as two parsers of one
            // file for four column ranges. Reading formatting costs nothing
            // here: the upload is capped, the zip is checked before this runs,
            // and only the «Notas» sheet is loaded.
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(false);
            $reader->setLoadSheetsOnly([self::SHEET]);

            return $reader->load($absolutePath);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @throws UnreadableIntuitivoSheet
     */
    protected function read(Worksheet $sheet, string $originalFilename): CanonicalCorrectionGrid
    {
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $lastRow = $sheet->getHighestDataRow();

        if ($lastColumn > self::MAX_COLUMNS || $lastRow > self::MAX_ROWS) {
            throw new UnreadableIntuitivoSheet('A folha «Notas» tem uma dimensão inesperada.');
        }

        if ($this->text($sheet, 'A1') === null || mb_strtolower((string) $this->text($sheet, 'A1')) !== self::HEADER_STUDENT) {
            throw new UnreadableIntuitivoSheet('A folha «Notas» não começa com a coluna «Nome do estudante».');
        }

        [$groups, $items, $groupOf] = $this->structure($sheet, $lastColumn);

        if ($groups === [] || $items === []) {
            throw new UnreadableIntuitivoSheet('Não foi possível identificar os grupos e as perguntas na folha «Notas».');
        }

        $totalColumn = $this->totalColumn($sheet, $lastColumn, $items);
        [$students, $results, $summaries] = $this->students($sheet, $lastRow, $items, $groupOf, $totalColumn);

        if ($students === []) {
            throw new UnreadableIntuitivoSheet('A folha «Notas» não tem alunos.');
        }

        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Intuitivo,
            instrument: new CanonicalInstrument(
                title: $this->titleFrom($originalFilename),
                // What the export declares the test is worth out of. A
                // suggestion for reconciliation, never a value that reaches an
                // Instrument unconfirmed.
                sourceTotal: $this->declaredMaximum($sheet, $totalColumn),
            ),
            groups: $groups,
            items: $items,
            students: $students,
            results: $results,
            summaries: $summaries,
            issues: [],
            // Only what explains the reading. No OOXML metadata, no author, no
            // path, no sheet dimensions beyond what a person would need (§10).
            sourceMetadata: [
                'sheet' => self::SHEET,
                'groups' => count($groups),
                'questions' => count($items),
            ],
        );
    }

    /**
     * Groups and questions, read from the merged headers on row 1 and the
     * «Item N (Máximo: X)» headers on row 2.
     *
     * The merge is the only statement of a group's extent. A group header that
     * is not merged spans one column, which is legitimate for a one-question
     * group and is why an unmerged FILE is refused elsewhere rather than here.
     *
     * @return array{0: list<CanonicalGroup>, 1: list<CanonicalItem>, 2: array<string, string>}
     *
     * @throws UnreadableIntuitivoSheet
     */
    protected function structure(Worksheet $sheet, int $lastColumn): array
    {
        $spans = $this->groupSpans($sheet, $lastColumn);

        $groups = [];
        $items = [];
        $groupOf = [];
        $sequence = 0;

        foreach ($spans as $index => $span) {
            $groupKey = 'group:'.($index + 1);

            $groups[] = new CanonicalGroup(
                sourceKey: $groupKey,
                sequence: $index + 1,
                label: $span['label'],
                metadata: ['columns' => $span['from'].'..'.$span['to']],
            );

            for ($column = $span['fromIndex']; $column <= $span['toIndex']; $column++) {
                $letter = Coordinate::stringFromColumnIndex($column);
                $header = $this->text($sheet, $letter.'2');

                if ($header === null) {
                    throw new UnreadableIntuitivoSheet("A coluna {$letter} não tem cabeçalho de pergunta.");
                }

                [$label, $maximum] = $this->splitHeader($header);

                if ($maximum === null) {
                    throw new UnreadableIntuitivoSheet("A pergunta «{$label}» não declara a cotação máxima.");
                }

                $sequence++;
                // Identity is the COLUMN, never the label: «Item 1» exists in
                // every group and merging two of them would merge two different
                // questions (§12).
                $itemKey = 'item:'.$groupKey.':'.$letter;
                $groupOf[$letter] = $itemKey;

                $items[] = new CanonicalItem(
                    sourceKey: $itemKey,
                    sequence: $sequence,
                    groupSourceKey: $groupKey,
                    code: $label,
                    label: $label,
                    pointsPossible: $maximum,
                    metadata: ['column' => $letter],
                );
            }
        }

        return [$groups, $items, $groupOf];
    }

    /**
     * Where each group begins and ends, from the merges on row 1.
     *
     * Column A and the trailing Total columns are merged vertically (A1:A2) and
     * are not groups; a group merge is horizontal on row 1.
     *
     * @return list<array{label: string, from: string, to: string, fromIndex: int, toIndex: int}>
     *
     * @throws UnreadableIntuitivoSheet
     */
    protected function groupSpans(Worksheet $sheet, int $lastColumn): array
    {
        $spans = [];

        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = array_pad(explode(':', $range), 2, null);

            if ($end === null) {
                continue;
            }

            $fromColumn = Coordinate::columnIndexFromString(preg_replace('/\d/', '', $start) ?? '');
            $toColumn = Coordinate::columnIndexFromString(preg_replace('/\d/', '', $end) ?? '');
            $fromRow = (int) preg_replace('/\D/', '', $start);

            // Horizontal, on row 1, and past column A.
            if ($fromRow !== 1 || $toColumn <= $fromColumn || $fromColumn < 2) {
                continue;
            }

            $label = $this->text($sheet, $start);

            if ($label === null || $this->looksLikeTotal($label)) {
                continue;
            }

            $spans[] = [
                'label' => $label,
                'from' => Coordinate::stringFromColumnIndex($fromColumn),
                'to' => Coordinate::stringFromColumnIndex($toColumn),
                'fromIndex' => $fromColumn,
                'toIndex' => min($toColumn, $lastColumn),
            ];
        }

        usort($spans, fn (array $a, array $b): int => $a['fromIndex'] <=> $b['fromIndex']);

        // Overlapping groups would put one question in two of them.
        $previousEnd = 1;

        foreach ($spans as $span) {
            if ($span['fromIndex'] <= $previousEnd) {
                throw new UnreadableIntuitivoSheet('Os grupos da folha «Notas» sobrepõem-se.');
            }

            $previousEnd = $span['toIndex'];
        }

        return $spans;
    }

    /**
     * «Item 3 (Máximo: 4.00)» → ['Item 3', '4.00'].
     *
     * The maximum comes back as a decimal STRING. It becomes points on a grade
     * path and a float would be the wrong shape for that from the first line
     * (§24.4).
     *
     * @return array{0: string, 1: string|null}
     */
    protected function splitHeader(string $header): array
    {
        if (preg_match('/^(.*?)\s*\(\s*Máximo\s*:\s*([0-9]+(?:[.,][0-9]+)?)\s*\)\s*$/u', $header, $matches) === 1) {
            return [trim($matches[1]), $this->decimal(str_replace(',', '.', $matches[2]))];
        }

        return [trim($header), null];
    }

    /**
     * One decimal shape for everything this parser produces: at most four
     * decimals, no trailing zeros, no float noise. «4.00» and «4» are the same
     * cotação, and letting both exist would make every comparison downstream
     * decide which one it meant.
     */
    protected function decimal(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * The column holding each student's own total, if the export has one.
     *
     * @param  list<CanonicalItem>  $items
     */
    protected function totalColumn(Worksheet $sheet, int $lastColumn, array $items): ?string
    {
        $lastQuestion = 0;

        foreach ($items as $item) {
            $lastQuestion = max($lastQuestion, Coordinate::columnIndexFromString($item->metadata['column']));
        }

        for ($column = $lastQuestion + 1; $column <= $lastColumn; $column++) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $header = $this->text($sheet, $letter.'1');

            if ($header !== null && $this->looksLikeTotal($header) && ! str_contains($header, '%')) {
                return $letter;
            }
        }

        return null;
    }

    protected function looksLikeTotal(string $header): bool
    {
        return str_starts_with(mb_strtolower(trim($header)), self::HEADER_TOTAL);
    }

    /**
     * The instrument's declared maximum, from «Total (Máximo: 100)».
     */
    protected function declaredMaximum(Worksheet $sheet, ?string $totalColumn): ?string
    {
        if ($totalColumn === null) {
            return null;
        }

        [, $maximum] = $this->splitHeader((string) $this->text($sheet, $totalColumn.'1'));

        return $maximum;
    }

    /**
     * Students, their marks, and the totals the source worked out for itself.
     *
     * A cell with no value produces NO result at all. Not a zero — the file has
     * plenty of real zeros and they mean zero — and not an absence, which is an
     * event a teacher records. What an empty cell means in Intuitivo has never
     * been demonstrated, so nothing here decides it (§14).
     *
     * @param  list<CanonicalItem>  $items
     * @param  array<string, string>  $groupOf  column letter => item source key
     * @return array{0: list<CanonicalStudent>, 1: list<CanonicalResult>, 2: list<CanonicalSummary>}
     *
     * @throws UnreadableIntuitivoSheet
     */
    protected function students(Worksheet $sheet, int $lastRow, array $items, array $groupOf, ?string $totalColumn): array
    {
        $students = [];
        $results = [];
        $summaries = [];

        for ($row = 3; $row <= $lastRow; $row++) {
            $name = $this->text($sheet, 'A'.$row);

            if ($name === null) {
                continue; // A formatted but empty row. The export has many.
            }

            $studentKey = 'student:'.$row;

            foreach ($items as $item) {
                $letter = $item->metadata['column'];
                $value = $this->number($sheet, $letter.$row);

                if ($value === null) {
                    continue; // Blank: no mark, and no opinion about why.
                }

                $results[] = new CanonicalResult(
                    studentSourceKey: $studentKey,
                    itemSourceKey: $groupOf[$letter],
                    pointsEarned: $value,
                    // Intuitivo grades in points, not right/wrong. `isCorrect`
                    // stays null and the mark stands on its own.
                    sourceValue: $value,
                );
            }

            $total = $totalColumn === null ? null : $this->number($sheet, $totalColumn.$row);

            $students[] = new CanonicalStudent(
                sourceKey: $studentKey,
                displayName: $name,
                // Points, not a percentage: the source's own total for this
                // student, carried for reconciliation and never as a mark (§27).
                sourceScore: $total,
            );

            if ($total !== null) {
                $summaries[] = new CanonicalSummary(
                    scope: CanonicalSummary::SCOPE_STUDENT,
                    key: 'total',
                    subjectSourceKey: $studentKey,
                    value: $total,
                    unit: 'points',
                );
            }
        }

        return [$students, $results, $summaries];
    }

    /**
     * A cell's text, or null. Never a formula: `getValue()` on a formula cell
     * returns the formula itself, and a mark that starts with «=» is a file this
     * parser does not understand (§9).
     *
     * @throws UnreadableIntuitivoSheet
     */
    protected function text(Worksheet $sheet, string $coordinate): ?string
    {
        $value = $sheet->getCell($coordinate)->getValue();

        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (str_starts_with($text, '=')) {
            throw new UnreadableIntuitivoSheet("A célula {$coordinate} contém uma fórmula. O Lapispro não executa fórmulas.");
        }

        return $text;
    }

    /**
     * A cell's number as a decimal STRING.
     *
     * XLSX stores 37.66 as 37.659999999999997, and a value that becomes points
     * must not carry that into the database. Rounded to four decimals — the
     * column's own precision — and never handled as a float past this point.
     *
     * @throws UnreadableIntuitivoSheet
     */
    protected function number(Worksheet $sheet, string $coordinate): ?string
    {
        $text = $this->text($sheet, $coordinate);

        if ($text === null) {
            return null;
        }

        $normalised = str_replace([' ', ','], ['', '.'], rtrim($text, '%'));

        if (! is_numeric($normalised)) {
            return null;
        }

        return $this->decimal($normalised);
    }

    /**
     * A title suggestion from the filename, which is the only place the export
     * names itself. Trimmed of its extension and nothing more — the teacher
     * edits it in the wizard.
     */
    protected function titleFrom(string $originalFilename): ?string
    {
        $title = trim((string) pathinfo($originalFilename, PATHINFO_FILENAME));

        return $title === '' ? null : mb_substr($title, 0, 200);
    }

    protected function refuse(string $originalFilename, string $why): CanonicalCorrectionGrid
    {
        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Intuitivo,
            instrument: new CanonicalInstrument(title: $this->titleFrom($originalFilename)),
            issues: [ImportIssue::make(
                IssueCode::UnsupportedStructure,
                $why,
                [],
                IssueSeverity::Error,
            )],
        );
    }
}
