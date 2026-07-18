<?php

namespace App\Services;

use App\Models\AcademicPeriodStatus;
use App\Models\AcademicYear;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates an academic year together with its periods, in one
 * transaction (§24.2). A half-written year with missing periods is never left
 * behind if anything fails partway.
 */
class AcademicYearService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $periods
     */
    public function create(array $attributes, array $periods): AcademicYear
    {
        return DB::transaction(function () use ($attributes, $periods): AcademicYear {
            $year = AcademicYear::create($attributes);
            $this->syncPeriods($year, $periods);

            return $year;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $periods
     */
    public function update(AcademicYear $year, array $attributes, array $periods): AcademicYear
    {
        return DB::transaction(function () use ($year, $attributes, $periods): AcademicYear {
            $year->update($attributes);
            $this->syncPeriods($year, $periods);

            return $year->refresh();
        });
    }

    /**
     * Replaces the year's periods with the submitted set.
     *
     * ponytail: replace-all, not diff-and-patch. Fine while periods carry no
     * results — nothing references them yet. Once instruments and results hang
     * off a period, this must become a real diff that refuses to delete a period
     * with dependent data. The FK is RESTRICT, so that day it fails loud, not
     * silently.
     *
     * @param  list<array<string, mixed>>  $periods
     */
    protected function syncPeriods(AcademicYear $year, array $periods): void
    {
        $year->periods()->delete();

        foreach ($periods as $period) {
            // Periods start as draft; opening/closing them is a separate action,
            // not part of setting up the year's structure.
            $year->periods()->create([...$period, 'status' => AcademicPeriodStatus::Draft->value]);
        }
    }
}
