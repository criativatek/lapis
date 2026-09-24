<?php

namespace App\Support\Interventions;

use App\Models\Enrollment;
use App\Models\Intervention;
use App\Models\InterventionStatus;
use App\Models\SupportMeasureCode;

/**
 * §30's dedup, both places a measure can be held.
 *
 * Extracted from `ApplyCharacterisationImport::activeInterventionExists()` so
 * the manual "Adicionar medida" path (ClassCharacterisationController) can
 * apply the exact same rule instead of growing a second, slightly different
 * one. See that class's former docblock for the full reasoning, preserved
 * below.
 *
 * A hand-created intervention that carries several measures stores only the
 * FIRST pair on the parent's own `support_measure_code` column — the rest
 * live exclusively in the `intervention_support_measures` pivot (see
 * `CreateIntervention::create()`). Checking the parent column alone misses
 * every measure but the first on such a row, so both places are checked here.
 *
 * THIS IS DELIBERATELY THE ONLY DIFFERENTIATOR. A difference in period,
 * context, framework or status is exactly the kind of thing this method
 * refuses to reason about silently: rather than guess whether it is the
 * "same" measure under those differences, it takes the safe branch — it does
 * not create a second one — and leaves the existing record alone for a
 * person to look at. That is why this checks "is there an active one at
 * all", not "is there one that also matches on every other field".
 */
final class ActiveSupportMeasures
{
    public static function exists(Enrollment $enrollment, SupportMeasureCode $code): bool
    {
        $activeStatuses = [InterventionStatus::New->value, InterventionStatus::InProgress->value];

        return Intervention::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereIn('status', $activeStatuses)
            ->where(function ($query) use ($code): void {
                $query->where('support_measure_code', $code->value)
                    ->orWhereHas('supportMeasures', function ($measures) use ($code): void {
                        $measures->where('support_measure_code', $code->value);
                    });
            })
            ->exists();
    }
}
