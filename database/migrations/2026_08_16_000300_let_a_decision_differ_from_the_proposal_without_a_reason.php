<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE BLOCKER, and the only reason this task touches a migration.
 *
 * The A10 invariant was written into the database as:
 *
 *     (final_value IS NULL AND final_scale_level_id IS NULL)
 *     OR override_reason IS NOT NULL
 *     OR (final_value <=> proposed_value AND final_scale_level_id <=> proposed_scale_level_id)
 *
 * It says two things at once, and both are now wrong.
 *
 * FIRST, it requires a reason whenever the decision differs from the proposal.
 * That is a product decision that has since been reversed: a teacher assigning a
 * level other than the proposed one is doing their job, not filing an exception.
 * The reason stays available — `override_reason` is untouched, and every
 * confirmation that carries one still stores it — it simply stops being a
 * precondition. What made the change auditable is unaffected: the original
 * proposal is still never overwritten, and `overridden_by`/`overridden_at` are
 * still stamped whenever the decision and the proposal disagree.
 *
 * SECOND, it requires `final_value` to equal `proposed_value` when there is no
 * reason — and those two columns do not hold the same kind of thing. The engine
 * fills `proposed_value` with the rounded NORMALIZED PERCENTAGE (66 for a result
 * of 66%), while the decision a teacher makes on a 1–5 scale is a LEVEL. Writing
 * the percentage into the decision is what put «66,0» in a column headed with the
 * grade, beside a proposal reading «3». Keeping the two in lockstep is keeping a
 * percentage and a level equal, which they never are.
 *
 * What replaces it is the invariant that survived: a classification that is no
 * longer merely proposed carries a decision. Nothing here rewrites a single
 * stored row — the decisions already taken stay exactly as they were recorded.
 */
return new class extends Migration
{
    protected const OLD = 'classifications_override_reason_check';

    protected const NEW = 'classifications_decided_has_decision_check';

    public function up(): void
    {
        $this->drop(self::OLD);

        // A confirmed or published classification says what was decided — as a
        // level of the scale, as a value on it, or both when the scale's levels
        // carry numbers.
        $this->add(
            self::NEW,
            "status IN ('proposed','superseded') "
            .'OR final_value IS NOT NULL '
            .'OR final_scale_level_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        $this->drop(self::NEW);

        $this->add(
            self::OLD,
            '(final_value IS NULL AND final_scale_level_id IS NULL) '
            .'OR override_reason IS NOT NULL '
            .'OR (final_value <=> proposed_value AND final_scale_level_id <=> proposed_scale_level_id)',
        );
    }

    protected function add(string $name, string $expression): void
    {
        if ($this->onMySql()) {
            DB::statement("ALTER TABLE classifications ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    protected function drop(string $name): void
    {
        if ($this->onMySql()) {
            DB::statement("ALTER TABLE classifications DROP CHECK {$name}");
        }
    }

    protected function onMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
