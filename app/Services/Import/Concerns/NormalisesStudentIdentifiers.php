<?php

namespace App\Services\Import\Concerns;

use Illuminate\Support\Str;

/**
 * How a student's process number and name are compared, wherever an import has
 * to decide who a row is about.
 *
 * Extracted so that the roster importer and the characterisation importer
 * compare identifiers the same way. Two importers that normalise names
 * differently would disagree about who a row is about while both looking
 * correct in their own tests, and the disagreement would only ever surface as
 * one child's text on another child's record.
 */
trait NormalisesStudentIdentifiers
{
    /**
     * Trimmed and upper-cased, and nothing else.
     *
     * A leading zero is part of somebody's identifier and not formatting to
     * tidy away — the same rule StudentEnrollmentService::setProcessNumber()
     * states when it stores the value. Both sides of the comparison come from
     * the school's own export anyway, so there is nothing to reconcile beyond
     * stray spaces and a letter's case.
     */
    protected function normalizeProcessNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::upper(trim($value));

        return $value === '' ? null : $value;
    }

    protected function normalizeName(string $value): string
    {
        return Str::of($value)->squish()->lower()->value();
    }
}
