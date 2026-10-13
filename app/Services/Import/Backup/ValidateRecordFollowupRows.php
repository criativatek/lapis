<?php

namespace App\Services\Import\Backup;

use App\Models\RecordFollowupCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Schema v15 — the two follow-up collections (docs/backup-schema.md,
 * «Acompanhamento dos registos»): `record_followup_cases` and
 * `record_followup_confirmations`. Absent in a backup ≤14: `?? []`, zero rows,
 * never an error, and nothing in the destination is touched because of it.
 *
 * Its own class rather than two more methods on ValidateBackupPayload, for the
 * same reason the plan is split: a file that size strains PHPStan.
 *
 * Whitelist only — `id`, `organization_id`, the numeric foreign keys and
 * anything else a hand-edited file carries are dropped. Every refusal is a
 * fixed sentence with the row's ulid, never its content.
 *
 * A confirmation's `occurrences` is checked for shape only here ({ulid|null,
 * date}); whether each record can be recovered in the destination is the
 * plan's question, answered against what the destination holds.
 */
final class ValidateRecordFollowupRows
{
    private const array CASE_KEYS = [
        'ulid', 'class_ulid', 'enrollment_ulid', 'academic_period_ulid', 'category',
        'alerted_at', 'alerted_count', 'created_at', 'updated_at',
    ];

    private const array CONFIRMATION_KEYS = [
        'ulid', 'case_ulid', 'confirmed_by_email', 'confirmed_at', 'occurrence_count', 'occurrences',
        'undone_by_email', 'undone_at', 'created_at', 'updated_at',
    ];

    /** Far above any real count; keeps a hand-edited file from ballooning the plan. */
    private const int MAX_OCCURRENCES = 500;

    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array{cases: list<array<string, mixed>>, confirmations: list<array<string, mixed>>}
     */
    public function validate(array $decoded, array &$rowIssues): array
    {
        // `use (&$rowIssues)`, never an arrow function: `fn` captures by value,
        // and a refusal recorded on the copy never reaches «Pontos a rever».
        $cases = $this->rows($decoded['record_followup_cases'] ?? [], self::CASE_KEYS, function (array $row) use (&$rowIssues): ?array {
            return $this->caseRow($row, $rowIssues);
        });
        $confirmations = $this->rows($decoded['record_followup_confirmations'] ?? [], self::CONFIRMATION_KEYS, function (array $row) use (&$rowIssues): ?array {
            return $this->confirmationRow($row, $rowIssues);
        });

        foreach (['record_followup_cases' => $cases, 'record_followup_confirmations' => $confirmations] as $domain => $result) {
            foreach ($result['duplicates'] as $ulid) {
                $rowIssues[] = ['domain' => $domain, 'ulid' => $ulid, 'reason' => $this->t('Linha duplicada neste ficheiro.')];
            }
        }

        return ['cases' => $cases['valid'], 'confirmations' => $confirmations['valid']];
    }

    /**
     * The valid rows (first of each ulid kept) and the ulids seen twice.
     *
     * @param  list<string>  $allowed
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $validate
     * @return array{valid: list<array<string, mixed>>, duplicates: list<string>}
     */
    private function rows(mixed $rowsIn, array $allowed, callable $validate): array
    {
        $seen = [];
        $valid = [];
        $duplicates = [];

        if (! is_array($rowsIn)) {
            return ['valid' => [], 'duplicates' => []];
        }

        foreach ($rowsIn as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result = $validate(array_intersect_key($row, array_flip($allowed)));

            if ($result === null) {
                continue;
            }

            if (isset($seen[$result['ulid']])) {
                $duplicates[] = (string) $result['ulid'];

                continue;
            }

            $seen[$result['ulid']] = true;
            $valid[] = $result;
        }

        return ['valid' => $valid, 'duplicates' => $duplicates];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function caseRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $issue = function (string $reason) use ($ulid, &$rowIssues): null {
            $rowIssues[] = ['domain' => 'record_followup_cases', 'ulid' => $this->isUlid($ulid) ? $ulid : null, 'reason' => $this->t($reason)];

            return null;
        };

        if (! $this->isUlid($ulid) || ! $this->isUlid($row['class_ulid'] ?? null) || ! $this->isUlid($row['enrollment_ulid'] ?? null) || ! $this->isUlid($row['academic_period_ulid'] ?? null)) {
            return $issue('Acompanhamento com identificação, turma, inscrição ou período em falta ou inválidos.');
        }

        $category = is_string($row['category'] ?? null) ? RecordFollowupCategory::tryFrom($row['category']) : null;

        if ($category === null) {
            return $issue('Acompanhamento com um tipo de ocorrência desconhecido.');
        }

        $alertedAt = $this->dateTime($row['alerted_at'] ?? null);
        $alertedCount = $row['alerted_count'] ?? null;

        if (! $alertedAt['ok'] || ($alertedCount !== null && (! is_int($alertedCount) || $alertedCount < RecordFollowupCategory::ALERT_THRESHOLD || $alertedCount > 65535))
            || (($alertedAt['value'] === null) !== ($alertedCount === null))) {
            return $issue('Acompanhamento com informação de alerta inválida.');
        }

        $createdAt = $this->dateTime($row['created_at'] ?? null);
        $updatedAt = $this->dateTime($row['updated_at'] ?? null);

        if (! $createdAt['ok'] || ! $updatedAt['ok']) {
            return $issue('Acompanhamento com data de criação ou de alteração inválida.');
        }

        return [
            'ulid' => $ulid,
            'class_ulid' => $row['class_ulid'],
            'enrollment_ulid' => $row['enrollment_ulid'],
            'academic_period_ulid' => $row['academic_period_ulid'],
            'category' => $category->value,
            'alerted_at' => $alertedAt['value'],
            'alerted_count' => $alertedCount,
            'created_at' => $createdAt['value'],
            'updated_at' => $updatedAt['value'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function confirmationRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $issue = function (string $reason) use ($ulid, &$rowIssues): null {
            $rowIssues[] = ['domain' => 'record_followup_confirmations', 'ulid' => $this->isUlid($ulid) ? $ulid : null, 'reason' => $this->t($reason)];

            return null;
        };

        if (! $this->isUlid($ulid) || ! $this->isUlid($row['case_ulid'] ?? null)) {
            return $issue('Marcação «Registado no Inovar» com identificação ou acompanhamento em falta ou inválidos.');
        }

        $confirmedAt = $this->dateTime($row['confirmed_at'] ?? null);

        if (! $confirmedAt['ok'] || $confirmedAt['value'] === null) {
            return $issue('Marcação «Registado no Inovar» sem data válida.');
        }

        $occurrences = $row['occurrences'] ?? null;
        $count = $row['occurrence_count'] ?? null;

        if (! is_array($occurrences) || ! array_is_list($occurrences) || $occurrences === [] || count($occurrences) > self::MAX_OCCURRENCES
            || ! is_int($count) || $count !== count($occurrences)) {
            return $issue('Marcação «Registado no Inovar» com ocorrências abrangidas inválidas.');
        }

        $normalized = [];

        foreach ($occurrences as $occurrence) {
            $occurrenceUlid = is_array($occurrence) ? ($occurrence['ulid'] ?? null) : null;
            $date = is_array($occurrence) ? ($occurrence['date'] ?? null) : null;

            if (($occurrenceUlid !== null && ! $this->isUlid($occurrenceUlid)) || ! $this->isDate($date)) {
                return $issue('Marcação «Registado no Inovar» com ocorrências abrangidas inválidas.');
            }

            $normalized[] = ['ulid' => $occurrenceUlid, 'date' => $date];
        }

        $undoneAt = $this->dateTime($row['undone_at'] ?? null);
        $createdAt = $this->dateTime($row['created_at'] ?? null);
        $updatedAt = $this->dateTime($row['updated_at'] ?? null);

        if (! $undoneAt['ok'] || ! $createdAt['ok'] || ! $updatedAt['ok']) {
            return $issue('Marcação «Registado no Inovar» com data inválida.');
        }

        $undoneByEmail = $this->email($row['undone_by_email'] ?? null);

        if ($undoneAt['value'] === null && $undoneByEmail !== null) {
            return $issue('Marcação «Registado no Inovar» com quem a desfez, mas sem a data em que foi desfeita.');
        }

        return [
            'ulid' => $ulid,
            'case_ulid' => $row['case_ulid'],
            'confirmed_by_email' => $this->email($row['confirmed_by_email'] ?? null),
            'confirmed_at' => $confirmedAt['value'],
            'occurrence_count' => $count,
            'occurrences' => $normalized,
            'undone_by_email' => $undoneByEmail,
            'undone_at' => $undoneAt['value'],
            'created_at' => $createdAt['value'],
            'updated_at' => $updatedAt['value'],
        ];
    }

    private function isUlid(mixed $value): bool
    {
        return is_string($value) && Str::isUlid($value);
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    private function email(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Absent or null is a real absence; present but not an ISO 8601 datetime
     * invalidates the row — the same discipline as
     * ValidateBackupPayload::optionalDateTimeOrInvalidate(). Normalised to the
     * application's time zone.
     *
     * @return array{ok: true, value: string|null}|array{ok: false}
     */
    private function dateTime(mixed $value): array
    {
        if ($value === null) {
            return ['ok' => true, 'value' => null];
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            return ['ok' => false];
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (Throwable) {
            return ['ok' => false];
        }

        return ['ok' => true, 'value' => $parsed->setTimezone((string) config('app.timezone'))->toIso8601String()];
    }

    private function t(string $key): string
    {
        return (string) __($key);
    }
}
