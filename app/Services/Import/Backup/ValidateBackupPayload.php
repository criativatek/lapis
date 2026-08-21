<?php

namespace App\Services\Import\Backup;

use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\ClassStatus;
use App\Models\EnrollmentStatus;
use App\Models\InstrumentStatus;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use App\Support\Import\Backup\BackupValidationException;
use App\Support\Import\Backup\SecretScanner;
use Closure;
use Illuminate\Support\Str;

/**
 * Turns raw JSON text into a whitelisted, structurally sound
 * `canonical_snapshot` — every field this importer will ever read, and
 * nothing else (§6 of the import brief: no insert/update happens here,
 * only reading and rejecting).
 *
 * Two different severities, deliberately:
 *   - a broken TOP-LEVEL contract (bad JSON, missing schema_version,
 *     missing `organization`, a secret-shaped key anywhere) rejects the
 *     whole file — BackupValidationException, nothing is stored.
 *   - a broken ROW inside classes/students/enrollments/instruments/
 *     classifications is dropped from the canonical snapshot and recorded
 *     in `$rowIssues` instead of failing the whole backup. One malformed
 *     enrollment should not block fifty good ones (§6, §52).
 *
 * Never copies an unrecognised key forward. A field this class does not
 * explicitly whitelist below simply does not reach `canonical_snapshot`,
 * which is what makes the secret scan defense-in-depth rather than the
 * only guard.
 */
class ValidateBackupPayload
{
    public function __construct(private readonly SecretScanner $secretScanner) {}

    /**
     * @return array{canonical: array<string, mixed>, schemaCompatibility: BackupSchemaCompatibility, rowIssues: list<array{domain: string, ulid: string|null, reason: string}>}
     *
     * @throws BackupValidationException
     */
    public function validate(string $rawJson): array
    {
        $decoded = json_decode($rawJson, true);

        if (! is_array($decoded)) {
            throw BackupValidationException::invalidJson();
        }

        $secretPaths = $this->secretScanner->scan($decoded);

        if ($secretPaths !== []) {
            throw BackupValidationException::suspiciousContent();
        }

        $this->requireField($decoded, 'schema_version');
        $compatibility = BackupSchemaCompatibility::for($decoded['schema_version']);

        if ($compatibility === BackupSchemaCompatibility::UnsupportedNewer) {
            throw BackupValidationException::unsupportedNewerSchema();
        }

        if ($compatibility === BackupSchemaCompatibility::Invalid) {
            throw BackupValidationException::unsupportedSchema();
        }

        $organization = $this->validOrganizationBlock($decoded);

        /** @var list<array{domain: string, ulid: string|null, reason: string}> $rowIssues */
        $rowIssues = [];

        $canonical = [
            'schema_version' => (int) $decoded['schema_version'],
            'app_version' => is_string($decoded['app_version'] ?? null) ? $decoded['app_version'] : null,
            'generated_at' => is_string($decoded['generated_at'] ?? null) ? $decoded['generated_at'] : null,
            'organization' => $organization,
            'classes' => $this->whitelistRows($decoded, 'classes', ['ulid', 'label', 'status', 'academic_year', 'subject'], function (array $row) use (&$rowIssues): ?array {
                return $this->validClassRow($row, $rowIssues);
            }),
            'students' => $this->whitelistRows($decoded, 'students', ['ulid', 'pseudonym_code', 'display_name'], function (array $row) use (&$rowIssues): ?array {
                return $this->validStudentRow($row, $rowIssues);
            }),
            'enrollments' => $this->whitelistRows($decoded, 'enrollments', ['ulid', 'class_ulid', 'student_ulid', 'status', 'enrolled_on', 'left_on', 'class_number'], function (array $row) use (&$rowIssues): ?array {
                return $this->validEnrollmentRow($row, $rowIssues);
            }),
            'instruments' => $this->whitelistRows($decoded, 'instruments', ['ulid', 'class_ulid', 'title', 'status'], function (array $row) use (&$rowIssues): ?array {
                return $this->validInstrumentRow($row, $rowIssues);
            }),
            'classifications' => $this->whitelistRows($decoded, 'classifications', ['ulid', 'enrollment_ulid', 'scope', 'status', 'proposed_value', 'final_value'], function (array $row) use (&$rowIssues): ?array {
                return $this->validClassificationRow($row, $rowIssues);
            }),
        ];

        return ['canonical' => $canonical, 'schemaCompatibility' => $compatibility, 'rowIssues' => $rowIssues];
    }

    /**
     * A translated string, never the string|array union __() is typed to
     * return — every call site here passes a literal key with no
     * placeholders that could resolve to anything but a string.
     */
    private function t(string $key): string
    {
        return (string) __($key);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function requireField(array $decoded, string $field): void
    {
        if (! array_key_exists($field, $decoded)) {
            throw BackupValidationException::missingRequiredField($field);
        }
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{ulid: string, name: string, type: string}
     */
    private function validOrganizationBlock(array $decoded): array
    {
        $organization = $decoded['organization'] ?? null;

        if (! is_array($organization)
            || ! $this->isUlid($organization['ulid'] ?? null)
            || ! is_string($organization['name'] ?? null)
            || ! in_array($organization['type'] ?? null, ['personal', 'institutional'], true)
        ) {
            throw BackupValidationException::missingRequiredField('organization');
        }

        return ['ulid' => $organization['ulid'], 'name' => $organization['name'], 'type' => $organization['type']];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<string>  $allowedKeys
     * @param  Closure(array<string, mixed>): (array<string, mixed>|null)  $rowValidator
     * @return list<array<string, mixed>>
     */
    private function whitelistRows(array $decoded, string $key, array $allowedKeys, Closure $rowValidator): array
    {
        $rows = $decoded[$key] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $whitelisted = array_intersect_key($row, array_flip($allowedKeys));
            $validated = $rowValidator($whitelisted);

            if ($validated !== null) {
                $result[] = $validated;
            }
        }

        return $result;
    }

    private function isUlid(mixed $value): bool
    {
        return is_string($value) && Str::isUlid($value);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validClassRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! is_string($row['label'] ?? null) || $row['label'] === ''
            || ClassStatus::tryFrom((string) ($row['status'] ?? '')) === null
            || ! is_string($row['academic_year'] ?? null) || $row['academic_year'] === ''
        ) {
            $rowIssues[] = ['domain' => 'classes', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $subject = $row['subject'] ?? null;

        return [
            'ulid' => $ulid,
            'label' => $row['label'],
            'status' => $row['status'],
            'academic_year' => $row['academic_year'],
            'subject' => is_string($subject) && $subject !== '' ? $subject : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validStudentRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! is_string($row['pseudonym_code'] ?? null) || $row['pseudonym_code'] === '') {
            $rowIssues[] = ['domain' => 'students', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $displayName = $row['display_name'] ?? null;

        return [
            'ulid' => $ulid,
            'pseudonym_code' => $row['pseudonym_code'],
            'display_name' => is_string($displayName) && $displayName !== '' ? $displayName : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validEnrollmentRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $classUlid = $row['class_ulid'] ?? null;
        $studentUlid = $row['student_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($classUlid) || ! $this->isUlid($studentUlid)
            || EnrollmentStatus::tryFrom((string) ($row['status'] ?? '')) === null
        ) {
            $rowIssues[] = ['domain' => 'enrollments', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $enrolledOn = $row['enrolled_on'] ?? null;
        $leftOn = $row['left_on'] ?? null;
        $classNumber = $row['class_number'] ?? null;

        return [
            'ulid' => $ulid,
            'class_ulid' => $classUlid,
            'student_ulid' => $studentUlid,
            'status' => $row['status'],
            'enrolled_on' => $this->validDateOrNull($enrolledOn),
            'left_on' => $this->validDateOrNull($leftOn),
            'class_number' => is_int($classNumber) ? $classNumber : null,
        ];
    }

    private function validDateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validInstrumentRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $classUlid = $row['class_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($classUlid)
            || ! is_string($row['title'] ?? null) || $row['title'] === ''
            || InstrumentStatus::tryFrom((string) ($row['status'] ?? '')) === null
        ) {
            $rowIssues[] = ['domain' => 'instruments', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return ['ulid' => $ulid, 'class_ulid' => $classUlid, 'title' => $row['title'], 'status' => $row['status']];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validClassificationRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $enrollmentUlid = $row['enrollment_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($enrollmentUlid)
            || ClassificationScope::tryFrom((string) ($row['scope'] ?? '')) === null
            || ClassificationStatus::tryFrom((string) ($row['status'] ?? '')) === null
        ) {
            $rowIssues[] = ['domain' => 'classifications', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $proposedValue = $row['proposed_value'] ?? null;
        $finalValue = $row['final_value'] ?? null;

        return [
            'ulid' => $ulid,
            'enrollment_ulid' => $enrollmentUlid,
            'scope' => $row['scope'],
            'status' => $row['status'],
            'proposed_value' => is_scalar($proposedValue) ? (string) $proposedValue : null,
            'final_value' => is_scalar($finalValue) ? (string) $finalValue : null,
        ];
    }
}
