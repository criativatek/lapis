<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The type-specific fields the "Registos" redesign needs: which per-kind
 * detail was recorded (homework status, participation level, activity
 * evaluation), and whether an activity is a candidate source for a future
 * report — a per-record flag, distinct from classes.include_evidence_in_report
 * / enrollments.include_evidence_in_report (whether the whole logbook shows up
 * in a report at all). All four are nullable: only the kind they belong to
 * ever sets them, every other kind and every pre-existing row leaves them null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_records', function (Blueprint $table) {
            $table->string('homework_status', 20)->nullable()->after('kind');
            $table->string('participation_level', 20)->nullable()->after('homework_status');
            $table->string('activity_evaluation', 20)->nullable()->after('participation_level');
            $table->boolean('activity_include_in_report')->nullable()->after('activity_evaluation');
        });

        $this->addCheck('evidence_records', 'evidence_homework_status_check', "homework_status IN ('done','partially_done','not_done')");
        $this->addCheck('evidence_records', 'evidence_participation_level_check', "participation_level IN ('positive','adequate','reduced')");
        $this->addCheck('evidence_records', 'evidence_activity_evaluation_check', "activity_evaluation IN ('very_positive','positive','satisfactory','not_very_positive')");
    }

    public function down(): void
    {
        $this->dropCheck('evidence_records', 'evidence_activity_evaluation_check');
        $this->dropCheck('evidence_records', 'evidence_participation_level_check');
        $this->dropCheck('evidence_records', 'evidence_homework_status_check');

        Schema::table('evidence_records', function (Blueprint $table) {
            $table->dropColumn(['homework_status', 'participation_level', 'activity_evaluation', 'activity_include_in_report']);
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
