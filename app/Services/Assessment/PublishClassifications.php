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

        $confirmed = Classification::query()
            ->where('academic_period_id', $period->id)
            ->where('scope', $scope)
            ->where('status', ClassificationStatus::Confirmed)
            ->get();

        $counts = ['published' => 0, 'blocked_under_review' => 0];

        DB::transaction(function () use ($confirmed, $class, $periodIds, &$counts): void {
            foreach ($confirmed as $classification) {
                if ($this->hasElementUnderReview($class, $classification->enrollment_id, $periodIds)) {
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
     * @param  list<int>  $periodIds
     */
    protected function hasElementUnderReview(SchoolClass $class, int $enrollmentId, array $periodIds): bool
    {
        $instrumentIds = Instrument::query()
            ->where('class_id', $class->id)
            ->whereIn('academic_period_id', $periodIds)
            ->pluck('id');

        return StudentItemScore::query()
            ->where('enrollment_id', $enrollmentId)
            ->whereIn('instrument_id', $instrumentIds)
            ->where('result_state', ResultState::UnderReview->value)
            ->exists();
    }
}
