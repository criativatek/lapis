<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\TimetableActivity;

/**
 * Writes the `timetable_activities` tier a validated backup's plan already
 * classified (schema v17). Only rows classified `new` are ever written —
 * `existing`/`conflict`/`invalid` are all skipped, like every other writer in
 * this pipeline.
 *
 * `user_id` is the account confirming the import: the plan only classifies a
 * row `new` after proving the backup's `author_email` is that account's, so
 * nothing is ever placed in anyone else's timetable. The class is optional
 * and resolved through the classes map (new ∪ existing); an unresolved class
 * leaves the activity without one rather than losing it. Nothing here creates
 * a lesson: an activity is not a lesson.
 */
class WriteTimetableActivities
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $classesByUlid  new ∪ existing classes
     */
    public function write(array $rows, array $classesByUlid): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $classUlid = $row['class_ulid'] ?? null;
            $activity = new TimetableActivity;

            $activity->forceFill([
                'ulid' => $this->writableUlid($row),
                'user_id' => $row['user_id'],
                'class_id' => $classUlid === null ? null : $this->resolveId($classUlid, $classesByUlid),
                'kind' => $row['kind'],
                'title' => $row['activity_title'],
                'day_of_week' => $row['day_of_week'],
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'],
                'starts_on' => $row['starts_on'],
                'ends_on' => $row['ends_on'],
            ]);
            $activity->save();
            $created++;
        }

        return $created;
    }
}
