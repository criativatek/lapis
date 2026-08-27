<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalInstrument;
use App\Domain\Import\Correction\CanonicalItem;
use App\Domain\Import\Correction\CanonicalResult;
use App\Domain\Import\Correction\CanonicalStudent;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportIssue;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\IssueCode;
use App\Domain\Import\Correction\IssueSeverity;
use App\Domain\Import\Correction\LapisGridContract;
use App\Domain\Import\Correction\LapisGridDeclaration;
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

        // A workbook Lapispro produced says so, formally, and then there is nothing
        // to ask: the structure is a contract this build wrote and can read
        // back. Everything else falls through to the ordinary path (§16).
        //
        // Unless the teacher has since described the sheet by hand, which is
        // exactly what they are offered when a grid no longer matches its own
        // contract. An explicit description always wins over a declaration —
        // otherwise the way out of a broken grid would loop back into it (§14).
        $declaration = $this->declarationIn($snapshot);

        if ($declaration !== null && ! $mapping->table->describesTheSheet()) {
            return $this->fromLapisGrid($snapshot, $declaration, $originalFilename);
        }

        return $this->canonicaliser->canonicalise(
            $snapshot,
            $this->settled($snapshot, $mapping->table),
            $mapping,
            $this->titleFrom($originalFilename),
        );
    }

    /**
     * What the workbook declares itself to be, or null when it declares nothing.
     */
    public function declarationIn(TabularSourceSnapshot $snapshot): ?LapisGridDeclaration
    {
        $names = $snapshot->metadata['defined_names'] ?? [];

        return is_array($names) ? LapisGridDeclaration::from($names) : null;
    }

    /**
     * A grid this application wrote, read back.
     *
     * Only IDENTITY is taken from the file — which item is in which column,
     * which enrolment is on which row. The cotações, the domains and the
     * permissions are read from the database afterwards, by ResolveLapisGrid,
     * because a workbook is a claim and never an authorisation (§6).
     *
     * Fail-closed throughout: a column that is not there, a row somebody
     * inserted, a version this build does not know. Each of those is a file
     * that no longer matches the contract, and interpreting it anyway is how a
     * mark lands on the wrong question (§14).
     */
    protected function fromLapisGrid(
        TabularSourceSnapshot $snapshot,
        LapisGridDeclaration $declaration,
        string $originalFilename,
    ): CanonicalCorrectionGrid {
        if (! $declaration->isUsable()) {
            return $this->refuseGrid($originalFilename, $declaration->reason());
        }

        $sheet = $snapshot->onlyOccupiedSheet() ?? $snapshot->sheet(LapisGridContract::SHEET);

        if ($sheet === null) {
            return $this->refuseGrid($originalFilename, $declaration->reason());
        }

        $items = [];
        $sequence = 0;

        foreach ($declaration->itemsByColumn as $column => $itemUlid) {
            $heading = $sheet->cellAt(LapisGridContract::HEADER_ROW, $column)->text;

            // The heading is what proves the column is still there.
            //
            // Not the column count: deleting a column in Excel shifts the ones
            // after it left, and the cells this grid creates for its own input
            // validation keep the old width reported for a while afterwards. The
            // heading is written for every item and for no other column, so a
            // declared column with an empty heading is a column that was removed
            // or emptied — either way the file no longer matches its own
            // contract, and reading it would put marks on the wrong item (§14).
            if ($heading === null || TabularColumn::index($column) > $sheet->columnCount) {
                return $this->refuseGrid($originalFilename, $declaration->reason());
            }

            $items[] = new CanonicalItem(
                sourceKey: TabularCanonicaliser::ITEM_PREFIX.$column,
                sequence: ++$sequence,
                externalId: $itemUlid,
                label: $heading,
                metadata: ['column' => $column],
            );
        }

        $students = [];
        $results = [];

        foreach ($sheet->occupiedRows() as $rowNumber) {
            if ($rowNumber < LapisGridContract::FIRST_DATA_ROW) {
                continue;
            }

            $identity = $sheet->cellAt($rowNumber, LapisGridContract::COLUMN_ENROLLMENT)->text;
            $name = $sheet->cellAt($rowNumber, LapisGridContract::COLUMN_NAME)->text;

            if ($identity === null) {
                // A row with content and no identity is a row somebody added.
                return $this->refuseGrid($originalFilename, $declaration->reason());
            }

            $studentKey = TabularCanonicaliser::STUDENT_PREFIX.$rowNumber;
            $number = $sheet->cellAt($rowNumber, LapisGridContract::COLUMN_NUMBER)->number;

            $students[] = new CanonicalStudent(
                sourceKey: $studentKey,
                externalId: $identity,
                classNumber: $number === null ? null : (int) $number,
                displayName: $name,
            );

            foreach ($declaration->itemsByColumn as $column => $itemUlid) {
                $cell = $sheet->cellAt($rowNumber, $column);

                if ($cell->isFormula) {
                    return $this->refuseGrid(
                        $originalFilename,
                        __('Esta grelha Lapispro tem fórmulas onde deviam estar os resultados. Substitua-as pelos valores antes de importar.'),
                    );
                }

                // A blank stays blank. Not a zero, not an absence (§10).
                if ($cell->isBlank() || ! $cell->isNumeric()) {
                    continue;
                }

                $results[] = new CanonicalResult(
                    studentSourceKey: $studentKey,
                    itemSourceKey: TabularCanonicaliser::ITEM_PREFIX.$column,
                    pointsEarned: $cell->number,
                    sourceValue: $cell->text,
                );
            }
        }

        if ($students === []) {
            return $this->refuseGrid($originalFilename, __('Esta grelha Lapispro não tem alunos.'));
        }

        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Generic,
            instrument: new CanonicalInstrument(
                title: $this->titleFrom($originalFilename),
                externalId: $declaration->instrumentUlid,
            ),
            items: $items,
            students: $students,
            results: $results,
            // Identity of the instrument, and counts. Never a student (§17).
            sourceMetadata: [...$declaration->metadata(), 'kind' => 'xlsx'],
        );
    }

    /**
     * A grid that no longer matches its own contract.
     *
     * Refused with the way out named: the generic path can still read it, and a
     * teacher who has just filled in thirty marks should not have to start over
     * because a column moved (§14).
     */
    protected function refuseGrid(string $originalFilename, string $message): CanonicalCorrectionGrid
    {
        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Generic,
            instrument: new CanonicalInstrument(title: $this->titleFrom($originalFilename)),
            issues: [ImportIssue::make(
                IssueCode::UnsupportedStructure,
                $message.' '.__('Pode importá-la como outra folha de cálculo, indicando onde estão os resultados.'),
                context: ['lapis_grid' => 'structure'],
                severity: IssueSeverity::Error,
            )],
            sourceMetadata: ['kind' => 'xlsx', 'lapis_grid' => false],
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

        $declaration = $this->declarationIn($snapshot);

        if ($declaration !== null) {
            // A grid this application wrote needs no describing, so none of the
            // questions below travel to the browser at all (§13).
            return [
                'readable' => true,
                'lapis_grid' => $declaration->isUsable(),
                'lapis_grid_refusal' => $declaration->isUsable() ? null : $declaration->reason(),
                'kind' => $snapshot->metadata['kind'] ?? null,
            ];
        }

        $table = $this->settled($snapshot, $mapping->table);
        $sheet = $snapshot->sheet($table->sheet);

        return [
            'readable' => true,
            'lapis_grid' => false,
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
