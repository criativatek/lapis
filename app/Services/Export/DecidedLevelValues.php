<?php

namespace App\Services\Export;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;

/**
 * The level to write in the grid — THE TEACHER'S DECISION, and nothing else.
 *
 * ONLY `final_*` IS EVER READ. `proposed_value` and `proposed_scale_level_id`
 * are the engine's conclusion, and a conclusion the system drew is not a
 * classification; writing one into a file a school uploads would be the system
 * confirming a grade on its own, which it never does (§3.3). A student whose
 * teacher has not decided yet is simply absent from what this returns, the cell
 * is left exactly as the grid had it, and the export says so out loud. Never a
 * zero, never the proposal, never a dash (§13.3).
 *
 * THE BAND'S OWN `code`, not its label and not its INOVAR code. The real grid's
 * level column carries «3», «4», «5» — the scale's own short names for its
 * bands — and that is the column's vocabulary. The INOVAR codes F/I/S/B/MB
 * belong to the qualitative columns and are resolved elsewhere; putting one of
 * those here would be answering a different question.
 */
class DecidedLevelValues
{
    /**
     * @param  list<int>  $enrollmentIds
     * @return array<int, string> enrollment id → the value to write; absent when nothing was decided
     */
    public function for(AcademicPeriod $period, ClassificationScope $scope, array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $classifications = Classification::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where('academic_period_id', $period->getKey())
            ->where('scope', $scope)
            ->whereNull('superseded_by_id')
            ->with('finalScaleLevel')
            ->get();

        $values = [];

        foreach ($classifications as $classification) {
            $value = $this->valueFor($classification);

            if ($value !== null) {
                $values[(int) $classification->enrollment_id] = $value;
            }
        }

        return $values;
    }

    protected function valueFor(Classification $classification): ?string
    {
        $level = $classification->finalScaleLevel;

        if ($level !== null && trim((string) $level->code) !== '') {
            return trim((string) $level->code);
        }

        // No band, but a decided value: a scale that is an interval expresses
        // the decision as the number itself.
        if ($classification->final_value === null) {
            return null;
        }

        return $this->withoutTrailingZeros((string) $classification->final_value);
    }

    /**
     * «14.000» is «14». The column is cast `decimal:3` because a grade is not a
     * float, and the trailing zeros that come with that are formatting, not
     * information — a school reading «14.000» in a grid would read it as noise.
     */
    protected function withoutTrailingZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
