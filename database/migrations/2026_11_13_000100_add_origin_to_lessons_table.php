<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DISTINGUE UMA AULA GERADA PELO HORÁRIO DE UMA AULA INSERIDA À MÃO —
 * distinção que a coluna `recurring_lesson_slot_id`, sozinha, deixou de poder
 * dar depois de se confirmar que uma corrida em
 * `LessonScheduleController::destroy()` podia deixá-la NULL numa aula que o
 * horário tinha mesmo produzido (a FK é `nullOnDelete`).
 *
 * `schedule`: nasceu de um tempo do horário — MaterializeLessonsForRange,
 * InsertLessonIntoSequence (desloca dentro da mesma rotina) ou uma importação
 * cujo `recurring_lesson_slot_ulid` resolveu para um slot real.
 * `manual`: nunca teve um tempo do horário a produzi-la — hoje, só uma
 * importação sem `recurring_lesson_slot_ulid`.
 *
 * BACKFILL: `recurring_lesson_slot_id` NÃO NULO → `schedule`, sem ambiguidade
 * — só a materialização e a importação ligada a um slot preenchem essa
 * coluna. `recurring_lesson_slot_id` NULO → `manual`, e não `schedule`: é a
 * escolha que NUNCA arrisca apagar dados. Uma aula NULA existente pode ser
 * genuinamente manual (importação sem slot) OU pode já ser uma órfã do
 * próprio defeito que esta migração ajuda a fechar — e não há, com o que
 * ficou gravado, forma de distinguir as duas com segurança. Rotulá-la
 * `manual` é o lado seguro: nunca mais é tocada pela reconciliação (Passo
 * 2.3 só remove órfãs `schedule`), o pior caso é uma órfã antiga continuar
 * visível como já estava — nunca uma aula manual apagada por engano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('origin', 16)->default('schedule')->after('recurring_lesson_slot_id');
        });

        DB::table('lessons')->whereNotNull('recurring_lesson_slot_id')->update(['origin' => 'schedule']);
        DB::table('lessons')->whereNull('recurring_lesson_slot_id')->update(['origin' => 'manual']);

        $this->addCheck('lessons', 'lessons_origin_check', "origin IN ('schedule','manual')");
    }

    public function down(): void
    {
        $this->dropCheck('lessons', 'lessons_origin_check');

        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    private function dropCheck(string $table, string $name): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }
    }
};
