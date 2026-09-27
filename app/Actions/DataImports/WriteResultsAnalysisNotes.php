<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\ResultsAnalysisNote;
use Illuminate\Support\Carbon;

/**
 * Writes the `results_analysis_notes` tier a validated backup's plan already
 * classified (schema v13). Only rows classified `new` are ever written —
 * `existing`/`conflict`/`invalid` are all skipped, exactly like every other
 * writer in this pipeline (§12 of the import brief).
 *
 * `lock_version` is reset to 1 for every note this writer creates — this
 * installation's own optimistic-lock counter, starting fresh, NEVER the
 * source installation's own counter (which describes edit history at a
 * DIFFERENT database and means nothing here).
 */
class WriteResultsAnalysisNotes
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $instrumentsByUlid
     */
    public function write(array $rows, array $instrumentsByUlid): int
    {
        $created = 0;

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $instrumentId = $this->resolveId($row['instrument_ulid'], $instrumentsByUlid);

            if ($instrumentId === null) {
                continue;
            }

            $note = new ResultsAnalysisNote;
            $note->timestamps = false;
            $createdAt = $this->databaseDateTime($row['created_at'] ?? null) ?? $this->databaseDateTime(now());
            $updatedAt = $this->databaseDateTime($row['updated_at'] ?? null) ?? $createdAt;

            $note->forceFill([
                'ulid' => $this->writableUlid($row),
                'context_kind' => $row['context_kind'],
                'instrument_id' => $instrumentId,
                'body' => $row['body'],
                'lock_version' => 1,
                'created_by' => $row['created_by'],
                'updated_by' => $row['updated_by'],
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
            $note->save();
            $created++;
        }

        return $created;
    }

    /**
     * With `timestamps = false`, `created_at`/`updated_at` stop being date
     * attributes for Eloquent, so nothing formats them on the way in. An
     * ISO 8601 string with an offset would then reach MySQL as-is — and
     * MySQL 8.0.19+ honours the offset, converting it to the SESSION time
     * zone: on a UTC server the restored note was an hour off. SQLite stores
     * the string untouched, which is why only CI saw it. So the value is
     * always handed over as a plain wall-clock time in the app's time zone,
     * exactly what Eloquent writes for every other timestamp.
     */
    private function databaseDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
