<?php

namespace App\Services\Evidence\Followup;

use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupCategory;
use App\Models\SchoolClass;
use App\Services\Lessons\ConsolidatedAbsences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * «Panorama da turma» — every student of ONE class in a window, with the four
 * follow-up indicators side by side: TPC não realizados, faltas de material,
 * atrasos and faltas de presença.
 *
 * Not a second way of counting. The three logbook indicators come from
 * RecordFollowupCounter (the same query the cards, lists, alerts and messages
 * use, grouped by student) and the absences from ConsolidatedAbsences (the
 * same rule as the card and the student's Evolução), so a column summed is
 * exactly the card's number for the same class and window.
 *
 * Everything is aggregated in a handful of grouped queries — never one query
 * per student, never the history sent to the browser.
 *
 * A number is only shown where it means something:
 * - TPC: `null` («sem verificações») when the student has no homework marked in
 *   the window — no record is not «did it» and not «zero not done».
 * - Faltas de material / atrasos: these are recorded when they happen, so 0
 *   is «nenhuma registada» (said so on screen, never «cumpriu»).
 * - Faltas de presença: `null` with `no_data` when no consolidated attendance
 *   of the student exists in the window, `not_applicable` when the
 *   organization has no access to the lessons module.
 */
final class ClassRecordPanorama
{
    public const string ABSENCES = RecordFollowupPresenter::ABSENCES;

    public const string NO_DATA = 'no_data';

    public const string NOT_APPLICABLE = 'not_applicable';

    public function __construct(
        private readonly RecordFollowupCounter $counter,
        private readonly ConsolidatedAbsences $absences,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>, meta: array<string, mixed>}
     */
    public function for(SchoolClass $class, FollowupWindow $window, bool $attendanceApplicable): array
    {
        $classIds = [$class->id];

        $recordCounts = $this->recordCounts($classIds, $window);
        $homework = $this->homeworkBreakdown($class, $window);
        $absences = $attendanceApplicable
            ? $this->absences->perStudent($classIds, $window)->keyBy('enrollment_id')
            : collect();
        $recorded = $attendanceApplicable ? $this->recordedAttendance($class, $window) : collect();

        $countedIds = array_values(array_unique(array_map('intval', array_merge(
            array_keys($recordCounts),
            $homework->keys()->all(),
            $absences->keys()->all(),
            $recorded->keys()->all(),
        ))));

        $cases = $this->cases($class, $window);
        $enrollments = $this->roster($class, $window, $countedIds);

        $rows = [];
        $totals = ['homework_not_done' => 0, 'missing_material' => 0, 'lateness' => 0, self::ABSENCES => 0];

        foreach ($enrollments as $enrollment) {
            $counts = $recordCounts[$enrollment->id] ?? [];
            $breakdown = $homework->get($enrollment->id, ['done' => 0, 'partially_done' => 0, 'not_done' => 0, 'verified' => 0]);
            $absence = $absences->get($enrollment->id);
            $recordedLessons = (int) $recorded->get($enrollment->id, 0);

            $notDone = $counts[RecordFollowupCategory::HomeworkNotDone->value] ?? 0;
            $material = $counts[RecordFollowupCategory::MissingMaterial->value] ?? 0;
            $lateness = $counts[RecordFollowupCategory::Lateness->value] ?? 0;
            $absenceCount = (int) ($absence['occurrences'] ?? 0);

            $missing = [];

            if ($breakdown['verified'] === 0 && $notDone === 0) {
                $missing['homework_not_done'] = self::NO_DATA;
            }

            if (! $attendanceApplicable) {
                $missing[self::ABSENCES] = self::NOT_APPLICABLE;
            } elseif ($recordedLessons === 0 && $absenceCount === 0) {
                $missing[self::ABSENCES] = self::NO_DATA;
            }

            $statuses = [];

            foreach ([RecordFollowupCategory::HomeworkNotDone, RecordFollowupCategory::MissingMaterial, RecordFollowupCategory::Lateness] as $category) {
                $count = $counts[$category->value] ?? 0;
                $status = $window->isPeriod() ? RecordFollowupCaseBook::status($count, $cases->get($enrollment->id.':'.$category->value)) : null;
                $statuses[$category->value] = $status === RecordFollowupCaseBook::STATUS_NONE ? null : $status;
            }

            $totals['homework_not_done'] += $notDone;
            $totals['missing_material'] += $material;
            $totals['lateness'] += $lateness;
            $totals[self::ABSENCES] += $absenceCount;

            $enrolledOn = $enrollment->enrolled_on->format('Y-m-d');
            $leftOn = $enrollment->left_on?->format('Y-m-d');

            $rows[] = [
                'ulid' => $enrollment->ulid,
                'number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? (string) __('(sem identidade)'),
                'enrolled_on' => $enrolledOn,
                'left_on' => $leftOn,
                // Entered or left during the window: the counts only cover the
                // part of it the student was in the class.
                'partial' => $enrolledOn > $window->from()->toDateString() || ($leftOn !== null && $leftOn < $window->to()->toDateString()),
                'counts' => [
                    'homework_not_done' => isset($missing['homework_not_done']) ? null : $notDone,
                    'missing_material' => $material,
                    'lateness' => $lateness,
                    self::ABSENCES => isset($missing[self::ABSENCES]) ? null : $absenceCount,
                ],
                'missing' => $missing,
                'homework' => $breakdown,
                'absence_days' => (int) ($absence['days'] ?? 0),
                'attendance_recorded' => $recordedLessons,
                'statuses' => $statuses,
                'has_occurrences' => $notDone + $material + $lateness + $absenceCount > 0,
            ];
        }

        usort($rows, fn (array $left, array $right): int => [$left['number'] ?? PHP_INT_MAX, $left['name'], $left['ulid']]
            <=> [$right['number'] ?? PHP_INT_MAX, $right['name'], $right['ulid']]);

        return [
            'rows' => $rows,
            'totals' => $totals,
            'meta' => [
                'is_period' => $window->isPeriod(),
                'attendance_applicable' => $attendanceApplicable,
                'homework_days' => $this->homeworkDays($class, $window),
                'lessons_counted' => $attendanceApplicable ? $this->countedLessons($class, $window) : 0,
                'lessons_not_recorded' => $attendanceApplicable ? $this->lessonsWithoutAttendance($class, $window) : 0,
            ],
        ];
    }

    /**
     * Occurrences per student and category, in ONE grouped query over the
     * counter's own occurrence set.
     *
     * @param  list<int>  $classIds
     * @return array<int, array<string, int>>
     */
    private function recordCounts(array $classIds, FollowupWindow $window): array
    {
        $counts = [];

        $rows = $this->counter->occurrences($classIds, $window)
            ->toBase()
            ->select('evidence_records.enrollment_id')
            ->selectRaw(RecordFollowupCategory::SQL_EXPRESSION.' as category')
            ->selectRaw('count(*) as occurrences')
            ->groupBy('evidence_records.enrollment_id')
            ->groupByRaw(RecordFollowupCategory::SQL_EXPRESSION)
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->enrollment_id][(string) $row->category] = (int) $row->occurrences;
        }

        return $counts;
    }

    /**
     * Every homework marking per student in the window, by state: what the
     * teacher actually verified. `verified` counts every marking, a state-less
     * legacy row included.
     *
     * @return Collection<int, array{done: int, partially_done: int, not_done: int, verified: int}>
     */
    private function homeworkBreakdown(SchoolClass $class, FollowupWindow $window): Collection
    {
        $rows = $this->homeworkQuery($class, $window)
            ->toBase()
            ->select('evidence_records.enrollment_id', 'evidence_records.homework_status')
            ->selectRaw('count(*) as total')
            ->groupBy('evidence_records.enrollment_id', 'evidence_records.homework_status')
            ->get();

        $breakdown = [];

        foreach ($rows as $row) {
            $id = (int) $row->enrollment_id;
            $breakdown[$id] ??= ['done' => 0, 'partially_done' => 0, 'not_done' => 0, 'verified' => 0];
            $status = HomeworkStatus::tryFrom((string) $row->homework_status);

            if ($status !== null) {
                $breakdown[$id][$status->value] += (int) $row->total;
            }

            $breakdown[$id]['verified'] += (int) $row->total;
        }

        return collect($breakdown);
    }

    /**
     * How many days of the window have homework marked for the class — the
     * days the teacher used the grid (or the form), not «days TPC was set»,
     * which the application does not know.
     */
    private function homeworkDays(SchoolClass $class, FollowupWindow $window): int
    {
        return (int) $this->homeworkQuery($class, $window)
            ->toBase()
            ->selectRaw('count(distinct date(evidence_records.occurred_at)) as days')
            ->value('days');
    }

    /**
     * @return Builder<EvidenceRecord>
     */
    private function homeworkQuery(SchoolClass $class, FollowupWindow $window): Builder
    {
        $query = EvidenceRecord::query()
            ->where('evidence_records.class_id', $class->id)
            ->whereNotNull('evidence_records.enrollment_id')
            ->where('evidence_records.kind', EvidenceKind::Homework->value);

        $window->applyTo($query);

        return $query;
    }

    /**
     * Consolidated attendance rows per student in the window (present or
     * absent) — whether there is anything to count at all.
     *
     * @return Collection<int, int>
     */
    private function recordedAttendance(SchoolClass $class, FollowupWindow $window): Collection
    {
        $query = LessonAttendance::query()
            ->join('lessons', 'lessons.id', '=', 'lesson_attendances.lesson_id')
            ->where('lessons.class_id', $class->id)
            ->where(fn (Builder $counts) => Lesson::whereAttendanceCounts($counts));

        $window->applyTo($query, 'lessons.starts_at');

        return $query->toBase()
            ->select('lesson_attendances.enrollment_id')
            ->selectRaw('count(*) as recorded')
            ->groupBy('lesson_attendances.enrollment_id')
            ->pluck('recorded', 'enrollment_id')
            ->map(fn ($recorded): int => (int) $recorded);
    }

    private function countedLessons(SchoolClass $class, FollowupWindow $window): int
    {
        $query = Lesson::query()
            ->where('lessons.class_id', $class->id)
            ->where(fn (Builder $counts) => Lesson::whereAttendanceCounts($counts));

        $window->applyTo($query, 'lessons.starts_at');

        return $query->count();
    }

    /**
     * Lessons taught with attendance applicable but not recorded yet — never
     * absences, said apart (the same definition as the student's Evolução).
     */
    private function lessonsWithoutAttendance(SchoolClass $class, FollowupWindow $window): int
    {
        $query = Lesson::query()
            ->where('lessons.class_id', $class->id)
            ->where(fn (Builder $taught) => Lesson::whereCountsAsTaughtWithAttendance($taught))
            ->whereNull('lessons.attendance_recorded_at');

        $window->applyTo($query, 'lessons.starts_at');

        return $query->count();
    }

    /**
     * @return Collection<string, RecordFollowupCase>
     */
    private function cases(SchoolClass $class, FollowupWindow $window): Collection
    {
        if ($window->period() === null) {
            return collect();
        }

        return RecordFollowupCase::query()
            ->where('class_id', $class->id)
            ->where('academic_period_id', $window->period()->id)
            ->with('activeConfirmation')
            ->get()
            ->keyBy(fn (RecordFollowupCase $case): string => $case->enrollment_id.':'.$case->category->value);
    }

    /**
     * Everyone who was in the class at some point of the window (the dated
     * rule of the attendance roll: current, or with a recorded leaving date —
     * an inactive enrollment without one is ambiguous and not guessed), plus
     * anyone with something counted in it, so no number ever goes missing
     * from the table.
     *
     * @param  list<int>  $countedIds
     * @return Collection<int, Enrollment>
     */
    private function roster(SchoolClass $class, FollowupWindow $window, array $countedIds): Collection
    {
        $from = $window->from()->toDateString();
        $to = $window->to()->toDateString();

        return Enrollment::query()
            ->where('class_id', $class->id)
            ->where(function (Builder $query) use ($from, $to, $countedIds): void {
                $query->where(function (Builder $dated) use ($from, $to): void {
                    $dated->whereDate('enrolled_on', '<=', $to)
                        ->where(fn (Builder $left) => $left->whereNull('left_on')->orWhereDate('left_on', '>=', $from))
                        ->where(fn (Builder $status) => $status->where('status', EnrollmentStatus::Active)->orWhereNotNull('left_on'));
                });

                if ($countedIds !== []) {
                    $query->orWhereIn('id', $countedIds);
                }
            })
            ->with('student.identity')
            ->get();
    }
}
