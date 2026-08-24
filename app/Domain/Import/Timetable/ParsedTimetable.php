<?php

namespace App\Domain\Import\Timetable;

/**
 * Everything the interpretation layer could read out of one timetable export.
 *
 * The header facts are best-effort and nullable on purpose: a missing academic
 * year costs the teacher one warning they will not see, and is never a reason to
 * refuse an import that is otherwise perfectly readable.
 */
final readonly class ParsedTimetable
{
    /**
     * @param  list<TimetableCandidateRow>  $rows
     * @param  list<string>  $weekdayLabels  the column headings, left to right
     * @param  string|null  $academicYearLabel  as printed, e.g. «2025/26»
     * @param  string|null  $academicYearNormalised  expanded to this app's own format, e.g. «2025/2026»
     */
    public function __construct(
        public array $rows,
        public int $timeRowCount,
        public array $weekdayLabels,
        public ?string $academicYearLabel = null,
        public ?string $academicYearNormalised = null,
        public ?string $teacherName = null,
    ) {}
}
