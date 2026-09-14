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
    /*
     * ÍNDICES SÓ DA CHAVE, NUNCA `(class_id, chave)`. Em MySQL um índice que
     * começa por `class_id` passa a servir a FK `class_id` quando é o mais
     * adequado, e o `down()` rebentaria com 1553 («needed in a foreign key
     * constraint») — a mesma armadilha que já deixou duas migrações por
     * reverter (0.139.1). As leituras filtram por turma de qualquer forma.
     */
    public function up(): void
    {
        Schema::table('recurring_lesson_slots', function (Blueprint $table): void {
            $table->char('split_lesson_key', 26)->nullable()->after('class_group_id');
            $table->index('split_lesson_key', 'recurring_lesson_slots_split_key_idx');
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->char('lesson_unit_key', 26)->nullable()->after('lesson_number');
            $table->index('lesson_unit_key', 'lessons_unit_key_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex('lessons_unit_key_idx');
            $table->dropColumn('lesson_unit_key');
        });

        Schema::table('recurring_lesson_slots', function (Blueprint $table): void {
            $table->dropIndex('recurring_lesson_slots_split_key_idx');
            $table->dropColumn('split_lesson_key');
        });
    }
};
