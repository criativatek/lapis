<?php

namespace App\Services\Export;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Services\Assessment\DecisionScaleReader;
use Illuminate\Database\Eloquent\Collection;

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
 *
 * A VALUE DECIDED ON ANOTHER SCALE IS NOT A LEVEL OF THIS ONE. A class migrated
 * from «Percentagem (0 a 100)» to «Escala 1 a 5» keeps its published 65 as 65 %,
 * and writing «65» in the level column would hand the school a level that does
 * not exist. Such a student is left out of `for()` — the cell stays empty — and
 * `onAnotherScale()` says which scale the decision was taken on, so the export
 * can tell the teacher why.
 */
class DecidedLevelValues
{
    public function __construct(protected DecisionScaleReader $decisionScales) {}

    /**
     * @param  list<int>  $enrollmentIds
     * @return array<int, string> enrollment id → the value to write; absent when nothing was decided
     */
    public function for(AcademicPeriod $period, ClassificationScope $scope, array $enrollmentIds): array
    {
        $values = [];

        foreach ($this->classifications($period, $scope, $enrollmentIds) as $classification) {
            $value = $this->valueFor($classification);

            if ($value !== null) {
                $values[(int) $classification->enrollment_id] = $value;
            }
        }

        return $values;
    }

    /**
     * The students whose decision is a value taken on a scale other than the one
     * their class is on now, with the NAME of that scale.
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, string> enrollment id → name of the scale the decision was taken on
     */
    public function onAnotherScale(AcademicPeriod $period, ClassificationScope $scope, array $enrollmentIds): array
    {
        $elsewhere = [];

        foreach ($this->classifications($period, $scope, $enrollmentIds) as $classification) {
            $name = $this->foreignScaleOf($classification);

            if ($name !== null) {
                $elsewhere[(int) $classification->enrollment_id] = $name;
            }
        }

        return $elsewhere;
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @return Collection<int, Classification>
     */
    protected function classifications(AcademicPeriod $period, ClassificationScope $scope, array $enrollmentIds): Collection
    {
        if ($enrollmentIds === []) {
            return new Collection;
        }

        return Classification::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where('academic_period_id', $period->getKey())
            ->where('scope', $scope)
            ->whereNull('superseded_by_id')
            ->with(['finalScaleLevel', 'enrollment.schoolClass'])
            ->get();
    }

    protected function foreignScaleOf(Classification $classification): ?string
    {
        $versionId = $classification->enrollment->schoolClass->assessment_profile_version_id ?? null;
        $currentScale = $versionId === null ? null : $this->decisionScales->scaleOfVersion((int) $versionId);

        return $this->decisionScales->read($classification, $currentScale)['foreign_scale'];
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

        // Decided on another scale (the class was migrated afterwards): it is
        // not a level of this one, and is never written as if it were.
        if ($this->foreignScaleOf($classification) !== null) {
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
