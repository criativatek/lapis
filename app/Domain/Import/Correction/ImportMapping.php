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
     * @param  array<string, int|null>  $students  source key => enrollment id, or null for "ignore this row"
     * @param  array<string, int>  $items  source key => instrument_item id (associate mode only)
     * @param  array<string, string>  $points  source key => points_possible, as a decimal string
     * @param  array<string, list<array{domain_id: int, allocation_percent: string}>>  $domains  source key => allocations
     * @param  array<string, string>  $conflicts  "enrollmentId:itemId" => CONFLICT_KEEP|CONFLICT_IMPORT
     * @param  array<string, mixed>  $instrumentAttributes  what the teacher filled in for a new instrument
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
        );
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
        ];
    }

    public function createsInstrument(): bool
    {
        return $this->mode === self::MODE_CREATE;
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
