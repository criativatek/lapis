<?php

namespace App\Services\Import\Backup;

use App\Models\AcademicPeriodKind;
use App\Models\AcademicPeriodStatus;
use App\Models\AcademicYearStatus;
use App\Models\ActivityEvaluation;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\ClassStatus;
use App\Models\DisciplinarySeverity;
use App\Models\EnrollmentStatus;
use App\Models\EvidenceKind;
use App\Models\HomeworkStatus;
use App\Models\InstrumentStatus;
use App\Models\InterventionEffectiveness;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\ParticipationLevel;
use App\Models\ProfileVersionStatus;
use App\Models\ReportScopeKind;
use App\Models\ReportTone;
use App\Models\ReportType;
use App\Models\ResultState;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentStatus;
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
 *   - a broken ROW inside any collection is dropped from the canonical
 *     snapshot and recorded in `$rowIssues` instead of failing the whole
 *     backup. One malformed enrollment should not block fifty good ones
 *     (§6, §52).
 *
 * Never copies an unrecognised key forward. A field this class does not
 * explicitly whitelist below simply does not reach `canonical_snapshot`,
 * which is what makes the secret scan defense-in-depth rather than the
 * only guard.
 *
 * Fatia 6.2 (schema_version 5, docs/backup-schema.md): one validator for
 * every schema_version this importer still reads, not a
 * BackupSchemaV1Validator/V2Validator pair (§89 of the import brief). A
 * backup older than schema_version 4 simply never HAS the newer collection
 * keys — `$decoded['assessment_profiles'] ?? []` already resolves to an
 * empty list for it, the same way an old export missing any other optional
 * key always has — so no branch on `$decoded['schema_version']` exists
 * anywhere below.
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

            'academic_years' => $this->whitelistRows($decoded, 'academic_years', ['ulid', 'label', 'starts_on', 'ends_on', 'status', 'country_code', 'region_code'], function (array $row) use (&$rowIssues): ?array {
                return $this->validAcademicYearRow($row, $rowIssues);
            }),
            'subjects' => $this->whitelistRows($decoded, 'subjects', ['ulid', 'name', 'code'], function (array $row) use (&$rowIssues): ?array {
                return $this->validSubjectRow($row, $rowIssues);
            }),
            'academic_periods' => $this->whitelistRows($decoded, 'academic_periods', ['ulid', 'academic_year', 'label', 'kind', 'sequence', 'starts_on', 'ends_on', 'status'], function (array $row) use (&$rowIssues): ?array {
                return $this->validAcademicPeriodRow($row, $rowIssues);
            }),
            'scales' => $this->whitelistRows($decoded, 'scales', ['ulid', 'name', 'kind', 'is_system', 'min_value', 'max_value', 'levels'], function (array $row) use (&$rowIssues): ?array {
                return $this->validScaleRow($row, $rowIssues);
            }),
            'instrument_types' => $this->whitelistRows($decoded, 'instrument_types', ['ulid', 'name', 'code', 'is_system', 'default_purpose', 'is_active'], function (array $row) use (&$rowIssues): ?array {
                return $this->validInstrumentTypeRow($row, $rowIssues);
            }),
            'domains' => $this->whitelistRows($decoded, 'domains', ['ulid', 'name', 'code', 'subject', 'parent_domain_ulid', 'sequence', 'is_active'], function (array $row) use (&$rowIssues): ?array {
                return $this->validDomainRow($row, $rowIssues);
            }),
            'assessment_profiles' => $this->whitelistRows($decoded, 'assessment_profiles', ['ulid', 'name', 'description', 'academic_year', 'subject', 'grade_level', 'grade_levels', 'is_institutional_template'], function (array $row) use (&$rowIssues): ?array {
                return $this->validProfileRow($row, $rowIssues);
            }),
            'assessment_profile_versions' => $this->whitelistRows($decoded, 'assessment_profile_versions', ['ulid', 'profile_ulid', 'version_number', 'status', 'is_current', 'scale', 'domain_weight_mode', 'period_result_mode', 'accumulated_mode', 'absence_mode', 'rounding_mode', 'rounding_scale', 'rounding_stage', 'minimum_rules', 'activated_at', 'frozen_at', 'superseded_at', 'change_note'], function (array $row) use (&$rowIssues): ?array {
                return $this->validProfileVersionRow($row, $rowIssues);
            }),
            'profile_version_domains' => $this->whitelistRows($decoded, 'profile_version_domains', ['version_ulid', 'domain_ulid', 'weight_percent', 'sequence', 'expected_element_count', 'minimum_element_count'], function (array $row) use (&$rowIssues): ?array {
                return $this->validProfileVersionDomainRow($row, $rowIssues);
            }),
            'profile_version_periods' => $this->whitelistRows($decoded, 'profile_version_periods', ['version_ulid', 'academic_period_ulid', 'is_cumulative', 'period_weight_percent', 'contributes_to_accumulated'], function (array $row) use (&$rowIssues): ?array {
                return $this->validProfileVersionPeriodRow($row, $rowIssues);
            }),

            'classes' => $this->whitelistRows($decoded, 'classes', ['ulid', 'label', 'status', 'academic_year', 'subject', 'assessment_profile_version_ulid'], function (array $row) use (&$rowIssues): ?array {
                return $this->validClassRow($row, $rowIssues);
            }),
            'students' => $this->whitelistRows($decoded, 'students', ['ulid', 'pseudonym_code', 'display_name'], function (array $row) use (&$rowIssues): ?array {
                return $this->validStudentRow($row, $rowIssues);
            }),
            'enrollments' => $this->whitelistRows($decoded, 'enrollments', ['ulid', 'class_ulid', 'student_ulid', 'status', 'enrolled_on', 'left_on', 'class_number'], function (array $row) use (&$rowIssues): ?array {
                return $this->validEnrollmentRow($row, $rowIssues);
            }),

            'instruments' => $this->whitelistRows($decoded, 'instruments', ['ulid', 'class_ulid', 'title', 'status', 'applied_on', 'academic_period_ulid', 'instrument_type', 'purpose', 'counts_toward_classification', 'total_points', 'scale', 'weight', 'allow_bonus'], function (array $row) use (&$rowIssues): ?array {
                return $this->validInstrumentRow($row, $rowIssues);
            }),
            'instrument_groups' => $this->whitelistRows($decoded, 'instrument_groups', ['ulid', 'instrument_ulid', 'label', 'sequence'], function (array $row) use (&$rowIssues): ?array {
                return $this->validInstrumentGroupRow($row, $rowIssues);
            }),
            'instrument_items' => $this->whitelistRows($decoded, 'instrument_items', ['ulid', 'instrument_ulid', 'group_ulid', 'code', 'label', 'sequence', 'points_possible', 'scoring_mode', 'scale', 'is_bonus', 'source_group_label'], function (array $row) use (&$rowIssues): ?array {
                return $this->validInstrumentItemRow($row, $rowIssues);
            }),
            'item_domain_allocations' => $this->whitelistRows($decoded, 'item_domain_allocations', ['item_ulid', 'domain_ulid', 'allocation_percent'], function (array $row) use (&$rowIssues): ?array {
                return $this->validItemDomainAllocationRow($row, $rowIssues);
            }),
            'student_item_scores' => $this->whitelistRows($decoded, 'student_item_scores', ['item_ulid', 'enrollment_ulid', 'result_state', 'points_earned', 'scale_level', 'state_reason', 'assessed_at', 'assessed_by_email'], function (array $row) use (&$rowIssues): ?array {
                return $this->validStudentItemScoreRow($row, $rowIssues);
            }),

            'classifications' => $this->whitelistRows($decoded, 'classifications', [
                'ulid', 'enrollment_ulid', 'academic_period_ulid', 'assessment_profile_version_ulid', 'scope', 'status',
                'proposed_normalized_value', 'proposed_value', 'proposed_scale_level', 'final_value', 'final_scale_level',
                'override_reason', 'overridden_by_email', 'overridden_at', 'confirmed_by_email', 'confirmed_at',
                'published_at', 'superseded_by_ulid',
            ], function (array $row) use (&$rowIssues): ?array {
                return $this->validClassificationRow($row, $rowIssues);
            }),

            'self_assessment_templates' => $this->whitelistRows($decoded, 'self_assessment_templates', ['ulid', 'name', 'is_active', 'assessment_profile_version_ulid', 'class_ulid'], function (array $row) use (&$rowIssues): ?array {
                return $this->validSelfAssessmentTemplateRow($row, $rowIssues);
            }),
            'self_assessment_questions' => $this->whitelistRows($decoded, 'self_assessment_questions', ['template_ulid', 'role', 'prompt', 'answer_kind', 'domain_ulid', 'scale', 'sequence'], function (array $row) use (&$rowIssues): ?array {
                return $this->validSelfAssessmentQuestionRow($row, $rowIssues);
            }),
            'self_assessments' => $this->whitelistRows($decoded, 'self_assessments', ['ulid', 'enrollment_ulid', 'academic_period_ulid', 'template_ulid', 'status', 'filled_by', 'reflection', 'submitted_at', 'reviewed_at', 'reviewed_by_email'], function (array $row) use (&$rowIssues): ?array {
                return $this->validSelfAssessmentRow($row, $rowIssues);
            }),
            'self_assessment_responses' => $this->whitelistRows($decoded, 'self_assessment_responses', ['self_assessment_ulid', 'question_role', 'question_sequence', 'scale_level', 'text_value', 'boolean_value'], function (array $row) use (&$rowIssues): ?array {
                return $this->validSelfAssessmentResponseRow($row, $rowIssues);
            }),

            'interim_assessments' => $this->whitelistRows($decoded, 'interim_assessments', ['ulid', 'class_ulid', 'academic_period_ulid', 'name', 'reference_date', 'note', 'snapshot_version', 'snapshot', 'snapshot_hash', 'created_by_email'], function (array $row) use (&$rowIssues): ?array {
                return $this->validInterimAssessmentRow($row, $rowIssues);
            }),
            'evidence_records' => $this->whitelistRows($decoded, 'evidence_records', [
                'ulid', 'class_ulid', 'enrollment_ulid', 'academic_period_ulid', 'domain_ulid', 'quick_rating_scale_level',
                'occurred_at', 'kind', 'description', 'activity_include_in_report', 'homework_status',
                'participation_level', 'activity_evaluation', 'disciplinary_severity', 'created_by_email',
            ], function (array $row) use (&$rowIssues): ?array {
                return $this->validEvidenceRecordRow($row, $rowIssues);
            }),

            'interventions' => $this->whitelistRows($decoded, 'interventions', [
                'ulid', 'class_ulid', 'enrollment_ulid', 'participant_enrollment_ulids', 'academic_period_ulid', 'domain_ulid',
                'target_type', 'intervention_type', 'motive_code', 'motive_label', 'strategy_code', 'strategy_label',
                'objective', 'domain_relation', 'title', 'description', 'description_source', 'status', 'started_on',
                'expected_end_on', 'concluded_on', 'review_on', 'available_for_reports', 'support_measure_level',
                'support_measure_code', 'evaluation_adaptation_code', 'legal_mapping_source', 'created_by_email',
            ], function (array $row) use (&$rowIssues): ?array {
                return $this->validInterventionRow($row, $rowIssues);
            }),
            'intervention_reviews' => $this->whitelistRows($decoded, 'intervention_reviews', ['ulid', 'intervention_ulid', 'reviewed_on', 'effectiveness', 'notes', 'reviewed_by_email'], function (array $row) use (&$rowIssues): ?array {
                return $this->validInterventionReviewRow($row, $rowIssues);
            }),

            'reports' => $this->whitelistRows($decoded, 'reports', [
                'ulid', 'type', 'title', 'tone', 'scope_kind', 'scope_label', 'starts_on', 'ends_on', 'class_ulid',
                'enrollment_ulid', 'academic_year', 'academic_period_ulid', 'interim_assessment_ulid', 'document',
                'document_version', 'document_hash', 'finalized_at', 'finalized_by_email', 'created_by_email',
                'based_on_report_ulid', 'template_key', 'template_snapshot',
            ], function (array $row) use (&$rowIssues): ?array {
                return $this->validReportRow($row, $rowIssues);
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

    private function nullableUlid(mixed $value): ?string
    {
        return $this->isUlid($value) ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableNumeric(mixed $value): ?string
    {
        return is_scalar($value) && is_numeric($value) ? (string) $value : null;
    }

    /**
     * @return list<string>|null
     */
    private function nullableStringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $strings = array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''));

        return $strings;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function nullableBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A reference to a Scale — {ulid, name, is_system} — used wherever a
     * profile version, an instrument, an item or a question points at one.
     * `is_system` decides how BuildImportPlan resolves it later: a system
     * scale is matched by name only (every database seeds its own copy
     * with its own ulid, so ulids never line up across databases for
     * reference/seeded data — §12 of the import brief); a custom scale is
     * matched/created by ulid, same as any other org-owned row.
     *
     * @return array{ulid: string|null, name: string, is_system: bool}|null
     */
    private function validScaleRef(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['name'] ?? null) || $value['name'] === '' || ! is_bool($value['is_system'] ?? null)) {
            return null;
        }

        return ['ulid' => $this->nullableUlid($value['ulid'] ?? null), 'name' => $value['name'], 'is_system' => $value['is_system']];
    }

    /**
     * A reference to a ScaleLevel — {scale: ScaleRef, code} — resolved
     * together with its parent scale, since a level has no identity of its
     * own outside it.
     *
     * @return array{scale: array{ulid: string|null, name: string, is_system: bool}, code: string}|null
     */
    private function validScaleLevelRef(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['code'] ?? null) || $value['code'] === '') {
            return null;
        }

        $scale = $this->validScaleRef($value['scale'] ?? null);

        return $scale === null ? null : ['scale' => $scale, 'code' => $value['code']];
    }

    /**
     * A reference to an InstrumentType — {ulid, code, is_system} — same
     * system-vs-custom matching rule as a scale reference.
     *
     * @return array{ulid: string|null, code: string, is_system: bool}|null
     */
    private function validInstrumentTypeRef(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['code'] ?? null) || $value['code'] === '' || ! is_bool($value['is_system'] ?? null)) {
            return null;
        }

        return ['ulid' => $this->nullableUlid($value['ulid'] ?? null), 'code' => $value['code'], 'is_system' => $value['is_system']];
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

        return [
            'ulid' => $ulid,
            'label' => $row['label'],
            'status' => $row['status'],
            'academic_year' => $row['academic_year'],
            'subject' => $this->nullableString($row['subject'] ?? null),
            'assessment_profile_version_ulid' => $this->nullableUlid($row['assessment_profile_version_ulid'] ?? null),
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

        return [
            'ulid' => $ulid,
            'pseudonym_code' => $row['pseudonym_code'],
            'display_name' => $this->nullableString($row['display_name'] ?? null),
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

        return [
            'ulid' => $ulid,
            'class_ulid' => $classUlid,
            'student_ulid' => $studentUlid,
            'status' => $row['status'],
            'enrolled_on' => $this->nullableDate($row['enrolled_on'] ?? null),
            'left_on' => $this->nullableDate($row['left_on'] ?? null),
            'class_number' => $this->nullableInt($row['class_number'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validAcademicPeriodRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! is_string($row['academic_year'] ?? null) || $row['academic_year'] === ''
            || ! is_string($row['label'] ?? null) || $row['label'] === ''
            || AcademicPeriodKind::tryFrom((string) ($row['kind'] ?? '')) === null
            || ! is_int($row['sequence'] ?? null)
            || $this->nullableDate($row['starts_on'] ?? null) === null
            || $this->nullableDate($row['ends_on'] ?? null) === null
            || AcademicPeriodStatus::tryFrom((string) ($row['status'] ?? '')) === null
        ) {
            $rowIssues[] = ['domain' => 'academic_periods', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'academic_year' => $row['academic_year'],
            'label' => $row['label'],
            'kind' => $row['kind'],
            'sequence' => $row['sequence'],
            'starts_on' => $row['starts_on'],
            'ends_on' => $row['ends_on'],
            'status' => $row['status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validAcademicYearRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $startsOn = $this->nullableDate($row['starts_on'] ?? null);
        $endsOn = $this->nullableDate($row['ends_on'] ?? null);

        if (! $this->isUlid($ulid) || ! is_string($row['label'] ?? null) || $row['label'] === '' || mb_strlen($row['label']) > 32
            || $startsOn === null || $endsOn === null || $endsOn <= $startsOn
            || AcademicYearStatus::tryFrom((string) ($row['status'] ?? '')) === null
            || ! is_string($row['country_code'] ?? null) || mb_strlen($row['country_code']) !== 2
            || ! (is_string($row['region_code'] ?? null) || ($row['region_code'] ?? null) === null)
            || (is_string($row['region_code'] ?? null) && mb_strlen($row['region_code']) > 8)
        ) {
            $rowIssues[] = ['domain' => 'academic_years', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid, 'label' => $row['label'], 'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'],
            'status' => $row['status'], 'country_code' => $row['country_code'], 'region_code' => $row['region_code'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validSubjectRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! is_string($row['name'] ?? null) || $row['name'] === '' || mb_strlen($row['name']) > 120
            || ! is_string($row['code'] ?? null) || $row['code'] === '' || mb_strlen($row['code']) > 32) {
            $rowIssues[] = ['domain' => 'subjects', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return ['ulid' => $ulid, 'name' => $row['name'], 'code' => $row['code']];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validScaleRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $allowedKinds = ['numeric', 'level', 'percentage', 'qualitative', 'custom'];

        if (! $this->isUlid($ulid) || ! is_string($row['name'] ?? null) || $row['name'] === ''
            || ! in_array($row['kind'] ?? null, $allowedKinds, true)
            || ! is_bool($row['is_system'] ?? null)
        ) {
            $rowIssues[] = ['domain' => 'scales', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $levelsIn = $row['levels'] ?? [];
        $levels = is_array($levelsIn) ? array_values(array_filter(array_map(
            fn (mixed $level): ?array => $this->validScaleLevelInline($level),
            array_filter($levelsIn, 'is_array'),
        ))) : [];

        return [
            'ulid' => $ulid,
            'name' => $row['name'],
            'kind' => $row['kind'],
            'is_system' => $row['is_system'],
            'min_value' => $this->nullableNumeric($row['min_value'] ?? null),
            'max_value' => $this->nullableNumeric($row['max_value'] ?? null),
            'levels' => $levels,
        ];
    }

    /**
     * A level nested directly under its scale — no ulid, no row-issue entry
     * of its own; a malformed level is simply dropped from its scale
     * (mirrors how a malformed item is dropped from its instrument).
     *
     * @param  array<string, mixed>  $level
     * @return array<string, mixed>|null
     */
    private function validScaleLevelInline(array $level): ?array
    {
        if (! is_string($level['code'] ?? null) || $level['code'] === ''
            || ! is_string($level['label'] ?? null) || $level['label'] === ''
            || ! is_int($level['sequence'] ?? null)
            || ! is_bool($level['is_negative'] ?? null)
        ) {
            return null;
        }

        return [
            'code' => $level['code'],
            'label' => $level['label'],
            'inovar_code' => $this->nullableString($level['inovar_code'] ?? null),
            'sequence' => $level['sequence'],
            'numeric_value' => $this->nullableNumeric($level['numeric_value'] ?? null),
            'normalized_value' => $this->nullableNumeric($level['normalized_value'] ?? null),
            'band_min_normalized' => $this->nullableNumeric($level['band_min_normalized'] ?? null),
            'band_max_normalized' => $this->nullableNumeric($level['band_max_normalized'] ?? null),
            'is_negative' => $level['is_negative'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validInstrumentTypeRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! is_string($row['name'] ?? null) || $row['name'] === ''
            || ! is_string($row['code'] ?? null) || $row['code'] === ''
            || ! is_bool($row['is_system'] ?? null) || ! is_bool($row['is_active'] ?? null)
        ) {
            $rowIssues[] = ['domain' => 'instrument_types', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'name' => $row['name'],
            'code' => $row['code'],
            'is_system' => $row['is_system'],
            'default_purpose' => $this->nullableString($row['default_purpose'] ?? null),
            'is_active' => $row['is_active'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validDomainRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! is_string($row['name'] ?? null) || $row['name'] === ''
            || ! is_string($row['code'] ?? null) || $row['code'] === ''
            || ! is_int($row['sequence'] ?? null) || ! is_bool($row['is_active'] ?? null)
        ) {
            $rowIssues[] = ['domain' => 'domains', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'name' => $row['name'],
            'code' => $row['code'],
            'subject' => $this->nullableString($row['subject'] ?? null),
            'parent_domain_ulid' => $this->nullableUlid($row['parent_domain_ulid'] ?? null),
            'sequence' => $row['sequence'],
            'is_active' => $row['is_active'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validProfileRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! is_string($row['name'] ?? null) || $row['name'] === ''
            || ! is_bool($row['is_institutional_template'] ?? null)
        ) {
            $rowIssues[] = ['domain' => 'assessment_profiles', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'name' => $row['name'],
            'description' => $this->nullableString($row['description'] ?? null),
            'academic_year' => $this->nullableString($row['academic_year'] ?? null),
            'subject' => $this->nullableString($row['subject'] ?? null),
            // Kept for backups written before schema_version 6 (see
            // BackupSchemaCompatibility): they carry only the old singular
            // field. grade_levels is the new, possibly-plural field; a backup
            // that lacks it entirely (nullable) falls back to grade_level
            // downstream (BuildAssessmentStructurePlan/WriteAssessmentStructure).
            'grade_level' => $this->nullableString($row['grade_level'] ?? null),
            'grade_levels' => $this->nullableStringList($row['grade_levels'] ?? null),
            'is_institutional_template' => $row['is_institutional_template'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validProfileVersionRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $profileUlid = $row['profile_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($profileUlid)
            || ! is_int($row['version_number'] ?? null)
            || ProfileVersionStatus::tryFrom((string) ($row['status'] ?? '')) === null
            || ! is_bool($row['is_current'] ?? null)
            || ! is_string($row['domain_weight_mode'] ?? null) || $row['domain_weight_mode'] === ''
            || ! is_string($row['period_result_mode'] ?? null) || $row['period_result_mode'] === ''
            || ! is_int($row['rounding_scale'] ?? null)
            || ! is_string($row['rounding_stage'] ?? null) || $row['rounding_stage'] === ''
        ) {
            $rowIssues[] = ['domain' => 'assessment_profile_versions', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $minimumRules = $row['minimum_rules'] ?? null;

        return [
            'ulid' => $ulid,
            'profile_ulid' => $profileUlid,
            'version_number' => $row['version_number'],
            'status' => $row['status'],
            'is_current' => $row['is_current'],
            'scale' => $this->validScaleRef($row['scale'] ?? null),
            'domain_weight_mode' => $row['domain_weight_mode'],
            'period_result_mode' => $row['period_result_mode'],
            'accumulated_mode' => $this->nullableString($row['accumulated_mode'] ?? null),
            'absence_mode' => $this->nullableString($row['absence_mode'] ?? null),
            'rounding_mode' => $this->nullableString($row['rounding_mode'] ?? null),
            'rounding_scale' => $row['rounding_scale'],
            'rounding_stage' => $row['rounding_stage'],
            'minimum_rules' => is_array($minimumRules) ? $minimumRules : null,
            'activated_at' => $this->nullableDateTime($row['activated_at'] ?? null),
            'frozen_at' => $this->nullableDateTime($row['frozen_at'] ?? null),
            'superseded_at' => $this->nullableDateTime($row['superseded_at'] ?? null),
            'change_note' => $this->nullableString($row['change_note'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validProfileVersionDomainRow(array $row, array &$rowIssues): ?array
    {
        $versionUlid = $row['version_ulid'] ?? null;
        $domainUlid = $row['domain_ulid'] ?? null;

        if (! $this->isUlid($versionUlid) || ! $this->isUlid($domainUlid) || $this->nullableNumeric($row['weight_percent'] ?? null) === null
            || ! is_int($row['sequence'] ?? null)
        ) {
            $rowIssues[] = ['domain' => 'profile_version_domains', 'ulid' => null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'version_ulid' => $versionUlid,
            'domain_ulid' => $domainUlid,
            'weight_percent' => $this->nullableNumeric($row['weight_percent'] ?? null),
            'sequence' => $row['sequence'],
            'expected_element_count' => $this->nullableInt($row['expected_element_count'] ?? null),
            'minimum_element_count' => $this->nullableInt($row['minimum_element_count'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validProfileVersionPeriodRow(array $row, array &$rowIssues): ?array
    {
        $versionUlid = $row['version_ulid'] ?? null;
        $periodUlid = $row['academic_period_ulid'] ?? null;

        if (! $this->isUlid($versionUlid) || ! $this->isUlid($periodUlid)
            || ! is_bool($row['is_cumulative'] ?? null) || ! is_bool($row['contributes_to_accumulated'] ?? null)
        ) {
            $rowIssues[] = ['domain' => 'profile_version_periods', 'ulid' => null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'version_ulid' => $versionUlid,
            'academic_period_ulid' => $periodUlid,
            'is_cumulative' => $row['is_cumulative'],
            'period_weight_percent' => $this->nullableNumeric($row['period_weight_percent'] ?? null),
            'contributes_to_accumulated' => $row['contributes_to_accumulated'],
        ];
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
            || $this->nullableDate($row['applied_on'] ?? null) === null
        ) {
            $rowIssues[] = ['domain' => 'instruments', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'class_ulid' => $classUlid,
            'title' => $row['title'],
            'status' => $row['status'],
            'applied_on' => $row['applied_on'],
            'academic_period_ulid' => $this->nullableUlid($row['academic_period_ulid'] ?? null),
            'instrument_type' => $this->validInstrumentTypeRef($row['instrument_type'] ?? null),
            'purpose' => $this->nullableString($row['purpose'] ?? null),
            'counts_toward_classification' => $this->nullableBool($row['counts_toward_classification'] ?? null) ?? true,
            'total_points' => $this->nullableNumeric($row['total_points'] ?? null),
            'scale' => $this->validScaleRef($row['scale'] ?? null),
            'weight' => $this->nullableNumeric($row['weight'] ?? null),
            'allow_bonus' => $this->nullableBool($row['allow_bonus'] ?? null) ?? false,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validInstrumentGroupRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $instrumentUlid = $row['instrument_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($instrumentUlid) || ! is_int($row['sequence'] ?? null)) {
            $rowIssues[] = ['domain' => 'instrument_groups', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'instrument_ulid' => $instrumentUlid,
            'label' => $this->nullableString($row['label'] ?? null),
            'sequence' => $row['sequence'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validInstrumentItemRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $instrumentUlid = $row['instrument_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($instrumentUlid)
            || ! is_string($row['code'] ?? null) || $row['code'] === ''
            || ! is_int($row['sequence'] ?? null)
            || $this->nullableNumeric($row['points_possible'] ?? null) === null
            || ! is_string($row['scoring_mode'] ?? null) || $row['scoring_mode'] === ''
        ) {
            $rowIssues[] = ['domain' => 'instrument_items', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'instrument_ulid' => $instrumentUlid,
            'group_ulid' => $this->nullableUlid($row['group_ulid'] ?? null),
            'code' => $row['code'],
            'label' => $this->nullableString($row['label'] ?? null),
            'sequence' => $row['sequence'],
            'points_possible' => $this->nullableNumeric($row['points_possible'] ?? null),
            'scoring_mode' => $row['scoring_mode'],
            'scale' => $this->validScaleRef($row['scale'] ?? null),
            'is_bonus' => $this->nullableBool($row['is_bonus'] ?? null) ?? false,
            'source_group_label' => $this->nullableString($row['source_group_label'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validItemDomainAllocationRow(array $row, array &$rowIssues): ?array
    {
        $itemUlid = $row['item_ulid'] ?? null;
        $domainUlid = $row['domain_ulid'] ?? null;

        if (! $this->isUlid($itemUlid) || ! $this->isUlid($domainUlid) || $this->nullableNumeric($row['allocation_percent'] ?? null) === null) {
            $rowIssues[] = ['domain' => 'item_domain_allocations', 'ulid' => null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'item_ulid' => $itemUlid,
            'domain_ulid' => $domainUlid,
            'allocation_percent' => $this->nullableNumeric($row['allocation_percent'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validStudentItemScoreRow(array $row, array &$rowIssues): ?array
    {
        $itemUlid = $row['item_ulid'] ?? null;
        $enrollmentUlid = $row['enrollment_ulid'] ?? null;

        if (! $this->isUlid($itemUlid) || ! $this->isUlid($enrollmentUlid)
            || ResultState::tryFrom((string) ($row['result_state'] ?? '')) === null
        ) {
            $rowIssues[] = ['domain' => 'student_item_scores', 'ulid' => null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'item_ulid' => $itemUlid,
            'enrollment_ulid' => $enrollmentUlid,
            'result_state' => $row['result_state'],
            'points_earned' => $this->nullableNumeric($row['points_earned'] ?? null),
            'scale_level' => $this->validScaleLevelRef($row['scale_level'] ?? null),
            'state_reason' => $this->nullableString($row['state_reason'] ?? null),
            'assessed_at' => $this->nullableDateTime($row['assessed_at'] ?? null),
            'assessed_by_email' => $this->nullableString($row['assessed_by_email'] ?? null),
        ];
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

        return [
            'ulid' => $ulid,
            'enrollment_ulid' => $enrollmentUlid,
            'academic_period_ulid' => $this->nullableUlid($row['academic_period_ulid'] ?? null),
            'assessment_profile_version_ulid' => $this->nullableUlid($row['assessment_profile_version_ulid'] ?? null),
            'scope' => $row['scope'],
            'status' => $row['status'],
            'proposed_normalized_value' => $this->nullableNumeric($row['proposed_normalized_value'] ?? null),
            'proposed_value' => $this->nullableNumeric($row['proposed_value'] ?? null),
            'proposed_scale_level' => $this->validScaleLevelRef($row['proposed_scale_level'] ?? null),
            'final_value' => $this->nullableNumeric($row['final_value'] ?? null),
            'final_scale_level' => $this->validScaleLevelRef($row['final_scale_level'] ?? null),
            'override_reason' => $this->nullableString($row['override_reason'] ?? null),
            'overridden_by_email' => $this->nullableString($row['overridden_by_email'] ?? null),
            'overridden_at' => $this->nullableDateTime($row['overridden_at'] ?? null),
            'confirmed_by_email' => $this->nullableString($row['confirmed_by_email'] ?? null),
            'confirmed_at' => $this->nullableDateTime($row['confirmed_at'] ?? null),
            'published_at' => $this->nullableDateTime($row['published_at'] ?? null),
            'superseded_by_ulid' => $this->nullableUlid($row['superseded_by_ulid'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validSelfAssessmentTemplateRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! is_string($row['name'] ?? null) || $row['name'] === '' || ! is_bool($row['is_active'] ?? null)) {
            $rowIssues[] = ['domain' => 'self_assessment_templates', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'name' => $row['name'],
            'is_active' => $row['is_active'],
            'assessment_profile_version_ulid' => $this->nullableUlid($row['assessment_profile_version_ulid'] ?? null),
            'class_ulid' => $this->nullableUlid($row['class_ulid'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validSelfAssessmentQuestionRow(array $row, array &$rowIssues): ?array
    {
        $templateUlid = $row['template_ulid'] ?? null;
        $allowedAnswerKinds = ['scale', 'text', 'boolean'];

        if (! $this->isUlid($templateUlid) || ! is_string($row['prompt'] ?? null) || $row['prompt'] === ''
            || ! in_array($row['answer_kind'] ?? null, $allowedAnswerKinds, true)
            || ! is_int($row['sequence'] ?? null)
        ) {
            $rowIssues[] = ['domain' => 'self_assessment_questions', 'ulid' => null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'template_ulid' => $templateUlid,
            'role' => $this->nullableString($row['role'] ?? null),
            'prompt' => $row['prompt'],
            'answer_kind' => $row['answer_kind'],
            'domain_ulid' => $this->nullableUlid($row['domain_ulid'] ?? null),
            'scale' => $this->validScaleRef($row['scale'] ?? null),
            'sequence' => $row['sequence'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validSelfAssessmentRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $enrollmentUlid = $row['enrollment_ulid'] ?? null;
        $periodUlid = $row['academic_period_ulid'] ?? null;
        $templateUlid = $row['template_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($enrollmentUlid) || ! $this->isUlid($periodUlid) || ! $this->isUlid($templateUlid)
            || SelfAssessmentStatus::tryFrom((string) ($row['status'] ?? '')) === null
            || SelfAssessmentFilledBy::tryFrom((string) ($row['filled_by'] ?? '')) === null
        ) {
            $rowIssues[] = ['domain' => 'self_assessments', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'enrollment_ulid' => $enrollmentUlid,
            'academic_period_ulid' => $periodUlid,
            'template_ulid' => $templateUlid,
            'status' => $row['status'],
            'filled_by' => $row['filled_by'],
            'reflection' => $this->nullableString($row['reflection'] ?? null),
            'submitted_at' => $this->nullableDateTime($row['submitted_at'] ?? null),
            'reviewed_at' => $this->nullableDateTime($row['reviewed_at'] ?? null),
            'reviewed_by_email' => $this->nullableString($row['reviewed_by_email'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validSelfAssessmentResponseRow(array $row, array &$rowIssues): ?array
    {
        $selfAssessmentUlid = $row['self_assessment_ulid'] ?? null;

        if (! $this->isUlid($selfAssessmentUlid)) {
            $rowIssues[] = ['domain' => 'self_assessment_responses', 'ulid' => null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $booleanValue = $row['boolean_value'] ?? null;

        return [
            'self_assessment_ulid' => $selfAssessmentUlid,
            'question_role' => $this->nullableString($row['question_role'] ?? null),
            'question_sequence' => $this->nullableInt($row['question_sequence'] ?? null),
            'scale_level' => $this->validScaleLevelRef($row['scale_level'] ?? null),
            'text_value' => $this->nullableString($row['text_value'] ?? null),
            'boolean_value' => is_bool($booleanValue) ? $booleanValue : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validInterimAssessmentRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $classUlid = $row['class_ulid'] ?? null;
        $periodUlid = $row['academic_period_ulid'] ?? null;
        $snapshot = $row['snapshot'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($classUlid) || ! $this->isUlid($periodUlid)
            || ! is_string($row['name'] ?? null) || $row['name'] === ''
            || $this->nullableDate($row['reference_date'] ?? null) === null
            || ! is_int($row['snapshot_version'] ?? null)
            || ! is_array($snapshot)
            || ! is_string($row['snapshot_hash'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/', (string) $row['snapshot_hash'])
        ) {
            $rowIssues[] = ['domain' => 'interim_assessments', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        return [
            'ulid' => $ulid,
            'class_ulid' => $classUlid,
            'academic_period_ulid' => $periodUlid,
            'name' => $row['name'],
            'reference_date' => $row['reference_date'],
            'note' => $this->nullableString($row['note'] ?? null),
            'snapshot_version' => $row['snapshot_version'],
            'snapshot' => $snapshot,
            'snapshot_hash' => $row['snapshot_hash'],
            'created_by_email' => $this->nullableString($row['created_by_email'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validEvidenceRecordRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $classUlid = $row['class_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($classUlid)
            || $this->nullableDateTime($row['occurred_at'] ?? null) === null
            || EvidenceKind::tryFrom((string) ($row['kind'] ?? '')) === null
            || ! is_string($row['description'] ?? null) || $row['description'] === '' || strlen((string) $row['description']) > 1000
        ) {
            $rowIssues[] = ['domain' => 'evidence_records', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $homeworkStatus = $row['homework_status'] ?? null;
        $participationLevel = $row['participation_level'] ?? null;
        $activityEvaluation = $row['activity_evaluation'] ?? null;
        $disciplinarySeverity = $row['disciplinary_severity'] ?? null;

        return [
            'ulid' => $ulid,
            'class_ulid' => $classUlid,
            'enrollment_ulid' => $this->nullableUlid($row['enrollment_ulid'] ?? null),
            'academic_period_ulid' => $this->nullableUlid($row['academic_period_ulid'] ?? null),
            'domain_ulid' => $this->nullableUlid($row['domain_ulid'] ?? null),
            'quick_rating_scale_level' => $this->validScaleLevelRef($row['quick_rating_scale_level'] ?? null),
            'occurred_at' => $row['occurred_at'],
            'kind' => $row['kind'],
            'description' => $row['description'],
            'activity_include_in_report' => $this->nullableBool($row['activity_include_in_report'] ?? null) ?? false,
            'homework_status' => HomeworkStatus::tryFrom((string) $homeworkStatus)?->value,
            'participation_level' => ParticipationLevel::tryFrom((string) $participationLevel)?->value,
            'activity_evaluation' => ActivityEvaluation::tryFrom((string) $activityEvaluation)?->value,
            'disciplinary_severity' => DisciplinarySeverity::tryFrom((string) $disciplinarySeverity)?->value,
            'created_by_email' => $this->nullableString($row['created_by_email'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validInterventionRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $classUlid = $row['class_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($classUlid)
            || InterventionTargetType::tryFrom((string) ($row['target_type'] ?? '')) === null
            || ! is_string($row['title'] ?? null) || $row['title'] === ''
            || InterventionStatus::tryFrom((string) ($row['status'] ?? '')) === null
            || $this->nullableDate($row['started_on'] ?? null) === null
        ) {
            $rowIssues[] = ['domain' => 'interventions', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $participantsIn = $row['participant_enrollment_ulids'] ?? [];
        $participants = is_array($participantsIn) ? array_values(array_filter($participantsIn, fn (mixed $v): bool => $this->isUlid($v))) : [];

        return [
            'ulid' => $ulid,
            'class_ulid' => $classUlid,
            'enrollment_ulid' => $this->nullableUlid($row['enrollment_ulid'] ?? null),
            'participant_enrollment_ulids' => $participants,
            'academic_period_ulid' => $this->nullableUlid($row['academic_period_ulid'] ?? null),
            'domain_ulid' => $this->nullableUlid($row['domain_ulid'] ?? null),
            'target_type' => $row['target_type'],
            'intervention_type' => $this->nullableString($row['intervention_type'] ?? null),
            'motive_code' => $this->nullableString($row['motive_code'] ?? null),
            'motive_label' => $this->nullableString($row['motive_label'] ?? null),
            'strategy_code' => $this->nullableString($row['strategy_code'] ?? null),
            'strategy_label' => $this->nullableString($row['strategy_label'] ?? null),
            'objective' => $this->nullableString($row['objective'] ?? null),
            'domain_relation' => $this->nullableString($row['domain_relation'] ?? null),
            'title' => $row['title'],
            'description' => $this->nullableString($row['description'] ?? null),
            'description_source' => $this->nullableString($row['description_source'] ?? null),
            'status' => $row['status'],
            'started_on' => $row['started_on'],
            'expected_end_on' => $this->nullableDate($row['expected_end_on'] ?? null),
            'concluded_on' => $this->nullableDate($row['concluded_on'] ?? null),
            'review_on' => $this->nullableDate($row['review_on'] ?? null),
            'available_for_reports' => $this->nullableBool($row['available_for_reports'] ?? null) ?? true,
            'support_measure_level' => $this->nullableString($row['support_measure_level'] ?? null),
            'support_measure_code' => $this->nullableString($row['support_measure_code'] ?? null),
            'evaluation_adaptation_code' => $this->nullableString($row['evaluation_adaptation_code'] ?? null),
            'legal_mapping_source' => $this->nullableString($row['legal_mapping_source'] ?? null),
            'created_by_email' => $this->nullableString($row['created_by_email'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validInterventionReviewRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $interventionUlid = $row['intervention_ulid'] ?? null;

        if (! $this->isUlid($ulid) || ! $this->isUlid($interventionUlid) || $this->nullableDate($row['reviewed_on'] ?? null) === null) {
            $rowIssues[] = ['domain' => 'intervention_reviews', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $effectiveness = $row['effectiveness'] ?? null;

        return [
            'ulid' => $ulid,
            'intervention_ulid' => $interventionUlid,
            'reviewed_on' => $row['reviewed_on'],
            'effectiveness' => InterventionEffectiveness::tryFrom((string) $effectiveness)?->value,
            'notes' => $this->nullableString($row['notes'] ?? null),
            'reviewed_by_email' => $this->nullableString($row['reviewed_by_email'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return array<string, mixed>|null
     */
    private function validReportRow(array $row, array &$rowIssues): ?array
    {
        $ulid = $row['ulid'] ?? null;
        $document = $row['document'] ?? null;

        if (! $this->isUlid($ulid)
            || ReportType::tryFrom((string) ($row['type'] ?? '')) === null
            || ! is_string($row['title'] ?? null) || $row['title'] === ''
            || ReportTone::tryFrom((string) ($row['tone'] ?? '')) === null
            || ReportScopeKind::tryFrom((string) ($row['scope_kind'] ?? '')) === null
            || ! is_array($document)
            || ! is_string($row['document_hash'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/', (string) $row['document_hash'])
        ) {
            $rowIssues[] = ['domain' => 'reports', 'ulid' => is_string($ulid) ? $ulid : null, 'reason' => $this->t('Campos obrigatórios em falta ou inválidos.')];

            return null;
        }

        $templateSnapshot = $row['template_snapshot'] ?? null;

        return [
            'ulid' => $ulid,
            'type' => $row['type'],
            'title' => $row['title'],
            'tone' => $row['tone'],
            'scope_kind' => $row['scope_kind'],
            'scope_label' => $this->nullableString($row['scope_label'] ?? null),
            'starts_on' => $this->nullableDate($row['starts_on'] ?? null),
            'ends_on' => $this->nullableDate($row['ends_on'] ?? null),
            'class_ulid' => $this->nullableUlid($row['class_ulid'] ?? null),
            'enrollment_ulid' => $this->nullableUlid($row['enrollment_ulid'] ?? null),
            'academic_year' => $this->nullableString($row['academic_year'] ?? null),
            'academic_period_ulid' => $this->nullableUlid($row['academic_period_ulid'] ?? null),
            'interim_assessment_ulid' => $this->nullableUlid($row['interim_assessment_ulid'] ?? null),
            'document' => $document,
            'document_version' => $this->nullableInt($row['document_version'] ?? null),
            'document_hash' => $row['document_hash'],
            'finalized_at' => $this->nullableDateTime($row['finalized_at'] ?? null),
            'finalized_by_email' => $this->nullableString($row['finalized_by_email'] ?? null),
            'created_by_email' => $this->nullableString($row['created_by_email'] ?? null),
            'based_on_report_ulid' => $this->nullableUlid($row['based_on_report_ulid'] ?? null),
            'template_key' => $this->nullableString($row['template_key'] ?? null),
            'template_snapshot' => is_array($templateSnapshot) ? $templateSnapshot : null,
        ];
    }
}
