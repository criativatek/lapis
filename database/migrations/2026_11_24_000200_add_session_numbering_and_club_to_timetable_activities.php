<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração opcional das sessões e o tipo «Clube» (0.170.0).
 *
 * `numbering_enabled` (por omissão falso) e `numbering_start` (por omissão 1)
 * são da atividade: a numeração das sessões é independente da das aulas e
 * nunca reutiliza a sequência de uma turma. Nenhuma atividade existente passa a
 * numerar.
 *
 * A CHECK de `kind` alarga-se para aceitar `club`. O `down()` volta à CHECK
 * antiga e falha se já houver atividades `club` — é essa a razão de o
 * `lapis:activity-sessions-recovery-check` existir: nunca `migrate:rollback`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_activities', function (Blueprint $table): void {
            $table->boolean('numbering_enabled')->default(false)->after('ends_on');
            $table->unsignedInteger('numbering_start')->default(1)->after('numbering_enabled');
        });

        $this->addCheck('timetable_activities', 'timetable_activities_numbering_start_check', 'numbering_start >= 1');

        $this->dropCheck('timetable_activities', 'timetable_activities_kind_check');
        $this->addCheck(
            'timetable_activities',
            'timetable_activities_kind_check',
            "kind IN ('class_direction','student_support','meeting','coordination','club','other')",
        );
    }

    public function down(): void
    {
        $this->dropCheck('timetable_activities', 'timetable_activities_kind_check');
        $this->addCheck(
            'timetable_activities',
            'timetable_activities_kind_check',
            "kind IN ('class_direction','student_support','meeting','coordination','other')",
        );

        $this->dropCheck('timetable_activities', 'timetable_activities_numbering_start_check');

        Schema::table('timetable_activities', function (Blueprint $table): void {
            $table->dropColumn(['numbering_enabled', 'numbering_start']);
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
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $clause = $driver === 'mariadb' ? 'DROP CONSTRAINT' : 'DROP CHECK';

        try {
            DB::statement("ALTER TABLE {$table} {$clause} {$name}");
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), $name)) {
                throw $exception;
            }
        }
    }
};
