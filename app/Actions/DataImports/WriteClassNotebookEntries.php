<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\ClassNotebookEntry;
use Illuminate\Support\Carbon;

/**
 * Writes the `class_notebook_entries` tier a validated backup's plan already
 * classified (schema v14). Only rows classified `new` are ever written —
 * `existing`/`conflict`/`invalid` are all skipped, exactly like every other
 * writer in this pipeline (§12 of the import brief).
 *
 * `author_id` is the account confirming the import: the plan only classifies a
 * row `new` after proving the backup's `author_email` is that account's (the
 * notebook is private to its author), so nothing is ever attributed to anyone
 * else here.
 *
 * `lock_version` restarts at 0 — this installation's own optimistic-lock
 * counter, never the source's. `deleted_at` stays null.
 */
class WriteClassNotebookEntries
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

            $classId = $this->resolveId($row['class_ulid'], $classesByUlid);

            if ($classId === null) {
                continue;
            }

            $entry = new ClassNotebookEntry;
            $entry->timestamps = false;
            $createdAt = $this->databaseDateTime($row['created_at'] ?? null) ?? $this->databaseDateTime(now());
            $updatedAt = $this->databaseDateTime($row['updated_at'] ?? null) ?? $createdAt;

            $entry->forceFill([
                'ulid' => $this->writableUlid($row),
                'class_id' => $classId,
                'author_id' => $row['author_id'],
                'title' => $row['entry_title'],
                'body' => $row['entry_body'],
                'is_pinned' => $row['is_pinned'],
                'lock_version' => 0,
                'edited_at' => $this->databaseDateTime($row['edited_at'] ?? null),
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
            $entry->save();
            $created++;
        }

        return $created;
    }

    /**
     * With `timestamps = false`, `created_at`/`updated_at` stop being date
     * attributes for Eloquent, so nothing formats them on the way in, and an
     * ISO 8601 string with an offset would reach MySQL as-is — which, from
     * 8.0.19, honours the offset and converts it to the SESSION time zone (an
     * hour off on a UTC server; SQLite stores the string untouched, so only CI
     * would miss it). Same fix as `WriteResultsAnalysisNotes`: always a plain
     * wall-clock time in the app's time zone. `edited_at` goes through it too,
     * for the same reason.
     */
    private function databaseDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
