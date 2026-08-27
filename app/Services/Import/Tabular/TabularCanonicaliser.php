<?php

namespace App\Services\Import\Tabular;

use App\Domain\Assessment\Bc;
use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalGroup;
use App\Domain\Import\Correction\CanonicalInstrument;
use App\Domain\Import\Correction\CanonicalItem;
use App\Domain\Import\Correction\CanonicalResult;
use App\Domain\Import\Correction\CanonicalStudent;
use App\Domain\Import\Correction\CanonicalSummary;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportIssue;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\IssueCode;
use App\Domain\Import\Correction\IssueSeverity;
use App\Domain\Import\Correction\TabularMapping;
use App\Domain\Import\Tabular\TabularCell;
use App\Domain\Import\Tabular\TabularNumber;
use App\Domain\Import\Tabular\TabularSheet;
use App\Domain\Import\Tabular\TabularSourceSnapshot;

/**
 * Turns a rectangle of cells into a correction grid, using only what the teacher
 * said it means.
 *
 * This is the step Plickers and Intuitivo do not have. Their files declare their
 * own structure, so their parsers can go straight to the canonical vocabulary; a
 * sheet somebody made up declares nothing, and the structure has to arrive from
 * the wizard instead. Everything after this point — preview, readiness,
 * persistence, calculation — is the pipeline that already existed, unchanged
 * (§5, §41).
 *
 * It is a PURE FUNCTION of (table, structure, decisions). It reads no database
 * and writes nothing, which is why the import can simply be re-canonicalised
 * whenever the teacher changes an answer, instead of the grid and the mapping
 * drifting apart and having to be reconciled.
 *
 * Two things it will not do, both of them the point of the whole feature:
 *
 *  - INFER A MAXIMUM. The largest mark in the class is not the cotação, however
 *    convenient that would be. A maximum arrives from the teacher or the column
 *    has none (§23).
 *  - INFER A DOMAIN. A column headed «Leitura» has still said nothing about
 *    curriculum. Domains stay in ImportMapping, where the teacher put them (§18).
 */
class TabularCanonicaliser
{
    /** Source key prefixes. Position, never heading — headings repeat (§20). */
    public const ITEM_PREFIX = 'col:';

    public const GROUP_PREFIX = 'group:';

    public const STUDENT_PREFIX = 'row:';

    /** How many offending cells to name in a message before summarising. */
    protected const NAMED_IN_MESSAGE = 5;

    public function canonicalise(
        TabularSourceSnapshot $snapshot,
        TabularMapping $table,
        ImportMapping $mapping,
        ?string $title = null,
    ): CanonicalCorrectionGrid {
        $sheet = $snapshot->sheet($table->sheet);

        if ($sheet === null || ! $table->describesTheSheet()) {
            return $this->undescribed($snapshot, $table, $mapping, $title);
        }

        $students = [];
        $results = [];
        $summaries = [];
        $issues = [];

        $columns = $this->columns($sheet, $table, $mapping);
        [$groups, $items] = $this->structure($columns, $mapping->resultMode);

        $withoutName = 0;
        $formulas = [];
        $unreadable = [];

        foreach ($this->dataRows($sheet, $table) as $rowNumber) {
            $nameCell = $sheet->cellAt($rowNumber, (string) $table->studentColumn);

            if ($nameCell->isBlank()) {
                // A row with marks but nobody's name cannot be attributed to a
                // student, and attributing it by position is exactly the guess
                // this importer refuses (§13).
                $withoutName++;

                continue;
            }

            $studentKey = self::STUDENT_PREFIX.$rowNumber;

            foreach ($columns as $column) {
                $cell = $sheet->cellAt($rowNumber, $column['letter']);

                if ($cell->isFormula) {
                    $formulas[$column['letter']] = true;

                    continue;
                }

                if ($cell->isBlank()) {
                    // No result at all. A blank is a question about a student,
                    // never a zero and never an absence (§29).
                    continue;
                }

                if (! $cell->isNumeric()) {
                    $unreadable[$cell->text ?? ''] = true;

                    continue;
                }

                if ($mapping->importsOverallResult()) {
                    continue;
                }

                $results[] = new CanonicalResult(
                    studentSourceKey: $studentKey,
                    itemSourceKey: self::ITEM_PREFIX.$column['letter'],
                    pointsEarned: $cell->number,
                    sourceValue: $cell->text,
                );
            }

            $students[] = new CanonicalStudent(
                sourceKey: $studentKey,
                displayName: $nameCell->text,
                sourceScore: $this->sourceScoreFor($sheet, $rowNumber, $table, $mapping, $columns),
            );

            $total = $this->totalFor($sheet, $rowNumber, $table);

            if ($total !== null) {
                // The source's own total, kept beside the marks so the wizard can
                // show both. It is reconciliation, never a substitute for what
                // Lapispro computes (§31).
                $summaries[] = new CanonicalSummary(
                    scope: CanonicalSummary::SCOPE_STUDENT,
                    key: 'source_total',
                    subjectSourceKey: $studentKey,
                    value: $total,
                );
            }
        }

        if ($students === []) {
            $issues[] = ImportIssue::make(
                IssueCode::UnsupportedStructure,
                __('Não foi encontrado nenhum aluno abaixo da linha dos títulos. Confirme a linha dos títulos e a coluna que identifica o aluno.'),
                severity: IssueSeverity::Error,
            );
        }

        $issues = [...$issues, ...$this->valueIssues($formulas, $unreadable, $withoutName)];
        $issues = [...$issues, ...$this->mappingIssues($table, $mapping, $columns)];

        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Generic,
            instrument: new CanonicalInstrument(title: $title),
            groups: $groups,
            items: $items,
            students: $students,
            results: $results,
            summaries: $summaries,
            issues: $issues,
            sourceMetadata: $this->metadata($snapshot, $table, $sheet, count($columns)),
        );
    }

    /**
     * A file read but not yet explained. Deliberately a grid with nothing in it
     * rather than a grid built on defaults: an import that has been told nothing
     * must be able to say so (§3).
     */
    protected function undescribed(
        TabularSourceSnapshot $snapshot,
        TabularMapping $table,
        ImportMapping $mapping,
        ?string $title,
    ): CanonicalCorrectionGrid {
        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::Generic,
            instrument: new CanonicalInstrument(title: $title),
            issues: [ImportIssue::make(
                IssueCode::UnsupportedStructure,
                $this->whatIsMissing($table, $mapping),
                severity: IssueSeverity::Error,
            )],
            sourceMetadata: $this->metadata($snapshot, $table, null, 0),
        );
    }

    protected function whatIsMissing(TabularMapping $table, ImportMapping $mapping): string
    {
        return match (true) {
            $table->sheet === null => __('Escolha a folha a importar.'),
            $table->headerRow === null => __('Indique qual é a linha com os títulos das colunas.'),
            $table->studentColumn === null => __('Indique qual é a coluna que identifica o aluno.'),
            $table->resultColumns === [] => __('Escolha pelo menos uma coluna com resultados.'),
            default => __('Indique como está organizada a folha antes de continuar.'),
        };
    }

    /**
     * The chosen result columns, with the heading each one carries.
     *
     * @return list<array{letter: string, label: string, points: string|null}>
     */
    protected function columns(TabularSheet $sheet, TabularMapping $table, ImportMapping $mapping): array
    {
        $columns = [];

        foreach ($table->resultColumns as $letter) {
            $heading = $sheet->cellAt((int) $table->headerRow, $letter)->text;

            $columns[] = [
                'letter' => $letter,
                // A column with no heading still has a position, and the position
                // is what identifies it anyway.
                'label' => $heading ?? __('Coluna :letra', ['letra' => $letter]),
                // The cotação the teacher set for this column, written into the
                // canonical item because that is where the persistence step reads
                // a section's worth from.
                'points' => $mapping->points[self::ITEM_PREFIX.$letter] ?? null,
            ];
        }

        return $columns;
    }

    /**
     * @param  list<array{letter: string, label: string, points: string|null}>  $columns
     * @return array{0: list<CanonicalGroup>, 1: list<CanonicalItem>}
     */
    protected function structure(array $columns, string $resultMode): array
    {
        if ($resultMode === ImportMapping::RESULT_OVERALL) {
            // The global result is one synthetic item the persistence step
            // creates for itself; the sheet contributes only the number.
            return [[], []];
        }

        $perGroup = $resultMode === ImportMapping::RESULT_PER_GROUP;

        $groups = [];
        $items = [];

        foreach ($columns as $index => $column) {
            $groupKey = null;

            if ($perGroup) {
                $groupKey = self::GROUP_PREFIX.$column['letter'];

                $groups[] = new CanonicalGroup(
                    sourceKey: $groupKey,
                    sequence: $index + 1,
                    label: $column['label'],
                    metadata: ['column' => $column['letter']],
                );
            }

            $items[] = new CanonicalItem(
                sourceKey: self::ITEM_PREFIX.$column['letter'],
                sequence: $index + 1,
                groupSourceKey: $groupKey,
                label: $column['label'],
                pointsPossible: $column['points'],
                metadata: ['column' => $column['letter']],
            );
        }

        return [$groups, $items];
    }

    /**
     * Row numbers that could hold a student: below the headings, not blank.
     *
     * @return list<int>
     */
    protected function dataRows(TabularSheet $sheet, TabularMapping $table): array
    {
        return array_values(array_filter(
            $sheet->occupiedRows(),
            fn (int $rowNumber): bool => $rowNumber > (int) $table->headerRow,
        ));
    }

    /**
     * What goes into `sourceScore`.
     *
     * For a global result it is the percentage, worked out from what the teacher
     * declared the column to be — a percentage already, or points out of a stated
     * maximum. Never from the marks observed (§16, §23).
     *
     * For the other modes it is the source's own total, when the teacher pointed
     * at a column carrying one, and it exists purely to be compared against.
     *
     * @param  list<array{letter: string, label: string, points: string|null}>  $columns
     */
    protected function sourceScoreFor(
        TabularSheet $sheet,
        int $rowNumber,
        TabularMapping $table,
        ImportMapping $mapping,
        array $columns,
    ): ?string {
        if (! $mapping->importsOverallResult()) {
            return $this->totalFor($sheet, $rowNumber, $table);
        }

        $letter = $columns[0]['letter'] ?? null;

        if ($letter === null) {
            return null;
        }

        $cell = $sheet->cellAt($rowNumber, $letter);

        if (! $cell->isNumeric() || $cell->number === null) {
            return null;
        }

        if ($table->readsPercentages()) {
            return $cell->number;
        }

        $maximum = $table->overallMaximum;

        if ($maximum === null || Bc::isZero(Bc::of($maximum))) {
            return null;
        }

        return Bc::round(Bc::mul(Bc::div(Bc::of($cell->number), Bc::of($maximum)), '100'), 4, 'half_up');
    }

    protected function totalFor(TabularSheet $sheet, int $rowNumber, TabularMapping $table): ?string
    {
        if ($table->totalColumn === null) {
            return null;
        }

        return $sheet->cellAt($rowNumber, $table->totalColumn)->number;
    }

    /**
     * @param  array<string, bool>  $formulas  column letter => seen
     * @param  array<string, bool>  $unreadable  the offending text => seen
     * @return list<ImportIssue>
     */
    protected function valueIssues(array $formulas, array $unreadable, int $withoutName): array
    {
        $issues = [];

        if ($formulas !== []) {
            $issues[] = ImportIssue::make(
                IssueCode::UnsupportedStructure,
                __('As colunas :colunas contêm fórmulas. O Lapispro não executa fórmulas — exporte ou copie os valores antes de importar.', [
                    'colunas' => implode(', ', array_keys($formulas)),
                ]),
                context: ['columns' => implode(',', array_keys($formulas))],
                severity: IssueSeverity::Error,
            );
        }

        if ($unreadable !== []) {
            $values = array_slice(array_keys($unreadable), 0, self::NAMED_IN_MESSAGE);

            $issues[] = ImportIssue::make(
                IssueCode::AmbiguousValue,
                __('Alguns valores não são números e ficaram por importar (:valores). Estes alunos ficam por avaliar nessas colunas — resolva-os na grelha ou corrija o ficheiro.', [
                    'valores' => implode(', ', array_map(fn (string $value): string => '«'.$value.'»', $values)),
                ]),
                context: ['values' => count($unreadable)],
                severity: IssueSeverity::Warning,
            );
        }

        if ($withoutName > 0) {
            $issues[] = ImportIssue::make(
                IssueCode::UnknownStudent,
                __(':linhas linha(s) com resultados não têm nome na coluna do aluno e foram ignoradas.', ['linhas' => $withoutName]),
                context: ['rows' => $withoutName],
                severity: IssueSeverity::Warning,
            );
        }

        return $issues;
    }

    /**
     * Disagreements between what the teacher declared and what the cells look
     * like. Every one of them is a WARNING: the declaration wins, because it is
     * the teacher's to make — but staying silent about a column formatted as a
     * percentage that was declared to be points would be letting somebody import
     * a 0,75 as three quarters of a mark (§14, §24).
     *
     * @param  list<array{letter: string, label: string, points: string|null}>  $columns
     * @return list<ImportIssue>
     */
    protected function mappingIssues(TabularMapping $table, ImportMapping $mapping, array $columns): array
    {
        $issues = [];

        if ($mapping->importsOverallResult() && count($table->resultColumns) > 1) {
            $issues[] = ImportIssue::make(
                IssueCode::UnsupportedStructure,
                __('Um resultado global vem de uma só coluna. Escolha apenas uma, ou mude para resultados por grupos.'),
                severity: IssueSeverity::Error,
            );
        }

        // Deliberately UnsupportedStructure and not MissingPoints, which reads
        // like the better name for it. The preview FILTERS MissingPoints out of
        // what it shows, because for a source that states its own cotações that
        // complaint is answered by the wizard's own cotação column and repeating
        // it would be noise. Here there is no such column to answer it — the
        // whole point is that the sheet never said — so a MissingPoints issue
        // would be silently dropped and the import would confirm with no maximum
        // at all. Named for where it is shown, not for how it reads.
        if ($mapping->importsOverallResult()
            && ! $table->readsPercentages()
            && $table->overallMaximum === null) {
            $issues[] = ImportIssue::make(
                IssueCode::UnsupportedStructure,
                __('Indique a cotação máxima do resultado, ou assinale que a coluna já está em percentagem. O Lapispro não deduz que 14 é 14 em 20.'),
                context: ['field' => 'overall_maximum'],
                severity: IssueSeverity::Error,
            );
        }

        if (! $mapping->importsOverallResult()) {
            $missing = array_values(array_filter($columns, fn (array $column): bool => $column['points'] === null));

            if ($missing !== []) {
                $issues[] = ImportIssue::make(
                    IssueCode::UnsupportedStructure,
                    __('Falta a cotação máxima de :quantas coluna(s): :colunas.', [
                        'quantas' => count($missing),
                        'colunas' => implode(', ', array_column($missing, 'label')),
                    ]),
                    context: ['field' => 'points', 'columns' => implode(',', array_column($missing, 'letter'))],
                    severity: IssueSeverity::Error,
                );
            }
        }

        return $issues;
    }

    /**
     * How the file was read. Facts about the container, never about the class:
     * this travels into its own database column and must stay free of anything
     * personal (§39).
     *
     * @return array<string, mixed>
     */
    protected function metadata(
        TabularSourceSnapshot $snapshot,
        TabularMapping $table,
        ?TabularSheet $sheet,
        int $resultColumns,
    ): array {
        // `defined_names` is how the reader hands the Lapispro contract to the
        // parser. It is scratch data for one request, not a fact about the
        // import worth keeping, and provenance is not a place to put things
        // just because they were in reach.
        $container = $snapshot->metadata;
        unset($container['defined_names']);

        return [
            ...$container,
            'sheet' => $table->sheet,
            'header_row' => $table->headerRow,
            // `??` applies isset() semantics to the whole expression, so a null
            // sheet yields 0 without a nullsafe operator.
            'columns' => $sheet->columnCount ?? 0,
            'result_columns' => $resultColumns,
        ];
    }

    /**
     * A blank cell, for callers that need one without importing the cell class.
     */
    public static function blank(): TabularCell
    {
        return TabularCell::empty();
    }

    /**
     * Rounded the way every other decimal in this feature is, so a cotação typed
     * as «20,5» and one typed as «20.50» are the same number.
     */
    public static function decimal(string $value): string
    {
        return TabularNumber::tidy($value);
    }
}
