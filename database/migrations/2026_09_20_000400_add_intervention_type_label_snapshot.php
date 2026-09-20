<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The designation an intervention's TYPE had when the intervention was
 * recorded.
 *
 * Why a new column rather than reusing `title`. A report groups interventions
 * by type and labels each group, and that label was read live from the enum —
 * so renaming a label rewrote how records made years earlier are described. The
 * obvious fix, "use the stored title", is the one this codebase already tried
 * and reverted: `title` is the teacher's words about ONE intervention, it is
 * written as `strategy_label ?? type->label()` so it is often not a type label
 * at all, and on rows that predate the type column it holds whatever an old
 * import put there — which is how «Legado sem dominio» reached a printed
 * document as if it were a kind of pedagogical action. `title` cannot tell a
 * genuine snapshot from free text. This column only ever holds one.
 *
 * Null is meaningful: "recorded before this column existed". A report falls
 * back to the live enum for those, which is exactly what it did before, so no
 * record changes behaviour by gaining a null.
 */
return new class extends Migration
{
    /**
     * Designations this application has generated for a type and has since
     * replaced.
     *
     * Frozen as a literal, never read from the enum: a migration must produce
     * the same result in five years, and reaching into application code would
     * make its output depend on whatever the enum says the day it runs.
     *
     * One entry today. «Reforço das aprendizagens» became «Antecipação e
     * reforço das aprendizagens» — the designation Decreto-Lei n.º 54/2018
     * uses at artigo 9.º, alínea d). The code never moved.
     *
     * @var array<string, list<string>>
     */
    private const SUPERSEDED_LABELS = [
        'learning_reinforcement' => ['Reforço das aprendizagens'],
    ];

    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            // 300, matching `strategy_label` — the longest thing that can end
            // up in a pedagogical label field. The longest current designation
            // is 76 characters, so this is headroom, not a guess.
            $table->string('intervention_type_label', 300)->nullable()->after('intervention_type');
        });

        $this->backfill();
    }

    /**
     * Recovers a snapshot only where one is PROVABLY already stored.
     *
     * A row whose `title` is exactly a designation this application generated
     * for that row's type is a snapshot of that designation — nothing else
     * writes that string into that column for that type. Anything else is left
     * null rather than guessed, because a wrong snapshot is worse than none:
     * none falls back to the live enum, a wrong one prints a category the
     * record never had.
     *
     * Rows whose title still matches the CURRENT designation need nothing —
     * the live fallback already produces the same string — so only superseded
     * designations are recovered. That keeps this to the rows the rename
     * actually put at risk.
     */
    protected function backfill(): void
    {
        foreach (self::SUPERSEDED_LABELS as $type => $labels) {
            DB::table('interventions')
                ->where('intervention_type', $type)
                ->whereIn('title', $labels)
                ->update(['intervention_type_label' => DB::raw('title')]);
        }
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn('intervention_type_label');
        });
    }
};
