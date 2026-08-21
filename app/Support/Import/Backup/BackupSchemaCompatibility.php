<?php

namespace App\Support\Import\Backup;

/**
 * How this app's importer relates to a backup's declared `schema_version`
 * (§4 of the import brief). Never inferred silently — every backup is
 * classified into exactly one of these before anything else happens.
 *
 * CURRENT (3) is what GenerateDataExport writes today. MINIMUM_SUPPORTED (2)
 * is the oldest shape this importer still reads — schema_version 2 backups
 * lack `enrollments[].enrolled_on`, so rows that would need it to be
 * CREATED are classified `unsupported` by BuildImportPlan rather than
 * refused outright; everything else in a v2 backup restores normally.
 * There is no version below 2 in the wild (schema_version was introduced
 * at 2), so no legacy normalizer exists — §56 is deliberately not built.
 */
enum BackupSchemaCompatibility
{
    case Supported;
    case LegacyCompatible;
    case UnsupportedNewer;
    case Invalid;

    public const int CURRENT = 3;

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
