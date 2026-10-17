<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\TimetableActivitySession;

/**
 * Writes the `timetable_activity_occurrences` tier a validated backup's plan
 * already classified (schema v19). Only rows classified `new` are ever written —
 * `existing`/`conflict`/`invalid` are all skipped, like every other writer in
 * this pipeline.
 *
 * Runs AFTER the activities writer: a session points at its activity through
 * the map that writer built (new ∪ existing). An activity that is not in the
 * map leaves the session an orphan, identified by the copy it carries — the
 * text is never lost for want of a block.
 *
 * `user_id` is the account confirming the import: the plan only classifies a
 * row `new` after proving the backup's `author_email` is that account's. The
 * academic year is NOT carried by the backup: it is derived exactly as when a
 * session is created — the class's year if there is one, else the organization's
 * year that contains the date. Nothing here creates a lesson, and nothing here
 * renumbers: the `session_number` the teacher saw is the one restored.
 */
class WriteTimetableActivitySessions
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid  new ∪ existing classes
     * @param  array<string, int>  $activitiesByUlid  new ∪ existing activities
     */
    public function write(array $rows, array $classesByUlid, array $activitiesByUlid): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $classId = $this->resolveId($row['class_ulid'] ?? null, $classesByUlid);
            $session = new TimetableActivitySession;

            $session->forceFill([
                'ulid' => $this->writableUlid($row),
                'user_id' => $row['user_id'],
                'timetable_activity_id' => $this->resolveId($row['activity_ulid'] ?? null, $activitiesByUlid),
                'activity_kind' => $row['activity_kind'],
                'activity_title' => $row['activity_title'],
                'class_id' => $classId,
                'academic_year_id' => $this->academicYearId($classId, (string) $row['occurs_on']),
                'occurs_on' => $row['occurs_on'],
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'],
                'summary' => $row['summary'],
                'status' => $row['status'],
                'session_number' => $row['sequence_number'],
            ]);
            $session->save();
            $created++;
        }

        return $created;
    }

    private function academicYearId(?int $classId, string $date): ?int
    {
        if ($classId !== null) {
            $classYear = SchoolClass::query()->whereKey($classId)->value('academic_year_id');

            if ($classYear !== null) {
                return (int) $classYear;
            }
        }

        return AcademicYear::containing($date)?->getKey();
    }
}
