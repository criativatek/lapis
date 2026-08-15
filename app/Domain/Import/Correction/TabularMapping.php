<?php

namespace App\Domain\Import\Correction;

/**
 * What the teacher said their own spreadsheet means.
 *
 * Everything here is structure — which sheet, which row holds the headings,
 * which column names the student, which columns carry marks. Nothing here is
 * pedagogy: domains, cotações and the result mode live in ImportMapping beside
 * it, where they are the same decisions a Plickers or Intuitivo import makes.
 * Keeping the two apart is what stops «generic» from becoming a second, parallel
 * importer (§40).
 *
 * Every field starts null or empty on purpose. A generic sheet states nothing
 * about itself, so an import that has been told nothing must be able to say so
 * rather than proceed on a default that happens to be right for the first file
 * anyone tried (§3).
 */
final readonly class TabularMapping
{
    /** The chosen column carries marks, out of a maximum the teacher states. */
    public const VALUE_POINTS = 'points';

    /** The chosen column already carries a percentage. */
    public const VALUE_PERCENTAGE = 'percentage';

    /**
     * @return list<string>
     */
    public static function valueKinds(): array
    {
        return [self::VALUE_POINTS, self::VALUE_PERCENTAGE];
    }

    /**
     * @param  list<string>  $resultColumns  Column letters, in the order the teacher sees them.
     */
    public function __construct(
        public ?string $sheet = null,
        /** 1-based, as the spreadsheet numbers it. */
        public ?int $headerRow = null,
        public ?string $studentColumn = null,
        public array $resultColumns = [],
        /**
         * How to read the global-result column. Only meaningful for the overall
         * mode: the other two read points and take their maxima from the cotações
         * the teacher sets per column, exactly as Intuitivo does (§16).
         */
        public string $valueKind = self::VALUE_POINTS,
        /**
         * What the global result is out of, when it is expressed in points.
         *
         * Never inferred from the marks observed. The largest mark in a class
         * being 17 is not evidence that the test was out of 17, and a cohort that
         * happened to do badly would silently rescale everyone (§23).
         */
        public ?string $overallMaximum = null,
        /**
         * An optional column carrying the source's own total, kept for
         * reconciliation. It is shown and compared, never substituted for what
         * LÁPIS computes (§31).
         */
        public ?string $totalColumn = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function fromArray(?array $snapshot): self
    {
        $snapshot ??= [];

        $headerRow = $snapshot['header_row'] ?? null;
        $valueKind = $snapshot['value_kind'] ?? null;

        return new self(
            sheet: self::stringOrNull($snapshot['sheet'] ?? null),
            headerRow: is_numeric($headerRow) && (int) $headerRow > 0 ? (int) $headerRow : null,
            studentColumn: self::columnOrNull($snapshot['student_column'] ?? null),
            resultColumns: self::columnsFrom($snapshot['result_columns'] ?? []),
            valueKind: is_string($valueKind) && in_array($valueKind, self::valueKinds(), true)
                ? $valueKind
                : self::VALUE_POINTS,
            overallMaximum: self::numberOrNull($snapshot['overall_maximum'] ?? null),
            totalColumn: self::columnOrNull($snapshot['total_column'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sheet' => $this->sheet,
            'header_row' => $this->headerRow,
            'student_column' => $this->studentColumn,
            'result_columns' => $this->resultColumns,
            'value_kind' => $this->valueKind,
            'overall_maximum' => $this->overallMaximum,
            'total_column' => $this->totalColumn,
        ];
    }

    /**
     * Whether the sheet has been described well enough to be read at all.
     *
     * The result mode decides what «enough» means, and the answer is deliberately
     * the same one the wizard shows: a teacher told the import is not ready has
     * to be able to see which of these is missing.
     */
    public function describesTheSheet(): bool
    {
        return $this->sheet !== null
            && $this->headerRow !== null
            && $this->studentColumn !== null
            && $this->resultColumns !== [];
    }

    public function isReadyFor(string $resultMode): bool
    {
        if (! $this->describesTheSheet()) {
            return false;
        }

        if ($resultMode !== ImportMapping::RESULT_OVERALL) {
            return true;
        }

        // One global result comes from one column, and a maximum has to be
        // stated unless the column is already a percentage.
        return count($this->resultColumns) === 1
            && ($this->valueKind === self::VALUE_PERCENTAGE || $this->overallMaximum !== null);
    }

    public function readsPercentages(): bool
    {
        return $this->valueKind === self::VALUE_PERCENTAGE;
    }

    /**
     * The columns that carry meaning, so a reader knows which ones to look at.
     *
     * @return list<string>
     */
    public function columnsInUse(): array
    {
        $columns = $this->resultColumns;

        foreach ([$this->studentColumn, $this->totalColumn] as $column) {
            if ($column !== null) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    protected static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected static function columnOrNull(mixed $value): ?string
    {
        $letter = self::stringOrNull($value);

        if ($letter === null) {
            return null;
        }

        $letter = strtoupper($letter);

        return preg_match('/^[A-Z]{1,3}$/', $letter) === 1 ? $letter : null;
    }

    protected static function numberOrNull(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        $value = self::stringOrNull($value);

        if ($value === null) {
            return null;
        }

        $normalised = str_replace(',', '.', $value);

        return is_numeric($normalised) ? $normalised : null;
    }

    /**
     * @return list<string>
     */
    protected static function columnsFrom(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $columns = [];

        foreach ($value as $entry) {
            $letter = self::columnOrNull($entry);

            // Order is the teacher's; duplicates are a request artefact and are
            // dropped rather than turned into two identical items.
            if ($letter !== null && ! in_array($letter, $columns, true)) {
                $columns[] = $letter;
            }
        }

        return $columns;
    }
}
