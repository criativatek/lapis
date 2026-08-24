<?php

namespace App\Domain\Import\Timetable;

use App\Models\SchoolClass;

/**
 * Which turma a candidate row points at — or an honest admission that it is not
 * clear.
 */
final readonly class TimetableClassMatch
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_NOT_FOUND = 'not_found';

    /**
     * @param  list<SchoolClass>  $candidates  the plausible turmas, named — empty when nothing plausible was found
     */
    public function __construct(
        public string $status,
        public ?SchoolClass $schoolClass,
        public array $candidates = [],
    ) {}

    public function matched(): bool
    {
        return $this->status === self::STATUS_MATCHED && $this->schoolClass !== null;
    }
}
