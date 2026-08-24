<?php

namespace App\Domain\Import\Timetable;

/**
 * One block of the timetable as the FILE describes it — never as LÁPIS knows it.
 *
 * Every field here belongs to the PDF: `classRaw` is the string "7º C" that
 * somebody's school-management system printed, not a SchoolClass; `subjectRaw`
 * is "PORT", not a Subject. Nothing in this object has been matched, resolved or
 * saved, and keeping it that way is what makes matching an explicit, reviewable
 * step rather than something that happens quietly inside a parser.
 *
 * `rawText` is the cell exactly as it was reconstructed, kept even when the
 * subject/class/room split succeeded. The split is a heuristic and can be wrong;
 * the original text is what lets a teacher see that for themselves.
 */
final readonly class TimetableCandidateRow
{
    public function __construct(
        /** The weekday as the file writes it, e.g. «2ª FEIRA». */
        public string $weekdayLabel,
        /** ISO-8601 weekday, 1 = Monday … 7 = Sunday — what RecurringLessonSlot stores. */
        public int $dayOfWeek,
        public string $startsAt,
        public string $endsAt,
        public string $rawText,
        public ?string $subjectRaw = null,
        public ?string $classRaw = null,
        public ?string $roomRaw = null,
    ) {}

    /**
     * Whether this block even looks like an ordinary lesson with a class.
     *
     * False for everything the file uses the same grid to record that is NOT a
     * turma — a support block, a coordination hour, a co-teaching code. Those
     * are shown to the teacher and never matched against a class, because a
     * confident wrong answer here writes a schedule the teacher does not have.
     */
    public function isCurricularCandidate(): bool
    {
        return $this->classRaw !== null;
    }
}
