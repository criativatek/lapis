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
 * student who was in the class that day, with or without a marking.
 *
 * The model has no homework «batch»: class + kind + calendar date is the
 * grid's identity (one marking per student per day). The roster is the dated
 * rule of the attendance roll (LessonAttendanceRoster): enrolled on that day,
 * and either current or with a recorded leaving date — an inactive enrollment
 * without one is ambiguous, left out and counted, never guessed.
 *
 * A row the grid must not write is still shown, read-only, with the reason:
 * - `duplicate`: the student has more than one homework record that day
 *   (made one by one in the form). They are distinct records and are never
 *   merged by the date — they are corrected in the search, one by one;
 * - `left`: no longer in the class and nothing recorded that day — a new
 *   record is only ever about the class as it stands (the rule every new
 *   record follows). A record that already exists stays correctable.
 */
final class HomeworkGridRoster
{
    public const string LOCKED_DUPLICATE = 'duplicate';

    public const string LOCKED_LEFT = 'left';

    /**
     * @return array{rows: list<array<string, mixed>>, excluded_without_left_on: int}
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
        $excluded = 0;

        foreach ($candidates as $enrollment) {
            $own = $records->get($enrollment->id, collect());
            $current = $enrollment->status === EnrollmentStatus::Active;

            if ($own->isEmpty() && ! $current && $enrollment->left_on === null) {
                $excluded++;

                continue;
            }

            $record = $own->count() === 1 ? $own->first() : null;
            $locked = match (true) {
                $own->count() > 1 => self::LOCKED_DUPLICATE,
                $own->isEmpty() && ! $current => self::LOCKED_LEFT,
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
                'editable' => $locked === null,
                'locked_reason' => $locked,
                'left_on' => $enrollment->left_on?->format('Y-m-d'),
            ];
        }

        usort($rows, fn (array $left, array $right): int => [$left['number'] ?? PHP_INT_MAX, $left['name'], $left['id']]
            <=> [$right['number'] ?? PHP_INT_MAX, $right['name'], $right['id']]);

        return ['rows' => $rows, 'excluded_without_left_on' => $excluded];
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
     * Whether a NEW marking may be created for this student on that day:
     * current in the class and enrolled on the date.
     */
    public function acceptsNewMarking(Enrollment $enrollment, string $date): bool
    {
        if ($enrollment->status !== EnrollmentStatus::Active) {
            return false;
        }

        return $enrollment->enrolled_on->format('Y-m-d') <= $date
            && ($enrollment->left_on === null || $enrollment->left_on->format('Y-m-d') >= $date);
    }
}
