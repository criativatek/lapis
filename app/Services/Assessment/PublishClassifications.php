<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Instrument;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Publication is the last step of a decision (§13.2): a confirmed classification
 * becomes communicated. It recalculates nothing (§ state table, row 9) — only the
 * status and published_at change. A classification whose result still has an
 * element under review is held back: a pending complaint blocks publication of
 * that period's grade (§5, under_review).
 */
class PublishClassifications
{
    public function __construct(protected ClassResultsCalculator $calculator) {}

    /**
     * @return array{published: int, blocked_under_review: int}
     */
    public function forPeriod(SchoolClass $class, AcademicPeriod $period, ClassificationScope $scope): array
    {
        $periodIds = $this->calculator->periodIdsInScope($class, $period, $scope);

        // Scoped to THIS class's enrollments — periods belong to the academic year,
        // not the class, so two classes share academic_period_id. Without this
        // filter, publishing one class would publish another's grades (and skip its
        // under-review guard) — a cross-class authorization hole within the org.
        $confirmedIds = Classification::query()
            ->whereIn('enrollment_id', $class->enrollments()->select('id'))
            ->where('academic_period_id', $period->id)
            ->where('scope', $scope)
            ->where('status', ClassificationStatus::Confirmed)
            ->pluck('id');

        // The instruments whose elements a review can block: only those that count
        // and are in a state the engine reads (the calculation universe, §570).
        $countingInstrumentIds = Instrument::query()
            ->where('class_id', $class->id)
            ->whereIn('academic_period_id', $periodIds)
            ->where('counts_toward_classification', true)
            ->get()
            ->filter(fn (Instrument $instrument) => $instrument->status->entersCalculation())
            ->pluck('id');

        $counts = ['published' => 0, 'blocked_under_review' => 0];

        DB::transaction(function () use ($confirmedIds, $countingInstrumentIds, &$counts): void {
            foreach ($confirmedIds as $id) {
                // Re-fetch under a row lock and re-check the state: a concurrent
                // publish or supersede between listing and writing must not be
                // overwritten (same guard as ConfirmClassification).
                $classification = Classification::query()
                    ->whereKey($id)
                    ->where('status', ClassificationStatus::Confirmed)
                    ->lockForUpdate()
                    ->first();

                if ($classification === null) {
                    continue;
                }

                if ($this->hasElementUnderReview($classification->enrollment_id, $countingInstrumentIds)) {
                    $counts['blocked_under_review']++;

                    continue;
                }

                // Publishing recalculates nothing — it only marks the decision as
                // communicated. The final value stays exactly as confirmed.
                $classification->fill([
                    'status' => ClassificationStatus::Published,
                    'published_at' => now(),
                ])->save();
                $counts['published']++;
            }
        });

        return $counts;
    }

    /**
     * @param  Collection<int, int>  $countingInstrumentIds
     */
    protected function hasElementUnderReview(int $enrollmentId, $countingInstrumentIds): bool
    {
        return StudentItemScore::query()
            ->where('enrollment_id', $enrollmentId)
            ->whereIn('instrument_id', $countingInstrumentIds)
            ->where('result_state', ResultState::UnderReview->value)
            ->exists();
    }
}
