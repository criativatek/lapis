<?php

namespace App\Services\Evidence;

use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The rows of the homework grid for one class and one calendar day — EVERY
 * student who belonged to the class that day, with or without a marking.
 *
 * The model has no homework «batch»: class + kind + calendar date is the
 * grid's identity (one marking per student per day).
 *
 * WHO MAY BE MARKED is a question about THE DATE, never about today: a student
 * who belonged to the class on that day may receive that day's marking, even
 * after leaving; a student who did not belong to it that day may not. Whether
 * they belonged comes from the enrollment's own dates and status, the rule of
 * the attendance roll (LessonAttendanceRoster):
 * - `enrolled_on` ≤ date and (no `left_on`, or `left_on` ≥ date), AND
 * - current, or with a recorded leaving date.
 * An enrollment that is not current and has NO leaving date cannot answer —
 * it may have ended before or after the day. It is shown, read-only, with that
 * concrete reason (`undetermined`), and never treated as eligible.
 *
 * A row the grid must not write is still shown, read-only, with the reason:
 * - `duplicate`: two homework records of that day (made one by one in the
 *   form). Distinct records, never merged nor one picked — corrected in the
 *   search, one by one;
 * - `undetermined`: as above. A marking that already exists stays correctable.
 *
 * Every row carries `version`: a reference to the marking as it was read.
 * The save compares it, per student, with what is there at that moment, so
 * a change made meanwhile by someone else is never overwritten silently.
 */
final class HomeworkGridRoster
{
    public const string LOCKED_DUPLICATE = 'duplicate';

    public const string LOCKED_UNDETERMINED = 'undetermined';

    public const string ELIGIBLE = 'eligible';

    public const string UNDETERMINED = 'undetermined';

    public const string NOT_ENROLLED = 'not_enrolled';

    /** The version of «no marking that day». */
    public const string VERSION_NONE = 'none';

    /**
     * @return array{rows: list<array<string, mixed>>}
     */
    public function for(SchoolClass $class, string $date): array
    {
        /** @var Collection<int, Collection<int, EvidenceRecord>> $records */
        $records = $this->recordsOn($class, $date)->get()->groupBy('enrollment_id');

        $candidates = Enrollment::query()
            ->where('class_id', $class->id)
            ->where(function (Builder $query) use ($date, $records): void {
                $query->where(fn (Builder $dated) => $dated->enrolledOn($date));

                if ($records->isNotEmpty()) {
                    $query->orWhereIn('id', $records->keys()->all());
                }
            })
            ->with('student.identity')
            ->get();

        $rows = [];

        foreach ($candidates as $enrollment) {
            $own = $records->get($enrollment->id, collect());
            $eligibility = $this->eligibilityOn($enrollment, $date);
            $record = $own->count() === 1 ? $own->first() : null;
            $locked = match (true) {
                $own->count() > 1 => self::LOCKED_DUPLICATE,
                $own->isEmpty() && $eligibility === self::UNDETERMINED => self::LOCKED_UNDETERMINED,
                default => null,
            };

            $rows[] = [
                'id' => $enrollment->id,
                'ulid' => $enrollment->ulid,
                'number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? (string) __('(sem identidade)'),
                'homework_status' => $record?->homework_status?->value,
                'description' => $record === null ? '' : $record->description,
                'record_ulid' => $record?->ulid,
                'record_count' => $own->count(),
                'version' => $this->versionOf($own),
                'editable' => $locked === null,
                'locked_reason' => $locked,
                'eligibility' => $eligibility,
                'enrollment_status' => $enrollment->status->value,
                'enrollment_status_label' => $enrollment->status->label(),
                'enrolled_on' => $enrollment->enrolled_on->format('Y-m-d'),
                'left_on' => $enrollment->left_on?->format('Y-m-d'),
            ];
        }

        usort($rows, fn (array $left, array $right): int => [$left['number'] ?? PHP_INT_MAX, $left['name'], $left['id']]
            <=> [$right['number'] ?? PHP_INT_MAX, $right['name'], $right['id']]);

        return ['rows' => $rows];
    }

    /**
     * The homework records of the class on that calendar day (soft-deleted
     * ones never included).
     *
     * @return Builder<EvidenceRecord>
     */
    public function recordsOn(SchoolClass $class, string $date): Builder
    {
        return EvidenceRecord::query()
            ->forClass($class->id)
            ->where('kind', EvidenceKind::Homework->value)
            ->whereDate('occurred_at', $date)
            ->whereNotNull('enrollment_id')
            ->orderBy('id');
    }

    /**
     * Whether the student belonged to the class ON THAT DAY — `eligible`,
     * `not_enrolled` (outside the enrollment's dates) or `undetermined` (not
     * current and no leaving date: the data cannot say).
     */
    public function eligibilityOn(Enrollment $enrollment, string $date): string
    {
        $enrolledOn = $enrollment->enrolled_on->format('Y-m-d');
        $leftOn = $enrollment->left_on?->format('Y-m-d');

        if ($enrolledOn > $date || ($leftOn !== null && $leftOn < $date)) {
            return self::NOT_ENROLLED;
        }

        if ($enrollment->status === EnrollmentStatus::Active || $leftOn !== null) {
            return self::ELIGIBLE;
        }

        return self::UNDETERMINED;
    }

    /**
     * The reference to a student's marking as it is now: `none` when there is
     * no marking that day, else a digest of the record, its state, its
     * observation and its last change. Equal versions mean nothing changed in
     * between; the content is part of it, so two edits within the same second
     * of `updated_at` are still told apart.
     *
     * @param  Collection<int, EvidenceRecord>  $own
     */
    public function versionOf(Collection $own): string
    {
        if ($own->isEmpty()) {
            return self::VERSION_NONE;
        }

        if ($own->count() > 1) {
            return 'duplicate:'.$own->count();
        }

        /** @var EvidenceRecord $record */
        $record = $own->first();

        return 'r:'.substr(sha1(implode('|', [
            $record->ulid,
            $record->homework_status->value ?? '',
            $record->description,
            $record->updated_at?->format('Y-m-d H:i:s') ?? '',
        ])), 0, 20);
    }
}
