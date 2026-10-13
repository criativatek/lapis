<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupConfirmation;
use Illuminate\Support\Carbon;

/**
 * Writes the follow-up tier a validated backup's plan already classified
 * (schema v15, BuildRecordFollowupPlan). Only `new` rows are written; the
 * rest attach by id or are skipped, never merged.
 *
 * Inside the restore's own transaction, and defensive against what the plan
 * could not see: a case whose key appeared meanwhile is reused, never
 * duplicated (the unique key would refuse it anyway), and a declaration in
 * force is never added beside another one in force on the same case.
 *
 * Nothing here alerts: the cases keep the alert date they had, and the
 * threshold is never evaluated by a restore.
 */
class WriteRecordFollowups
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $caseRows
     * @param  array<int, array<string, mixed>>  $confirmationRows
     * @param  array<string, int>  $classesByUlid  new ∪ existing
     * @param  array<string, int>  $enrollmentsByUlid  new ∪ existing
     * @param  array<string, int>  $periodsByUlid  new ∪ existing
     * @param  array<string, string>  $evidenceRecordUlids  source → destination ulid of the records this restore created
     * @return array{cases: int, confirmations: int, incomplete: int}
     */
    public function write(array $caseRows, array $confirmationRows, array $classesByUlid, array $enrollmentsByUlid, array $periodsByUlid, array $evidenceRecordUlids): array
    {
        $caseIds = [];
        $casesCreated = 0;

        foreach ($caseRows as $row) {
            if (in_array($row['classification'], ['existing', 'conflict'], true) && ($row['key_matches'] ?? false) && isset($row['existing_id'])) {
                $caseIds[$row['ulid']] = (int) $row['existing_id'];

                continue;
            }

            if ($row['classification'] !== 'new') {
                continue;
            }

            $key = [
                'class_id' => $this->resolveId($row['class_ulid'], $classesByUlid),
                'enrollment_id' => $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid),
                'academic_period_id' => $this->resolveId($row['academic_period_ulid'], $periodsByUlid),
                'category' => $row['category'],
            ];

            if (in_array(null, $key, true)) {
                continue;
            }

            $twin = RecordFollowupCase::query()->where($key)->first();

            if ($twin !== null) {
                $caseIds[$row['ulid']] = $twin->getKey();

                continue;
            }

            $case = new RecordFollowupCase;
            $case->timestamps = false;
            $createdAt = $this->wallClock($row['created_at'] ?? null) ?? $this->wallClock(now());

            $case->forceFill(array_merge($key, [
                'ulid' => $this->writableUlid($row),
                'alerted_at' => $this->wallClock($row['alerted_at'] ?? null),
                'alerted_count' => $row['alerted_count'],
                'created_at' => $createdAt,
                'updated_at' => $this->wallClock($row['updated_at'] ?? null) ?? $createdAt,
            ]));
            $case->save();
            $caseIds[$row['ulid']] = $case->getKey();
            $casesCreated++;
        }

        $confirmationsCreated = 0;
        $incomplete = 0;
        $restoredAt = $this->wallClock(now());

        foreach ($confirmationRows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            $caseId = $caseIds[$row['case_ulid']] ?? null;

            if ($caseId === null) {
                continue;
            }

            if ($row['undone_at'] === null
                && RecordFollowupConfirmation::query()->where('record_followup_case_id', $caseId)->whereNull('undone_at')->exists()) {
                continue;
            }

            // Each covered occurrence points at the destination's record, or
            // keeps only its date when the record could not be recovered.
            $occurrences = array_values(array_map(function (array $occurrence) use ($evidenceRecordUlids): array {
                $ulid = match ($occurrence['via']) {
                    'existing' => $occurrence['ulid'],
                    'new' => $evidenceRecordUlids[(string) $occurrence['ulid']] ?? null,
                    default => null,
                };

                return ['ulid' => $ulid, 'date' => $occurrence['date']];
            }, $row['restore_occurrences']));

            $confirmation = new RecordFollowupConfirmation;
            $confirmation->timestamps = false;
            $createdAt = $this->wallClock($row['created_at'] ?? null) ?? $this->wallClock($row['confirmed_at']);

            $confirmation->forceFill([
                'ulid' => $this->writableUlid($row),
                'record_followup_case_id' => $caseId,
                'confirmed_by' => $row['confirmed_by'],
                'confirmed_at' => $this->wallClock($row['confirmed_at']),
                'occurrence_count' => $row['occurrence_count'],
                'occurrences' => $occurrences,
                'undone_by' => $row['undone_by'],
                'undone_at' => $this->wallClock($row['undone_at'] ?? null),
                'restored_at' => $restoredAt,
                'created_at' => $createdAt,
                'updated_at' => $this->wallClock($row['updated_at'] ?? null) ?? $createdAt,
            ]);
            $confirmation->save();
            $confirmationsCreated++;

            if (in_array(null, array_column($occurrences, 'ulid'), true)) {
                $incomplete++;
            }
        }

        return ['cases' => $casesCreated, 'confirmations' => $confirmationsCreated, 'incomplete' => $incomplete];
    }

    /**
     * A plain wall-clock time in the application's time zone — the same fix
     * as WriteClassNotebookEntries: with `timestamps = false` an ISO string
     * with an offset would reach MySQL as-is and be shifted by the session
     * time zone.
     */
    private function wallClock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
