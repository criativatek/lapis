<?php

namespace App\Services\Assessment;

use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\ResultState;
use App\Models\StudentItemScore;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Answers one question, in one place: "is there any applicable cell still
 * unresolved on this instrument?"
 *
 * The Avaliações page and the "Concluir correção" action must never be able to
 * disagree about that — a teacher shown 6/6 has to be allowed to close the
 * correction, and a teacher shown 4/5 has to be refused. So the rule lives here
 * and both call it, rather than each carrying its own copy that could drift.
 *
 * Two things this rule already gets right, and which are the reason not to
 * write a second one:
 *
 *  - APPLICABLE, not "every student in the class". A student who joined after
 *    applied_on, or who left before it, was never meant to have a result here
 *    and must not block the correction (§11.4, A3).
 *  - RESOLVED, not "has a mark". An absence, a dispensation, a not-applicable
 *    or an annulled cell are decisions the teacher already took — they resolve
 *    the cell without carrying a value. Only `pending` (nobody decided) and
 *    `under_review` (contested) leave it open. An empty cell is never a zero.
 */
class InstrumentCompleteness
{
    /**
     * The cell states that count as "the teacher has dealt with this".
     *
     * @var list<ResultState>
     */
    public const RESOLVED_STATES = [
        ResultState::Assessed,
        ResultState::Absent,
        ResultState::AbsentJustified,
        ResultState::Exempt,
        ResultState::NotApplicable,
        ResultState::Annulled,
    ];

    /**
     * @return array{applicable: int<0, max>, completed: int<0, max>, under_review: int<0, max>, complete: bool}
     */
    public function for(Instrument $instrument): array
    {
        return $this->forMany(new EloquentCollection([$instrument]))
            ->get($instrument->id, ['applicable' => 0, 'completed' => 0, 'under_review' => 0, 'complete' => false]);
    }

    /**
     * How many applicable students are still missing a decision — what the
     * refusal message counts.
     */
    public function pendingCount(Instrument $instrument): int
    {
        $progress = $this->for($instrument);

        return max(0, $progress['applicable'] - $progress['completed']);
    }

    /**
     * WHICH applicable students are still missing a decision.
     *
     * The count alone told a teacher that one of thirty rows was unresolved and
     * left them to find it. Naming them is the difference between a refusal
     * they can act on and one they have to investigate — and it costs one query
     * on a page that is already showing every one of those students.
     *
     * Same rule as the count, deliberately: both walk `unresolvedItems()`, so a
     * student who appears in this list is exactly a student the count includes.
     *
     * @return list<int> enrollment ids, in class-number order
     */
    public function pendingEnrollmentIds(Instrument $instrument): array
    {
        $instrument->loadMissing('items:id,instrument_id');

        $itemIds = $instrument->items->pluck('id');

        if ($itemIds->isEmpty()) {
            return [];
        }

        $scores = StudentItemScore::query()
            ->where('instrument_id', $instrument->getKey())
            ->get(['instrument_item_id', 'enrollment_id', 'result_state'])
            ->keyBy(fn (StudentItemScore $score): string => $score->enrollment_id.':'.$score->instrument_item_id);

        $enrollments = Enrollment::query()
            ->where('class_id', $instrument->class_id)
            ->orderBy('class_number')
            ->get(['id', 'class_id', 'class_number', 'enrolled_on', 'left_on']);

        $pending = [];

        foreach ($enrollments as $enrollment) {
            if (! self::isApplicable($enrollment, $instrument->applied_on)) {
                continue;
            }

            if ($this->unresolvedItems($enrollment, $itemIds, $scores) > 0) {
                $pending[] = (int) $enrollment->getKey();
            }
        }

        return $pending;
    }

    /**
     * How many of this student's cells nobody has decided about yet.
     *
     * A missing row IS «por avaliar» — the grid stores no blank, so absence and
     * `pending` are the same fact and are treated as one here.
     *
     * @param  Collection<int, int|string>  $itemIds
     * @param  Collection<string, StudentItemScore>  $scores
     */
    protected function unresolvedItems(Enrollment $enrollment, Collection $itemIds, Collection $scores): int
    {
        $unresolved = 0;

        foreach ($itemIds as $itemId) {
            $state = $scores->get($enrollment->getKey().':'.$itemId)?->result_state;

            if (! in_array($state, self::RESOLVED_STATES, true)) {
                $unresolved++;
            }
        }

        return $unresolved;
    }

    /**
     * Batched on purpose: the Avaliações page asks about every instrument of a
     * class at once, and doing that one query at a time would be an N+1.
     *
     * @param  EloquentCollection<int, Instrument>  $instruments
     * @return Collection<int, array{applicable: int<0, max>, completed: int<0, max>, under_review: int<0, max>, complete: bool}>
     */
    public function forMany(EloquentCollection $instruments): Collection
    {
        if ($instruments->isEmpty()) {
            return collect();
        }

        $instruments->loadMissing('items:id,instrument_id');

        $enrollmentsByClass = Enrollment::query()
            ->whereIn('class_id', $instruments->pluck('class_id')->unique())
            ->get(['id', 'class_id', 'enrolled_on', 'left_on'])
            ->groupBy('class_id');

        $scoresByInstrument = StudentItemScore::query()
            ->whereIn('instrument_id', $instruments->pluck('id'))
            ->get(['instrument_id', 'instrument_item_id', 'enrollment_id', 'result_state'])
            ->groupBy('instrument_id');

        return $instruments->mapWithKeys(function (Instrument $instrument) use ($enrollmentsByClass, $scoresByInstrument) {
            $applicableEnrollments = $enrollmentsByClass->get($instrument->class_id, collect())
                ->filter(fn (Enrollment $enrollment) => self::isApplicable($enrollment, $instrument->applied_on));

            $itemIds = $instrument->items->pluck('id');
            $applicable = $applicableEnrollments->count();

            // No questions, or nobody it applies to: there is nothing to
            // complete, and "complete" is false rather than vacuously true.
            if ($itemIds->isEmpty() || $applicable === 0) {
                return [$instrument->id => ['applicable' => $applicable, 'completed' => 0, 'under_review' => 0, 'complete' => false]];
            }

            $scores = $scoresByInstrument->get($instrument->id, collect())
                ->keyBy(fn (StudentItemScore $score) => $score->enrollment_id.':'.$score->instrument_item_id);

            $completed = 0;
            $underReview = 0;

            foreach ($applicableEnrollments as $enrollment) {
                $hasUnderReview = false;

                foreach ($itemIds as $itemId) {
                    if ($scores->get($enrollment->id.':'.$itemId)?->result_state === ResultState::UnderReview) {
                        $hasUnderReview = true;

                        break;
                    }
                }

                if ($hasUnderReview) {
                    $underReview++;
                } elseif ($this->unresolvedItems($enrollment, $itemIds, $scores) === 0) {
                    // Same predicate the naming path uses, so the count and the
                    // list of names can never disagree about one student.
                    $completed++;
                }
            }

            return [$instrument->id => [
                'applicable' => $applicable,
                'completed' => $completed,
                'under_review' => $underReview,
                'complete' => $completed === $applicable,
            ]];
        });
    }

    /**
     * Whether this instrument was ever meant to produce a result for this
     * student: enrolled by the day it was applied, and not already gone.
     */
    public static function isApplicable(Enrollment $enrollment, CarbonInterface $appliedOn): bool
    {
        return $enrollment->enrolled_on->lessThanOrEqualTo($appliedOn)
            && ($enrollment->left_on === null || $enrollment->left_on->greaterThanOrEqualTo($appliedOn));
    }
}
