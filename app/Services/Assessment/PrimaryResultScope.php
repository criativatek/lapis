<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;

/**
 * Which figure IS the result, at each period of a year.
 *
 * IN CONTINUOUS ASSESSMENT THE ANSWER MOVES. At the first moment that counts,
 * the period's own Média Ponderada is the whole story — there is nothing behind
 * it to accumulate. From the second onwards the accumulated figure is what the
 * year has produced so far, and it is what the teacher will classify against;
 * the period's own figure becomes a supplementary reading answering «e só neste
 * período, como esteve?».
 *
 * READ FROM THE PROFILE VERSION, never from the word «semestre». Continuity is
 * `contributes_to_accumulated` on the version's own periods — the same flag
 * ClassResultsCalculator builds the accumulated scope from — so a school whose
 * second period stands alone gets `period` for it, and one with three
 * cumulative terms gets `accumulated` for the last two. Nothing here knows how
 * many periods a year has.
 *
 * IT LIVES IN ITS OWN CLASS because two readers need it and they must never
 * drift: Estatística asks it which figure to open on, and the interim
 * comparison asks it which pair of figures to compare. A second copy of this
 * rule would be a second answer to the same question.
 *
 * IT CHOOSES, IT DOES NOT COMPUTE. Both figures were decided by the calculator
 * long before this is called; all that happens here is picking which of the two
 * already-stored numbers answers. That is what makes it safe to apply to a
 * snapshot: nothing academic is recalculated, and a photograph keeps the values
 * it recorded (§4).
 */
class PrimaryResultScope
{
    /**
     * Every period of this class's year, and which figure answers for it.
     *
     * ONE QUERY for the periods and one for the configuration, whatever the
     * size of the class.
     *
     * @param  list<array<string, mixed>>|null  $periods  in sequence, when the caller already has them
     * @return array<int, 'period'|'accumulated'>
     */
    public function forClass(SchoolClass $class, ?array $periods = null): array
    {
        $ordered = $periods ?? AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (AcademicPeriod $period): array => ['id' => (int) $period->id])
            ->all();

        $contributes = $class->profileVersion?->periods()
            ->pluck('contributes_to_accumulated', 'academic_period_id');

        $scopes = [];
        $earlierContributors = 0;

        foreach ($ordered as $period) {
            $id = (int) $period['id'];
            // Absent configuration means continuity, which is the same default
            // the calculator uses when it builds the accumulated scope.
            $counts = (bool) ($contributes?->get($id) ?? true);

            $scopes[$id] = $this->scopeFor($counts, $earlierContributors);

            if ($counts) {
                $earlierContributors++;
            }
        }

        return $scopes;
    }

    /** The reading that answers for one period. */
    public function forPeriod(SchoolClass $class, AcademicPeriod $period): string
    {
        return $this->forClass($class)[(int) $period->id] ?? 'period';
    }

    /**
     * A period answers for itself when it does not feed the continuous line, or
     * when nothing yet does.
     *
     * @return 'period'|'accumulated'
     */
    protected function scopeFor(bool $contributes, int $earlierContributors): string
    {
        return ! $contributes || $earlierContributors === 0 ? 'period' : 'accumulated';
    }
}
