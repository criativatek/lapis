<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns an intervention from a record of WHAT WAS DONE into a record of the
 * teacher's reasoning: why, what, what for, and what was observed afterwards.
 *
 * STRICTLY ADDITIVE. Nothing is dropped, renamed or recategorised. Every column
 * below is nullable, so every pre-existing row stays exactly as valid as it was
 * — an intervention recorded last November has no motive and no objective, and
 * that is shown as an absence rather than filled in with a guess (§4).
 *
 * WHY code + label AND NOT A FOREIGN KEY. The pedagogical library is authored
 * text that gets reworded, and Relatórios already settled this question the same
 * way: what a teacher picked is COPIED at the moment of picking, so rewording
 * «planificação da escrita» next September leaves February's intervention
 * saying what the teacher chose in February. The code records where it came
 * from; the label is what it said (§56, §57).
 *
 * `suspended` joins the status check because «suspensa» and «cancelada» are
 * different pedagogical statements — one is paused and may resume, the other was
 * abandoned. The existing value stays and keeps its meaning; nothing is
 * relabelled underneath a row that already has it (§21, §45).
 *
 * `needs_reformulation` joins the effectiveness check for the same reason: it is
 * a judgement none of the four existing values expresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            // PORQUÊ — the situation the teacher identified. A code when it came
            // from the library, and the words either way.
            $table->string('motive_code', 64)->nullable()->after('intervention_type');
            $table->string('motive_label', 300)->nullable()->after('motive_code');

            // O QUÊ — the strategy, as the teacher would name it. Distinct from
            // `intervention_type`, which is the catalogue entry that carries the
            // legal framing: one says what this counts as, the other says what
            // was actually done.
            $table->string('strategy_code', 64)->nullable()->after('motive_label');
            $table->string('strategy_label', 300)->nullable()->after('strategy_code');

            // PARA QUÊ — free text on purpose. A library objective may be
            // suggested and then edited, and what is kept is what was applied.
            $table->text('objective')->nullable()->after('strategy_label');

            // «Rever em». The teacher's own date, and the only thing that makes
            // an intervention pending — no rule invents one from elapsed time
            // (§22, §37, §38).
            $table->date('review_on')->nullable()->after('expected_end_on');

            $table->index(['organization_id', 'status', 'review_on'], 'interventions_org_status_review_idx');
        });

        $this->replaceCheck(
            'interventions',
            'interventions_status_check',
            "status IN ('new','in_progress','concluded','cancelled','suspended')",
        );

        $this->replaceCheck(
            'intervention_reviews',
            'intervention_reviews_effectiveness_check',
            "effectiveness IS NULL OR effectiveness IN ('not_effective','partially_effective','effective','inconclusive','needs_reformulation')",
        );
    }

    public function down(): void
    {
        // Anything using the values this migration introduced has no
        // representation in the old check, and narrowing it underneath them
        // would leave the table describing rows it forbids.
        $suspended = DB::table('interventions')->where('status', 'suspended')->count();
        $reformulation = DB::table('intervention_reviews')->where('effectiveness', 'needs_reformulation')->count();

        if ($suspended > 0 || $reformulation > 0) {
            throw new RuntimeException(
                "Rollback abortado: {$suspended} intervenção(ões) suspensa(s) e {$reformulation} apreciação(ões) ".
                'com «necessita de reformulação» não cabem nos estados anteriores. Converta-as antes de reverter.'
            );
        }

        $this->replaceCheck(
            'intervention_reviews',
            'intervention_reviews_effectiveness_check',
            "effectiveness IS NULL OR effectiveness IN ('not_effective','partially_effective','effective','inconclusive')",
        );

        $this->replaceCheck(
            'interventions',
            'interventions_status_check',
            "status IN ('new','in_progress','concluded','cancelled')",
        );

        Schema::table('interventions', function (Blueprint $table) {
            $table->dropIndex('interventions_org_status_review_idx');
            $table->dropColumn([
                'motive_code', 'motive_label', 'strategy_code', 'strategy_label', 'objective', 'review_on',
            ]);
        });
    }

    /**
     * MySQL only, exactly as the two migrations before this one: SQLite has no
     * ALTER for a check constraint, and the test suite runs on it. The
     * application's own enums are what actually keep the values honest; the
     * constraint is the second line, where the database supports one.
     */
    protected function replaceCheck(string $table, string $name, string $expression): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }
};
