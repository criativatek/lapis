<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\DB;

/**
 * Turns the engine's period results into proposals the teacher can act on. It
 * only ever writes `proposed` rows and refreshes them; a `confirmed` or
 * `published` classification is a decision the teacher already made and is left
 * untouched (§7.1) — re-proposing never rewrites a grade.
 *
 * A student whose result is not computable (every element excluded → no value)
 * gets no proposal row: there is nothing to propose, and an empty proposal would
 * read as a zero, which it is not (§9).
 */
class ProposeClassifications
{
    public function __construct(protected ClassResultsCalculator $calculator) {}

    /**
     * @return array{created: int, updated: int, skipped_frozen: int, no_value: int}
     */
    public function forPeriod(SchoolClass $class, AcademicPeriod $period): array
    {
        $version = $class->profileVersion;

        if ($version === null) {
            return ['created' => 0, 'updated' => 0, 'skipped_frozen' => 0, 'no_value' => 0];
        }

        $results = $this->calculator->forPeriod($class, $period);
        $counts = ['created' => 0, 'updated' => 0, 'skipped_frozen' => 0, 'no_value' => 0];

        DB::transaction(function () use ($results, $period, $version, &$counts): void {
            foreach ($results as $row) {
                $outcome = $row['outcome'];

                if (! $outcome->hasValue()) {
                    $counts['no_value']++;

                    continue;
                }

                $live = Classification::query()
                    ->where('enrollment_id', $row['enrollment']->id)
                    ->where('academic_period_id', $period->id)
                    ->where('scope', ClassificationScope::Period)
                    ->whereNot('status', ClassificationStatus::Superseded)
                    ->first();

                if ($live !== null && $live->status->isFrozen()) {
                    $counts['skipped_frozen']++;

                    continue;
                }

                $proposal = [
                    // Refreshed on every re-propose: if the class moved to a new
                    // profile version, the row must not keep pointing at the old
                    // one while its values came from the new (§10.2).
                    'assessment_profile_version_id' => $version->id,
                    'proposed_normalized_value' => $outcome->normalizedValue,
                    'proposed_value' => $outcome->proposedValue,
                    // Q1: no scale bands configured, so the engine proposes no level.
                    'proposed_scale_level_id' => null,
                ];

                if ($live !== null) {
                    $live->fill($proposal)->save();
                    $counts['updated']++;

                    continue;
                }

                Classification::create([
                    'enrollment_id' => $row['enrollment']->id,
                    'academic_period_id' => $period->id,
                    'scope' => ClassificationScope::Period,
                    'assessment_profile_version_id' => $version->id,
                    'status' => ClassificationStatus::Proposed,
                    ...$proposal,
                ]);
                $counts['created']++;
            }
        });

        return $counts;
    }
}
