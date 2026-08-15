<?php

namespace App\Domain\Import\Correction;

/**
 * Every decision the teacher made in the wizard, in one object.
 *
 * It is stored as `mapping_snapshot` and read back on confirmation, so that
 * confirming is a matter of applying decisions already taken rather than of
 * re-deriving them. Nothing here is inferred at write time: if a value is
 * missing, the import is not ready, and that is a state the wizard shows rather
 * than a gap the persistence step fills in.
 *
 * `students` maps a source key to an enrolment id, or to null for a row the
 * teacher chose to ignore. Null is a decision, absence is not — an unmapped
 * student blocks readiness, an explicitly ignored one does not.
 */
final readonly class ImportMapping
{
    public const MODE_CREATE = 'create_new';

    public const MODE_ASSOCIATE = 'associate_existing';

    /** Keep whatever LÁPIS already has for a cell the file also carries. */
    public const CONFLICT_KEEP = 'keep';

    /** Replace it with what the file says. */
    public const CONFLICT_IMPORT = 'import';

    /**
     * Import the one result the platform already worked out for each student.
     * The default, and what a teacher wants almost every time (§1).
     */
    public const RESULT_OVERALL = 'overall';

    /**
     * Import the correction question by question, with cotações decided in
     * LÁPIS. Everything the source says about totals is then ignored — the
     * modes are separate arithmetic and are never blended (§8).
     */
    public const RESULT_PER_QUESTION = 'per_question';

    /**
     * One result per section of the test — «Grupo I», «Grupo II» — each counting
     * toward the domain the teacher assigns to it.
     *
     * This is what a multi-domain paper actually is: a single instrument whose
     * Grupo I assesses Leitura and whose Grupo III assesses Gramática. The
     * granularity sits between the other two, and for a source that states its
     * sections and their cotações it is the most faithful of the three — it
     * neither throws away the structure the file carries nor invents a
     * question-level precision the teacher did not ask for.
     *
     * Provider-neutral on purpose: nothing here says «Intuitivo». A source only
     * decides which mode the wizard OPENS on.
     */
    public const RESULT_PER_GROUP = 'per_group';

    /**
     * @return list<string>
     */
    public static function resultModes(): array
    {
        return [self::RESULT_OVERALL, self::RESULT_PER_GROUP, self::RESULT_PER_QUESTION];
    }

    /**
     * @param  array<string, int|null>  $students  source key => enrollment id, or null for "ignore this row"
     * @param  array<string, int>  $items  source key => instrument_item id (associate mode only)
     * @param  array<string, string>  $points  source key => points_possible, as a decimal string
     * @param  array<string, list<array{domain_id: int, allocation_percent: string}>>  $domains  source key => allocations
     * @param  array<string, string>  $conflicts  "enrollmentId:itemId" => CONFLICT_KEEP|CONFLICT_IMPORT
     * @param  array<string, mixed>  $instrumentAttributes  what the teacher filled in for a new instrument
     * @param  list<array{domain_id: int, allocation_percent: string}>  $overallDomains  where the global result counts
     * @param  int|null  $overallItemId  which existing item receives it, in associate mode
     * @param  array<string, list<array{domain_id: int, allocation_percent: string}>>  $groupDomains  source group key => where that section counts
     */
    public function __construct(
        public string $mode = self::MODE_CREATE,
        public ?int $instrumentId = null,
        public array $students = [],
        public array $items = [],
        public array $points = [],
        public array $domains = [],
        public array $conflicts = [],
        public array $instrumentAttributes = [],
        // Deliberately NOT the product default. Which mode a fresh import opens
        // in is a product decision and is stated where a fresh import is
        // created, in plain sight; a value object that quietly reinterpreted
        // every mapping built without this argument would change what stored
        // decisions mean.
        public string $resultMode = self::RESULT_PER_QUESTION,
        public array $overallDomains = [],
        public ?int $overallItemId = null,
        // Source group key => allocations. A group is structure and a domain is
        // pedagogy, so this is the teacher's decision and never the file's: a
        // source that names its sections «Leitura» has still said nothing about
        // curriculum. Several groups may point at the same domain.
        public array $groupDomains = [],
        // What the teacher said their own spreadsheet means — which sheet, which
        // row holds the headings, which column names the student. Empty for every
        // source whose file explains itself, and it stays empty: Plickers and
        // Intuitivo are never put through a mapping screen (§7).
        public TabularMapping $table = new TabularMapping,
    ) {}

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function fromArray(?array $snapshot): self
    {
        $snapshot ??= [];

        return new self(
            mode: is_string($snapshot['mode'] ?? null) ? $snapshot['mode'] : self::MODE_CREATE,
            instrumentId: isset($snapshot['instrument_id']) ? (int) $snapshot['instrument_id'] : null,
            students: (array) ($snapshot['students'] ?? []),
            items: (array) ($snapshot['items'] ?? []),
            points: (array) ($snapshot['points'] ?? []),
            domains: (array) ($snapshot['domains'] ?? []),
            conflicts: (array) ($snapshot['conflicts'] ?? []),
            instrumentAttributes: (array) ($snapshot['instrument'] ?? []),
            // An import stored before this field existed was a per-question one,
            // and re-reading it as an overall import would change what its own
            // snapshot means. Absence is answered by the old behaviour, never by
            // the new default.
            resultMode: is_string($snapshot['result_mode'] ?? null) ? $snapshot['result_mode'] : self::RESULT_PER_QUESTION,
            overallDomains: self::allocationsFrom($snapshot['overall_domains'] ?? []),
            overallItemId: isset($snapshot['overall_item_id']) ? (int) $snapshot['overall_item_id'] : null,
            groupDomains: self::allocationsPerKeyFrom($snapshot['group_domains'] ?? []),
            table: TabularMapping::fromArray(is_array($snapshot['table'] ?? null) ? $snapshot['table'] : null),
        );
    }

    /**
     * @return array<string, list<array{domain_id: int, allocation_percent: string}>>
     */
    protected static function allocationsPerKeyFrom(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $key => $allocations) {
            $clean = self::allocationsFrom($allocations);

            if ($clean !== []) {
                $rows[(string) $key] = $clean;
            }
        }

        return $rows;
    }

    /**
     * Rebuilds allocations from a snapshot that has been through JSON and back.
     *
     * A stored mapping is data, not a promise: it was written by an earlier
     * version of this class, possibly before a field existed. Anything that is
     * not a usable allocation is dropped here rather than carried forward to
     * fail somewhere that has no idea what it is looking at.
     *
     * @return list<array{domain_id: int, allocation_percent: string}>
     */
    protected static function allocationsFrom(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $allocation) {
            if (! is_array($allocation) || ! isset($allocation['domain_id'])) {
                continue;
            }

            $rows[] = [
                'domain_id' => (int) $allocation['domain_id'],
                'allocation_percent' => (string) ($allocation['allocation_percent'] ?? '100'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'instrument_id' => $this->instrumentId,
            'students' => $this->students,
            'items' => $this->items,
            'points' => $this->points,
            'domains' => $this->domains,
            'conflicts' => $this->conflicts,
            'instrument' => $this->instrumentAttributes,
            'result_mode' => $this->resultMode,
            'overall_domains' => $this->overallDomains,
            'overall_item_id' => $this->overallItemId,
            'group_domains' => $this->groupDomains,
            'table' => $this->table->toArray(),
        ];
    }

    /**
     * Whether the source's own structure still has to be described by hand.
     * Asked by the wizard and by the readiness rule, computed in neither.
     */
    public function describesATable(): bool
    {
        return $this->table->describesTheSheet();
    }

    public function createsInstrument(): bool
    {
        return $this->mode === self::MODE_CREATE;
    }

    /**
     * Whether this import carries one result per student rather than one per
     * answer. Asked in several places and computed in none of them.
     */
    public function importsOverallResult(): bool
    {
        return $this->resultMode === self::RESULT_OVERALL;
    }

    /**
     * Whether the global result has been told which domain it is evidence for.
     *
     * Only ever required when the instrument actually counts: an item with no
     * allocation is legitimate in the model (§4.3) and enters no domain, so
     * demanding one from an instrument that does not enter the calculation would
     * be paperwork. Demanding one from an instrument that does is the difference
     * between a result that counts and a result that silently counts for nothing.
     */
    public function overallDomainIsDecided(): bool
    {
        return $this->overallDomains !== [];
    }

    /**
     * Whether this import records one result per section of the test.
     */
    public function importsGroupResults(): bool
    {
        return $this->resultMode === self::RESULT_PER_GROUP;
    }

    /**
     * The domain allocations a source group was pointed at, or none.
     *
     * @return list<array{domain_id: int, allocation_percent: string}>
     */
    public function domainsForGroup(string $groupSourceKey): array
    {
        return $this->groupDomains[$groupSourceKey] ?? [];
    }

    /**
     * The enrolment a source row was pointed at, or null when the teacher chose
     * to leave that row out. Deliberately distinguishes "decided to ignore"
     * from "never decided": the second is not ready to import.
     */
    public function enrollmentFor(string $studentSourceKey): ?int
    {
        return $this->students[$studentSourceKey] ?? null;
    }

    public function wasDecided(string $studentSourceKey): bool
    {
        return array_key_exists($studentSourceKey, $this->students);
    }

    public function conflictChoice(int $enrollmentId, int $itemId): ?string
    {
        return $this->conflicts[$enrollmentId.':'.$itemId] ?? null;
    }
}
