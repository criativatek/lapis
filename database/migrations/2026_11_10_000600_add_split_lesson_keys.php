<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Estes tempos correspondem à mesma lição» — o vínculo explícito entre T1 e T2
 * (0.145.2).
 *
 * `recurring_lesson_slots.split_lesson_key`: os tempos de grupos da MESMA turma
 * com a mesma chave são realizações da mesma lição curricular. NULL = sem
 * vínculo (e é sempre NULL num tempo da turma inteira).
 *
 * `lessons.lesson_unit_key`: a identidade da lição a que a aula pertence,
 * gravada UMA vez quando a aula é ligada e nunca recalculada. É isto que torna
 * o emparelhamento estável: a T2 que acontece uma semana depois por causa de um
 * feriado continua ligada à T1 da lição dela, e reabrir a semana não mexe nisso.
 *
 * Sem backfill aqui: a inferência para dados existentes só escreve quando não
 * há ambiguidade, e vive em `lapis:renumber-lessons`, onde se inspeciona antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_lesson_slots', function (Blueprint $table): void {
            $table->char('split_lesson_key', 26)->nullable()->after('class_group_id');
            $table->index(['class_id', 'split_lesson_key'], 'recurring_lesson_slots_split_key_idx');
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->char('lesson_unit_key', 26)->nullable()->after('lesson_number');
            $table->index(['class_id', 'lesson_unit_key'], 'lessons_class_unit_key_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex('lessons_class_unit_key_idx');
            $table->dropColumn('lesson_unit_key');
        });

        Schema::table('recurring_lesson_slots', function (Blueprint $table): void {
            $table->dropIndex('recurring_lesson_slots_split_key_idx');
            $table->dropColumn('split_lesson_key');
        });
    }
};
