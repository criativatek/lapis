<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\InstrumentStudentComment;
use Illuminate\Support\Carbon;

/**
 * Writes the `instrument_student_comments` tier a validated backup's plan
 * already classified (schema v23). Only rows classified `new` are ever written —
 * `existing`/`conflict`/`invalid` are all skipped, exactly like every other
 * writer in this pipeline.
 *
 * `lock_version` is reset to 1 for every comment this writer creates — this
 * installation's own optimistic-lock counter, never the source's.
 */
class WriteInstrumentStudentComments
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $instrumentsByUlid
     * @param  array<string, int>  $enrollmentsByUlid  new ∪ existing
     */
    public function write(array $rows, array $instrumentsByUlid, array $enrollmentsByUlid): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $instrumentId = $this->resolveId($row['instrument_ulid'], $instrumentsByUlid);
            $enrollmentId = $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid);

            if ($instrumentId === null || $enrollmentId === null) {
                continue;
            }

            $comment = new InstrumentStudentComment;
            $comment->timestamps = false;
            $createdAt = $this->databaseDateTime($row['created_at'] ?? null) ?? $this->databaseDateTime(now());
            $updatedAt = $this->databaseDateTime($row['updated_at'] ?? null) ?? $createdAt;

            $comment->forceFill([
                'ulid' => $this->writableUlid($row),
                'instrument_id' => $instrumentId,
                'enrollment_id' => $enrollmentId,
                'body' => $row['body'],
                'lock_version' => 1,
                'created_by' => $row['created_by'],
                'updated_by' => $row['updated_by'],
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
            $comment->save();
            $created++;
        }

        return $created;
    }

    /**
     * With `timestamps = false` nothing formats the dates on the way in, and an
     * ISO 8601 string with an offset would reach MySQL as-is (8.0.19+ honours
     * the offset). So the value is always handed over as a plain wall-clock time
     * in the app's time zone, exactly what Eloquent writes for every other
     * timestamp — the same fix as `WriteResultsAnalysisNotes`.
     */
    private function databaseDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
