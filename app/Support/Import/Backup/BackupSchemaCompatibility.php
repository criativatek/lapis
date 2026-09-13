<?php

namespace App\Support\Import\Backup;

/**
 * How this app's importer relates to a backup's declared `schema_version`
 * (§4 of the import brief). Never inferred silently — every backup is
 * classified into exactly one of these before anything else happens.
 *
 * CURRENT (8) is what GenerateDataExport writes today. Version 8 adds
 * `classes[].is_support_class` (absent in older backups, read as false).
 * Version 7 adds
 * intervention batch provenance and multiple legal support measures. Fatia "multi-grade
 * profiles" adds `assessment_profiles[].grade_levels` (a profile may now
 * cover more than one grade level) alongside everything schema_version 5
 * already restored: the "schema v2" capability tier of Fatia 6.2
 * (docs/backup-schema.md) — first-class academic years and subjects, plus
 * the schema v4 assessment structure (profiles/domains/scales),
 * elements/items/scores, persisted classifications, self-assessments,
 * interim assessments, pedagogical records and finalized reports, alongside
 * everything schema_version 3 already restored. MINIMUM_SUPPORTED (2) is the
 * oldest shape this importer still reads — schema_version 2/3 backups
 * ("schema v1" tier) carry none of the new pedagogical collections at all,
 * so every new collection key is simply absent from their JSON; `?? []`
 * fallbacks throughout ValidateBackupPayload/BuildImportPlan turn that
 * absence into empty arrays automatically — no separate v1/v2 validator or
 * importer class, no branching on schema_version anywhere in the pipeline.
 * A schema_version ≤5 backup lacks `assessment_profiles[].grade_levels`
 * entirely but still carries the old singular `grade_level`;
 * ValidateBackupPayload reads both, so restoring one such backup still
 * recovers the (at most one) grade level it had. A schema_version 2 backup
 * additionally lacks `enrollments[].enrolled_on`, so rows that would need it
 * to be CREATED stay `unsupported`; everything else restores normally at
 * whatever tier the backup actually carries.
 */
enum BackupSchemaCompatibility
{
    case Supported;
    case LegacyCompatible;
    case UnsupportedNewer;
    case Invalid;

    public const int CURRENT = 8;

    public const int MINIMUM_SUPPORTED = 2;

    public static function for(mixed $schemaVersion): self
    {
        if (! is_int($schemaVersion) || $schemaVersion < self::MINIMUM_SUPPORTED) {
            return self::Invalid;
        }

        if ($schemaVersion > self::CURRENT) {
            return self::UnsupportedNewer;
        }

        return $schemaVersion === self::CURRENT ? self::Supported : self::LegacyCompatible;
    }

    public function isReadable(): bool
    {
        return $this === self::Supported || $this === self::LegacyCompatible;
    }
}
