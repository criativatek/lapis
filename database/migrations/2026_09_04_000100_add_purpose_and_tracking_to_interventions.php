<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acompanhamento do Aluno (§8): finalidade, frequência e indicador de
 * acompanhamento. None of this was on the intervention before — checked the
 * model and the two prior migrations and confirmed absent.
 *
 * STRICTLY ADDITIVE, exactly like 2026_08_22_000100. Every column is
 * nullable, so every existing row stays exactly as valid as it was: an
 * intervention recorded before this migration has no finalidade, and that is
 * shown as «não especificada» — never defaulted to one of the three, and
 * never backfilled with a guess (§8).
 *
 * FREQUENCY AND TRACKING INDICATOR ARE FREE TEXT, following the same
 * precedent as `motive_label` / `strategy_label`: this application does not
 * force a rigid structure onto "2x por semana" or "leitura em voz alta,
 * registo semanal" when a short sentence already says it. A code+label pair
 * would only make sense once a library of frequencies existed to pick from,
 * and none does.
 *
 * `purpose` IS A NATIVE ENUM COLUMN, following the convention already set by
 * `target_type` / `status` / `domain_relation` on this same table: the PHP
 * enum is the single source of truth for the set of values, and MySQL's own
 * CHECK is the second line where the database engine supports one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('purpose', 32)->nullable()->after('intervention_type');
            $table->string('frequency', 200)->nullable()->after('review_on');
            $table->string('tracking_indicator', 300)->nullable()->after('frequency');
        });

        $this->replaceCheck(
            'interventions',
            'interventions_purpose_check',
            "purpose IS NULL OR purpose IN ('recovery','consolidation','improvement')",
        );
    }

    public function down(): void
    {
        $this->dropCheck('interventions', 'interventions_purpose_check');

        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'frequency', 'tracking_indicator']);
        });
    }

    /**
     * MySQL only, exactly as the migrations before this one: SQLite has no
     * ALTER for a check constraint, and the test suite runs on it. The
     * application's own enum is what actually keeps the values honest; the
     * constraint is the second line, where the database engine supports one.
     */
    protected function replaceCheck(string $table, string $name, string $expression): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    protected function dropCheck(string $table, string $name): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
    }
};
