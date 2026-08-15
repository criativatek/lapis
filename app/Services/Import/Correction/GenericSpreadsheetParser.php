<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalInstrument;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportIssue;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\IssueCode;
use App\Domain\Import\Correction\IssueSeverity;
use App\Domain\Import\Correction\TabularMapping;
use App\Domain\Import\Tabular\TabularColumn;
use App\Domain\Import\Tabular\TabularSheet;
use App\Domain\Import\Tabular\TabularSourceSnapshot;
use App\Services\Import\Tabular\CsvTabularReader;
use App\Services\Import\Tabular\TabularCanonicaliser;
use App\Services\Import\Tabular\TabularReader;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use App\Services\Import\Tabular\XlsxTabularReader;

/**
 * The teacher's own spreadsheet — a CSV or an .xlsx nobody wrote an adapter for.
 *
 * Where the other two parsers recognise a format, this one recognises only a
 * TABLE, and then asks. That is the entire difference, and it is why this is the
 * one parser that implements MappedCorrectionGridParser: a file with no agreed
 * shape cannot be turned into marks until somebody says which column is the
 * student and what the numbers are out of (§3).
 *
 * It suggests, and it does not decide. The suggestions are structural — a column
 * whose cells are mostly text is probably names, a column whose cells are mostly
 * numbers is probably marks — and they arrive as pre-filled answers on a form the
 * teacher has to submit. Nothing is stored until they do, so a suggestion that
 * was wrong costs a correction rather than a wrong mark (§14).
 *
 * What it will never do, in this or any version: read a heading called «Leitura»
 * as the Leitura domain, take the largest observed mark as the cotação, or turn
 * an empty cell into a zero.
 */
class GenericSpreadsheetParser implements MappedCorrectionGridParser
{
    /** Rows of the sheet shown to the teacher. Enough to recognise it, small enough for a page. */
    protected const SAMPLE_ROWS = 12;

    /** Above this share of numeric cells, a column is offered as a result column. */
    protected const NUMERIC_SHARE = 0.6;

    /** Rows examined when guessing what a column holds. */
    protected const ROWS_EXAMINED = 25;

    /** @var list<TabularReader> */
    protected array $readers;

    public function __construct(
        ?CsvTabularReader $csv = null,
        ?XlsxTabularReader $xlsx = null,
        protected TabularCanonicaliser $canonicaliser = new TabularCanonicaliser,
    ) {
        $this->readers = [$csv ?? new CsvTabularReader, $xlsx ?? new XlsxTabularReader];
    }

    public function source(): CorrectionGridSource
    {
        return CorrectionGridSource::Generic;
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        $extensions = [];

        foreach ($this->readers as $reader) {
            foreach ($reader->extensions() as $extension) {
                $extensions[$extension] = true;
            }
        }

        return array_keys($extensions);
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        $types = [];

        foreach ($this->readers as $reader) {
            foreach ($reader->mimeTypes() as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    public function supports(string $absolutePath, string $originalFilename): bool
    {
        return $this->readerFor($originalFilename)?->supports($absolutePath, $originalFilename) === true;
    }

    /**
     * A first look, with nothing decided. Produces a grid that says so — an
     * Error, no students, no items — because an import that has been told nothing
     * about a sheet knows nothing about it, and a preview built on «probably
     * column A» would be a preview of a guess.
     */
    public function parse(string $absolutePath, string $originalFilename): CanonicalCorrectionGrid
    {
        return $this->parseWith($absolutePath, $originalFilename, new ImportMapping(
            resultMode: CorrectionGridSource::Generic->defaultResultMode(),
        ));
    }

    public function parseWith(string $absolutePath, string $originalFilename, ImportMapping $mapping): CanonicalCorrectionGrid
    {
        try {
            $snapshot = $this->read($absolutePath, $originalFilename);
        } catch (UnreadableSpreadsheet $exception) {
            return $this->refuse($originalFilename, $exception->getMessage());
        }

        if ($snapshot->isEmpty()) {
            return $this->refuse($originalFilename, __('O ficheiro não tem nenhuma folha com dados.'));
        }

        return $this->canonicaliser->canonicalise(
            $snapshot,
            $this->settled($snapshot, $mapping->table),
            $mapping,
            $this->titleFrom($originalFilename),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(string $absolutePath, ImportMapping $mapping): array
    {
        try {
            $snapshot = $this->read($absolutePath, '');
        } catch (UnreadableSpreadsheet $exception) {
            return ['readable' => false, 'message' => $exception->getMessage()];
        }

        $table = $this->settled($snapshot, $mapping->table);
        $sheet = $snapshot->sheet($table->sheet);

        return [
            'readable' => true,
            'kind' => $snapshot->metadata['kind'] ?? null,
            'metadata' => $snapshot->metadata,
            'sheets' => array_map(fn (TabularSheet $each): array => [
                'name' => $each->name,
                'rows' => count($each->occupiedRows()),
                'columns' => $each->columnCount,
                'empty' => $each->isEmpty(),
            ], $snapshot->sheets),
            'needs_sheet_choice' => count($snapshot->occupiedSheets()) > 1 && $mapping->table->sheet === null,
            'selected_sheet' => $table->sheet,
            'row_numbers' => $sheet === null ? [] : $sheet->occupiedRows(),
            'columns' => $sheet === null ? [] : $this->columns($sheet, $table),
            'sample' => $sheet?->sample(self::SAMPLE_ROWS) ?? [],
            'suggestions' => $sheet === null ? [] : $this->suggestions($sheet, $table),
            'table' => $table->toArray(),
        ];
    }

    /**
     * The mapping with the answers that are not really choices already filled in.
     *
     * Only one: a workbook with a single sheet that has anything in it. Choosing
     * between one option is not a choice, and making the teacher confirm it would
     * be ceremony. Several sheets stay unanswered — picking the first because it
     * is first is exactly the silent guess this refuses (§11).
     */
    protected function settled(TabularSourceSnapshot $snapshot, TabularMapping $table): TabularMapping
    {
        if ($table->sheet !== null && $snapshot->sheet($table->sheet) !== null) {
            return $table;
        }

        $only = $snapshot->onlyOccupiedSheet();

        if ($only === null) {
            return $table;
        }

        return new TabularMapping(
            sheet: $only->name,
            headerRow: $table->headerRow,
            studentColumn: $table->studentColumn,
            resultColumns: $table->resultColumns,
            valueKind: $table->valueKind,
            overallMaximum: $table->overallMaximum,
            totalColumn: $table->totalColumn,
        );
    }

    /**
     * Each column with the heading it carries under the chosen header row, and
     * what its cells look like. «Looks numeric» is a fact about the cells, never
     * a claim about meaning.
     *
     * @return list<array{letter: string, heading: string|null, numeric_share: float, has_formula: bool}>
     */
    protected function columns(TabularSheet $sheet, TabularMapping $table): array
    {
        $headerRow = $table->headerRow ?? $this->suggestHeaderRow($sheet);
        $columns = [];

        foreach ($sheet->columnLetters() as $letter) {
            [$share, $hasFormula] = $this->profile($sheet, $letter, $headerRow);

            $columns[] = [
                'letter' => $letter,
                'heading' => $sheet->cellAt($headerRow, $letter)->text,
                'numeric_share' => $share,
                'has_formula' => $hasFormula,
            ];
        }

        return $columns;
    }

    /**
     * @return array{0: float, 1: bool}
     */
    protected function profile(TabularSheet $sheet, string $letter, int $headerRow): array
    {
        $numeric = 0;
        $filled = 0;
        $hasFormula = false;

        foreach ($sheet->occupiedRows() as $rowNumber) {
            if ($rowNumber <= $headerRow || $filled >= self::ROWS_EXAMINED) {
                continue;
            }

            $cell = $sheet->cellAt($rowNumber, $letter);

            if ($cell->isFormula) {
                $hasFormula = true;
            }

            if ($cell->isBlank()) {
                continue;
            }

            $filled++;

            if ($cell->isNumeric()) {
                $numeric++;
            }
        }

        return [$filled === 0 ? 0.0 : round($numeric / $filled, 2), $hasFormula];
    }

    /**
     * Structural guesses, offered as pre-filled answers.
     *
     * Deliberately blind to what anything is CALLED. A column is offered as the
     * student column because its cells are text, not because its heading says
     * «Nome» — a heading is a word in a language, and reading meaning out of it
     * is the first step towards reading «Leitura» as a domain (§3, §18).
     *
     * @return array<string, mixed>
     */
    protected function suggestions(TabularSheet $sheet, TabularMapping $table): array
    {
        $headerRow = $table->headerRow ?? $this->suggestHeaderRow($sheet);

        $student = null;
        $results = [];

        foreach ($sheet->columnLetters() as $letter) {
            [$share] = $this->profile($sheet, $letter, $headerRow);

            if ($student === null && $share < self::NUMERIC_SHARE) {
                $student = $letter;

                continue;
            }

            if ($share >= self::NUMERIC_SHARE) {
                $results[] = $letter;
            }
        }

        return [
            'header_row' => $headerRow,
            'student_column' => $student,
            // Offered, never applied. A total column is NOT guessed at all: a
            // column called «Total» might be a total, and might be the mark for
            // the section called Total (§31).
            'result_columns' => $results,
        ];
    }

    /**
     * The first occupied row that looks like headings rather than data: at least
     * two cells, and not mostly numbers.
     */
    protected function suggestHeaderRow(TabularSheet $sheet): int
    {
        foreach ($sheet->occupiedRows() as $rowNumber) {
            $filled = 0;
            $numeric = 0;

            foreach ($sheet->row($rowNumber) as $cell) {
                if ($cell->isBlank()) {
                    continue;
                }

                $filled++;

                if ($cell->isNumeric()) {
                    $numeric++;
                }
            }

            if ($filled >= 2 && $numeric < $filled) {
                return $rowNumber;
            }
        }

        return $sheet->occupiedRows()[0] ?? 1;
    }

    protected function read(string $absolutePath, string $originalFilename): TabularSourceSnapshot
    {
        $reader = $this->readerFor($originalFilename) ?? $this->readerByContent($absolutePath);

        if ($reader === null) {
            throw new UnreadableSpreadsheet(__('Só são suportados ficheiros CSV e Excel (.xlsx).'));
        }

        return $reader->read($absolutePath);
    }

    protected function readerFor(string $originalFilename): ?TabularReader
    {
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));

        if ($extension === '') {
            return null;
        }

        foreach ($this->readers as $reader) {
            if (in_array($extension, $reader->extensions(), true)) {
                return $reader;
            }
        }

        return null;
    }

    /**
     * When the filename is not available — `describe()` works from the stored
     * upload, whose name on disk carries no extension — the container is
     * recognised by its first bytes instead.
     */
    protected function readerByContent(string $absolutePath): ?TabularReader
    {
        $head = @file_get_contents($absolutePath, false, null, 0, 4);

        $extension = is_string($head) && str_starts_with($head, "PK\x03\x04") ? 'xlsx' : 'csv';

        foreach ($this->readers as $reader) {
            if (in_array($extension, $reader->extensions(), true)) {
                return $reader;
            }
        }

        return null;
    }

    protected function refuse(string $originalFilename, string $message): CanonicalCorrectionGrid
    {
        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Generic,
            instrument: new CanonicalInstrument(title: $this->titleFrom($originalFilename)),
            issues: [ImportIssue::make(IssueCode::UnsupportedStructure, $message, severity: IssueSeverity::Error)],
        );
    }

    /**
     * A title suggestion from the file's own name. The teacher edits it; it is a
     * starting point, not a decision.
     */
    protected function titleFrom(string $originalFilename): ?string
    {
        $stem = trim((string) pathinfo($originalFilename, PATHINFO_FILENAME));
        $stem = trim((string) preg_replace('/[_]+/', ' ', $stem));
        $stem = trim((string) preg_replace('/\s+/', ' ', $stem));

        return $stem === '' ? null : $stem;
    }

    /**
     * Column letters, exposed for callers that build a mapping by position.
     */
    public static function letter(int $index): string
    {
        return TabularColumn::letter($index);
    }
}
