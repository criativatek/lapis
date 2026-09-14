<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\CancelledLessonOccurrence;
use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonPlan;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\RecurringLessonSlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Writes the lessons/attendance tier a validated backup's plan already
 * classified (schema_version 9, docs/backup-schema.md). Split out of
 * `ExecuteDataImport` purely to keep each file small enough for PHPStan to
 * analyse, and run LAST (after `WritePedagogicalRecords`) because it needs
 * only classes/enrollments, never anything the calculation engine produces.
 *
 * Order matches the plan's own dependency order: groups → memberships →
 * slots → cancelled → lessons → summaries → plans → attendances.
 *
 * `forceFill` throughout, `preserve_ulid` respected exactly like every other
 * writer in this pipeline — never LessonNumbering, never
 * MaterializeLessonsForRange: a restored `lesson_number` is copied verbatim
 * (it is a frozen fact the teacher already saw), not recomputed.
 *
 * AN OPTIONAL PARENT THAT DOES NOT RESOLVE SKIPS THE ROW, NEVER DEGRADES IT.
 * `class_group_id`/`recurring_lesson_slot_id` are nullable columns — NULL is
 * a legitimate, meaningful value ("the whole class", "not tied to a
 * schedule"). But a row whose backup carried a NON-null `class_group_ulid`/
 * `recurring_lesson_slot_ulid` and whose parent simply is not in `byUlid`
 * (the plan judged it unsafe — a `conflict`, or genuinely absent) must never
 * fall back to that same NULL: writing a T1 lesson with `class_group_id`
 * NULL would silently turn it into a whole-class lesson, changing exactly
 * who `LessonAttendanceRoster` considers eligible for it. `resolveOptionalId()`
 * is what tells the two cases apart — the plan side already refuses to mark
 * such a row `new` in the first place (`BuildLessonsPlan`), so this is
 * defence in depth, not the only guard.
 *
 * SAME-CLASS INTEGRITY IS RE-CHECKED HERE TOO, not only trusted from the
 * plan. `BuildLessonsPlan` already refuses to classify a row `new` when its
 * group/slot/enrollment belongs to a different class (see that class's own
 * docblock) — this is the second, independent guard the same discipline as
 * `resolveOptionalId()` above asks for: a row is skipped, never written
 * under a class it does not actually belong to, even if a future change to
 * the plan side ever regressed that check. `classIdMap()` batches one query
 * per parent type, never one per row.
 */
class WriteLessons
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<string, int>  $byUlid
     * @return array{ok: bool, id: int|null}
     */
    private function resolveOptionalId(?string $ulid, array $byUlid): array
    {
        if ($ulid === null) {
            return ['ok' => true, 'id' => null];
        }

        $id = $byUlid[$ulid] ?? null;

        return ['ok' => $id !== null, 'id' => $id];
    }

    /**
     * The real `class_id` of every parent row this batch just resolved
     * (created or matched to an existing one), keyed by that row's own
     * destination id — one query, never one per row.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, int>  $byUlid
     * @return Collection<int, int>
     */
    private function classIdMap(string $modelClass, array $byUlid): Collection
    {
        $ids = array_values(array_unique($byUlid));

        return $ids === [] ? collect() : $modelClass::query()->whereIn('id', $ids)->pluck('class_id', 'id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $groupRows
     * @param  array<int, array<string, mixed>>  $membershipRows
     * @param  array<int, array<string, mixed>>  $slotRows
     * @param  array<int, array<string, mixed>>  $cancelledRows
     * @param  array<int, array<string, mixed>>  $lessonRows
     * @param  array<int, array<string, mixed>>  $summaryRows
     * @param  array<int, array<string, mixed>>  $planRows
     * @param  array<int, array<string, mixed>>  $attendanceRows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $enrollmentsByUlid
     * @return array<string, int>
     */
    public function write(
        array $groupRows,
        array $membershipRows,
        array $slotRows,
        array $cancelledRows,
        array $lessonRows,
        array $summaryRows,
        array $planRows,
        array $attendanceRows,
        Organization $organization,
        array $classesByUlid,
        array $enrollmentsByUlid,
    ): array {
        $enrollmentClassIds = $this->classIdMap(Enrollment::class, $enrollmentsByUlid);

        ['byUlid' => $groupsByUlid, 'created' => $groupsCreated] = $this->writeClassGroups($groupRows, $classesByUlid);
        $groupClassIds = $this->classIdMap(ClassGroup::class, $groupsByUlid);

        $membershipsCreated = $this->writeMemberships($membershipRows, $groupsByUlid, $enrollmentsByUlid, $groupClassIds, $enrollmentClassIds);

        ['byUlid' => $slotsByUlid, 'created' => $slotsCreated] = $this->writeSlots($slotRows, $classesByUlid, $groupsByUlid, $groupClassIds);
        $slotClassIds = $this->classIdMap(RecurringLessonSlot::class, $slotsByUlid);

        $cancelledCreated = $this->writeCancelledOccurrences($cancelledRows, $classesByUlid, $groupsByUlid, $slotsByUlid, $groupClassIds, $slotClassIds);
        ['byUlid' => $lessonsByUlid, 'created' => $lessonsCreated] = $this->writeLessons($lessonRows, $classesByUlid, $groupsByUlid, $slotsByUlid, $groupClassIds, $slotClassIds);
        $lessonClassIds = $this->classIdMap(Lesson::class, $lessonsByUlid);

        $summariesCreated = $this->writeSummaries($summaryRows, $lessonsByUlid);
        $plansCreated = $this->writePlans($planRows, $lessonsByUlid);
        $attendancesCreated = $this->writeAttendances($attendanceRows, $lessonsByUlid, $enrollmentsByUlid, $lessonClassIds, $enrollmentClassIds);

        return [
            'class_groups' => $groupsCreated,
            'class_group_memberships' => $membershipsCreated,
            'recurring_lesson_slots' => $slotsCreated,
            'cancelled_lesson_occurrences' => $cancelledCreated,
            'lessons' => $lessonsCreated,
            'lesson_summaries' => $summariesCreated,
            'lesson_plans' => $plansCreated,
            'lesson_attendances' => $attendancesCreated,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeClassGroups(array $rows, array $classesByUlid): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $classId = $this->resolveId($row['class_ulid'], $classesByUlid);

                if ($classId === null) {
                    continue;
                }

                $group = new ClassGroup;
                $group->forceFill([
                    'ulid' => $this->writableUlid($row), 'class_id' => $classId, 'label' => $row['label'],
                    'position' => $row['position'], 'archived_at' => $row['archived_at'],
                ]);
                $group->save();
                $byUlid[$row['ulid']] = $group->getKey();
                $created++;
            } elseif ($row['classification'] === 'existing' && isset($row['existing_id'])) {
                // Deliberately NOT `conflict` — see the class docblock.
                // A conflicting group is never a safe parent for a child
                // this same run might otherwise write.
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $groupsByUlid
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  Collection<int, int>  $groupClassIds
     * @param  Collection<int, int>  $enrollmentClassIds
     */
    private function writeMemberships(array $rows, array $groupsByUlid, array $enrollmentsByUlid, Collection $groupClassIds, Collection $enrollmentClassIds): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $groupId = $this->resolveId($row['class_group_ulid'], $groupsByUlid);
            $enrollmentId = $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid);

            if ($groupId === null || $enrollmentId === null) {
                continue;
            }

            if ($groupClassIds->get($groupId) !== $enrollmentClassIds->get($enrollmentId)) {
                continue;
            }

            $membership = new ClassGroupMembership;
            $membership->forceFill([
                'ulid' => $this->writableUlid($row), 'class_group_id' => $groupId, 'enrollment_id' => $enrollmentId,
                'effective_from' => $row['effective_from'], 'effective_until' => $row['effective_until'],
            ]);
            $membership->save();
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $groupsByUlid
     * @param  Collection<int, int>  $groupClassIds
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeSlots(array $rows, array $classesByUlid, array $groupsByUlid, Collection $groupClassIds): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $classId = $this->resolveId($row['class_ulid'], $classesByUlid);

                if ($classId === null) {
                    continue;
                }

                $groupResolution = $this->resolveOptionalId($row['class_group_ulid'], $groupsByUlid);

                if (! $groupResolution['ok']) {
                    continue;
                }

                if ($groupResolution['id'] !== null && $groupClassIds->get($groupResolution['id']) !== $classId) {
                    continue;
                }

                $slot = new RecurringLessonSlot;
                $slot->forceFill([
                    'ulid' => $this->writableUlid($row), 'class_id' => $classId,
                    'class_group_id' => $groupResolution['id'],
                    'day_of_week' => $row['day_of_week'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'],
                    'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'],
                    'split_lesson_key' => $row['split_lesson_key'],
                ]);
                $slot->save();
                $byUlid[$row['ulid']] = $slot->getKey();
                $created++;
            } elseif ($row['classification'] === 'existing' && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $groupsByUlid
     * @param  array<string, int>  $slotsByUlid
     * @param  Collection<int, int>  $groupClassIds
     * @param  Collection<int, int>  $slotClassIds
     */
    private function writeCancelledOccurrences(array $rows, array $classesByUlid, array $groupsByUlid, array $slotsByUlid, Collection $groupClassIds, Collection $slotClassIds): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $classId = $this->resolveId($row['class_ulid'], $classesByUlid);
            $slotId = $this->resolveId($row['recurring_lesson_slot_ulid'], $slotsByUlid);
            $groupResolution = $this->resolveOptionalId($row['class_group_ulid'], $groupsByUlid);

            if ($classId === null || $slotId === null || ! $groupResolution['ok']) {
                continue;
            }

            if ($slotClassIds->get($slotId) !== $classId) {
                continue;
            }

            if ($groupResolution['id'] !== null && $groupClassIds->get($groupResolution['id']) !== $classId) {
                continue;
            }

            $occurrence = new CancelledLessonOccurrence;
            $occurrence->forceFill([
                'class_id' => $classId, 'class_group_id' => $groupResolution['id'],
                'recurring_lesson_slot_id' => $slotId, 'occurs_at' => $row['occurs_at'], 'cancelled_by' => $row['cancelled_by'],
            ]);
            $occurrence->save();
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid
     * @param  array<string, int>  $groupsByUlid
     * @param  array<string, int>  $slotsByUlid
     * @param  Collection<int, int>  $groupClassIds
     * @param  Collection<int, int>  $slotClassIds
     * @return array{byUlid: array<string, int>, created: int}
     */
    private function writeLessons(array $rows, array $classesByUlid, array $groupsByUlid, array $slotsByUlid, Collection $groupClassIds, Collection $slotClassIds): array
    {
        $byUlid = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] === 'new') {
                $classId = $this->resolveId($row['class_ulid'], $classesByUlid);

                if ($classId === null) {
                    continue;
                }

                $groupResolution = $this->resolveOptionalId($row['class_group_ulid'], $groupsByUlid);
                $slotResolution = $this->resolveOptionalId($row['recurring_lesson_slot_ulid'], $slotsByUlid);

                if (! $groupResolution['ok'] || ! $slotResolution['ok']) {
                    continue;
                }

                if ($groupResolution['id'] !== null && $groupClassIds->get($groupResolution['id']) !== $classId) {
                    continue;
                }

                if ($slotResolution['id'] !== null && $slotClassIds->get($slotResolution['id']) !== $classId) {
                    continue;
                }

                $lesson = new Lesson;
                $lesson->forceFill([
                    'ulid' => $this->writableUlid($row), 'class_id' => $classId,
                    'class_group_id' => $groupResolution['id'],
                    'recurring_lesson_slot_id' => $slotResolution['id'],
                    'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'lesson_number' => $row['lesson_number'],
                    'lesson_unit_key' => $row['lesson_unit_key'],
                    'status' => $row['status'], 'attendance_recorded_at' => $row['attendance_recorded_at'],
                    'attendance_recorded_by' => $row['attendance_recorded_by'], 'created_by' => $row['created_by'],
                ]);
                $lesson->save();
                $byUlid[$row['ulid']] = $lesson->getKey();
                $created++;
            } elseif ($row['classification'] === 'existing' && isset($row['existing_id'])) {
                $byUlid[$row['ulid']] = (int) $row['existing_id'];
            }
        }

        return ['byUlid' => $byUlid, 'created' => $created];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $lessonsByUlid
     */
    private function writeSummaries(array $rows, array $lessonsByUlid): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $lessonId = $this->resolveId($row['lesson_ulid'], $lessonsByUlid);

            if ($lessonId === null) {
                continue;
            }

            $summary = new LessonSummary;
            $summary->forceFill([
                'ulid' => $this->writableUlid($row), 'lesson_id' => $lessonId, 'content' => $row['content'],
                'private_notes' => $row['private_notes'], 'resources' => $row['resources'], 'homework' => $row['homework'],
                'reviewed_at' => $row['reviewed_at'], 'reviewed_by' => $row['reviewed_by'],
            ]);
            $summary->save();
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $lessonsByUlid
     */
    private function writePlans(array $rows, array $lessonsByUlid): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $lessonId = $this->resolveId($row['lesson_ulid'], $lessonsByUlid);

            if ($lessonId === null) {
                continue;
            }

            $plan = new LessonPlan;
            $plan->forceFill([
                'ulid' => $this->writableUlid($row), 'lesson_id' => $lessonId,
                'planned_summary' => $row['planned_summary'], 'created_by' => $row['created_by'],
            ]);
            $plan->save();
            $created++;
        }

        return $created;
    }

    /**
     * `student_id` is derived from the RESOLVED enrollment, never taken from
     * the file (docs/backup-schema.md, §13.3 of CLAUDE.md — never a value
     * carried through as-is when a canonical source already resolved it).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $lessonsByUlid
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  Collection<int, int>  $lessonClassIds
     * @param  Collection<int, int>  $enrollmentClassIds
     */
    private function writeAttendances(array $rows, array $lessonsByUlid, array $enrollmentsByUlid, Collection $lessonClassIds, Collection $enrollmentClassIds): int
    {
        $created = 0;

        // One query for every enrollment this batch could possibly need,
        // never one per row — the same discipline every loader elsewhere in
        // this importer already follows (e.g. `GenerateDataExport`'s own
        // "never inside a per-row loop" rule).
        $studentIdsByEnrollmentId = Enrollment::query()
            ->whereIn('id', array_unique(array_values($enrollmentsByUlid)))
            ->pluck('student_id', 'id');

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $lessonId = $this->resolveId($row['lesson_ulid'], $lessonsByUlid);
            $enrollmentId = $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid);
            $studentId = $enrollmentId === null ? null : $studentIdsByEnrollmentId->get($enrollmentId);

            if ($lessonId === null || $enrollmentId === null || $studentId === null) {
                continue;
            }

            if ($lessonClassIds->get($lessonId) !== $enrollmentClassIds->get($enrollmentId)) {
                continue;
            }

            $attendance = new LessonAttendance;
            $attendance->forceFill([
                'ulid' => $this->writableUlid($row), 'lesson_id' => $lessonId, 'enrollment_id' => $enrollmentId,
                'student_id' => $studentId, 'status' => $row['status'], 'updated_by' => $row['updated_by'],
            ]);
            $attendance->save();
            $created++;
        }

        return $created;
    }
}
