<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The severity grade (G2–G6) of a disciplinary occurrence — required whenever
 * kind = 'incident' ("Ocorrência disciplinar"), never set otherwise. G1
 * ("Comportamento meritório") is its own EvidenceKind, not a severity here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_records', function (Blueprint $table) {
            $table->string('disciplinary_severity', 16)->nullable()->after('kind');
        });

        $this->addCheck(
            'evidence_records',
            'evidence_disciplinary_severity_check',
            "disciplinary_severity IN ('g2','g3','g4','g5','g6')",
        );
    }

    public function down(): void
    {
        $this->dropCheck('evidence_records', 'evidence_disciplinary_severity_check');

        Schema::table('evidence_records', function (Blueprint $table) {
            $table->dropColumn('disciplinary_severity');
        });
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    protected function dropCheck(string $table, string $name): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }
    }
};
