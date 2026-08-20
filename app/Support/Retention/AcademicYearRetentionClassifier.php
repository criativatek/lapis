<?php

namespace App\Support\Retention;

use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use Illuminate\Support\Collection;

/**
 * Classifies an organization's academic years as within the identifiable-data
 * retention window or eligible for review — never "eligible for deletion":
 * this class is read-only and triggers nothing on its own.
 *
 * Retention is counted in ACADEMIC YEARS, ordered by starts_on descending
 * (the ordering convention used everywhere else in this codebase), never by
 * raw created_at/timestamp comparison. A record entered late for a prior year
 * belongs to that year, not to whenever it happened to be typed.
 */
final class AcademicYearRetentionClassifier
{
    public function __construct(private readonly RetentionPolicy $policy) {}

    /**
     * @param  Collection<int, AcademicYear>  $academicYears
     * @return Collection<int, array{year: AcademicYear, within_retention: bool}>
     */
    public function classify(Collection $academicYears, AcademicYear $currentYear): Collection
    {
        $orderedYears = $academicYears->sortByDesc(fn (AcademicYear $academicYear): string => $academicYear->starts_on->toDateString())->values();

        $currentYearRank = $orderedYears->search(
            fn (AcademicYear $academicYear): bool => $academicYear->is($currentYear)
        );

        // The reference year is not part of the collection being classified —
        // nothing can be said to be "relative to" it, so nothing is within
        // retention.
        if ($currentYearRank === false) {
            return $orderedYears->map(fn (AcademicYear $academicYear): array => [
                'year' => $academicYear,
                'within_retention' => false,
            ]);
        }

        $lastRetainedRank = $currentYearRank + $this->policy->pedagogicalPreviousYearsRetained();

        return $orderedYears->map(fn (AcademicYear $academicYear, int $rank): array => [
            'year' => $academicYear,
            'within_retention' => $rank >= $currentYearRank && $rank <= $lastRetainedRank,
        ]);
    }

    /**
     * A read-only heuristic for reporting/preview purposes only — never used
     * to trigger any destructive action. Picks the AcademicYearStatus::Active
     * year when there is exactly one; otherwise falls back to the most recent
     * year by starts_on. Returns null when the organization has no academic
     * years at all.
     *
     * @param  Collection<int, AcademicYear>  $academicYears
     */
    public function currentYearFor(Collection $academicYears): ?AcademicYear
    {
        if ($academicYears->isEmpty()) {
            return null;
        }

        $activeYears = $academicYears->filter(
            fn (AcademicYear $academicYear): bool => $academicYear->status === AcademicYearStatus::Active
        );

        if ($activeYears->count() === 1) {
            return $activeYears->first();
        }

        return $academicYears
            ->sortByDesc(fn (AcademicYear $academicYear): string => $academicYear->starts_on->toDateString())
            ->first();
    }
}
