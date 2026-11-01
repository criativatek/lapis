<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\LessonOutcomeRectification;

/**
 * Writes the `lesson_outcome_rectifications` tier a validated backup's plan already
 * classified (schema v21). Only `new` rows are written, after the lessons writer: each
 * points at its lesson through the map that writer built (new ∪ existing).
 *
 * It only ADDS history. It never touches the lesson: the current outcome, the numbers
 * and the planning are whatever the lessons tier restored, exactly as before.
 */
class WriteLessonOutcomeRectifications
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $lessonsByUlid
     */
    public function write(array $rows, array $lessonsByUlid): int
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

            $rectification = new LessonOutcomeRectification;
            $rectification->forceFill([
                'ulid' => $this->writableUlid($row),
                'lesson_id' => $lessonId,
                'rectified_by' => $row['rectified_by'],
                'rectified_at' => $row['rectified_at'],
                'kind' => $row['kind'],
                'previous' => $row['previous'],
                'current' => $row['current'],
                'motive' => $row['motive'],
            ]);
            $rectification->save();
            $created++;
        }

        return $created;
    }
}
