<?php

namespace App\Services\Lessons;

use App\Models\AttendanceStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Services\Evidence\Followup\FollowupWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * «Faltas de presença» for the Registos consultation — counted from the
 * attendance the teacher registered in the lessons, never from a second copy
 * (no logbook entry represents an absence).
 *
 * ONE ABSENCE = ONE `lesson_attendances` ROW WITH `absent`, in a lesson whose
 * attendance COUNTS (`Lesson::whereAttendanceCounts()`, the same rule as the
 * student's Evolução and the reports): consolidated, and a lesson that took
 * place. Attendance still to fill in is not an absence, a draft absence in a
 * lesson not yet closed is not one either, and a lateness (a logbook entry) is
 * never one. Two lessons (tempos) on the same day are two absences on one
 * day — both numbers are given, never one passed off as the other.
 *
 * The date is the lesson's own (`starts_at`, local wall-clock time). A
 * correction (CorrectLessonAttendance) or an annulment of the lesson
 * (outcome changed, lesson deleted) shows at once: everything is read live.
 *
 * The model has no justified/unjustified distinction — none is presumed.
 *
 * Callers pass the class ids they may read; this class does not authorize.
 */
final class ConsolidatedAbsences
{
    /**
     * @param  list<int>  $classIds
     * @return Builder<LessonAttendance>
     */
    public function query(array $classIds, FollowupWindow $window, ?int $enrollmentId = null): Builder
    {
        $query = LessonAttendance::query()
            ->join('lessons', 'lessons.id', '=', 'lesson_attendances.lesson_id')
            ->where('lesson_attendances.status', AttendanceStatus::Absent->value)
            ->whereIn('lessons.class_id', $classIds)
            ->when($enrollmentId !== null, fn (Builder $query) => $query->where('lesson_attendances.enrollment_id', $enrollmentId));

        $query->where(fn (Builder $counts) => Lesson::whereAttendanceCounts($counts));
        $window->applyTo($query, 'lessons.starts_at');

        return $query;
    }

    /**
     * Distinct students (the student identity), cases (student × class) and
     * absences in the window.
     *
     * @param  list<int>  $classIds
     * @return array{students: int, cases: int, absences: int}
     */
    public function summary(array $classIds, FollowupWindow $window, ?int $enrollmentId = null): array
    {
        if ($classIds === []) {
            return ['students' => 0, 'cases' => 0, 'absences' => 0];
        }

        $row = $this->query($classIds, $window, $enrollmentId)
            ->toBase()
            ->selectRaw('count(*) as absences')
            ->selectRaw('count(distinct lesson_attendances.enrollment_id) as cases')
            ->selectRaw('count(distinct lesson_attendances.student_id) as students')
            ->first();

        return [
            'students' => (int) ($row->students ?? 0),
            'cases' => (int) ($row->cases ?? 0),
            'absences' => (int) ($row->absences ?? 0),
        ];
    }

    /**
     * One row per (class, enrollment) with at least one absence: how many
     * absences and on how many distinct days.
     *
     * @param  list<int>  $classIds
     * @param  list<int>|null  $enrollmentIds
     * @return Collection<int, array{class_id: int, enrollment_id: int, occurrences: int, days: int, first_at: string, last_at: string}>
     */
    public function perStudent(array $classIds, FollowupWindow $window, ?array $enrollmentIds = null): Collection
    {
        if ($classIds === [] || $enrollmentIds === []) {
            return collect();
        }

        return $this->query($classIds, $window)
            ->when($enrollmentIds !== null, fn (Builder $query) => $query->whereIn('lesson_attendances.enrollment_id', $enrollmentIds))
            ->toBase()
            ->select('lessons.class_id', 'lesson_attendances.enrollment_id')
            ->selectRaw('count(*) as occurrences')
            ->selectRaw('count(distinct date(lessons.starts_at)) as days')
            ->selectRaw('min(lessons.starts_at) as first_at')
            ->selectRaw('max(lessons.starts_at) as last_at')
            ->groupBy('lessons.class_id', 'lesson_attendances.enrollment_id')
            ->get()
            ->map(fn (object $row): array => [
                'class_id' => (int) $row->class_id,
                'enrollment_id' => (int) $row->enrollment_id,
                'occurrences' => (int) $row->occurrences,
                'days' => (int) $row->days,
                'first_at' => (string) $row->first_at,
                'last_at' => (string) $row->last_at,
            ]);
    }

    /**
     * The absences of one student in one class, oldest first, with their
     * lessons — the exact set the detail lists.
     *
     * @return Collection<int, LessonAttendance>
     */
    public function caseAbsences(int $classId, int $enrollmentId, FollowupWindow $window): Collection
    {
        return $this->query([$classId], $window, $enrollmentId)
            ->select('lesson_attendances.*')
            ->with(['lesson.classGroup', 'lesson.schoolClass'])
            ->orderBy('lessons.starts_at')
            ->orderBy('lessons.id')
            ->get();
    }
}
