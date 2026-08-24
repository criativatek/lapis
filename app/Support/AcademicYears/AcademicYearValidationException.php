<?php

namespace App\Support\AcademicYears;

use RuntimeException;

/**
 * A rule an academic year's periods must satisfy that spans rows — whether a
 * period dropped from a save still has data depending on it — so it cannot be
 * a CHECK constraint and lives here instead, mirroring the role
 * InstrumentValidationException plays for instruments.
 */
class AcademicYearValidationException extends RuntimeException
{
    /**
     * Every dependent table (calculation_snapshots, classifications,
     * instruments... — see AcademicYearService::DEPENDENT_MODELS) has a
     * RESTRICT foreign key to academic_periods.id. Without this check that
     * constraint would reject the DELETE with a raw database error instead
     * of this message.
     */
    public static function cannotRemovePeriodWithDependents(string $periodLabel): self
    {
        return new self(__(
            'O período :label já tem dados associados e não pode ser eliminado.',
            ['label' => $periodLabel],
        ));
    }
}
