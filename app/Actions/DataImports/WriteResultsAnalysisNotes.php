<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\ResultsAnalysisNote;

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
            $createdAt = $row['created_at'] ?? now();
            $updatedAt = $row['updated_at'] ?? $createdAt;

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
}
