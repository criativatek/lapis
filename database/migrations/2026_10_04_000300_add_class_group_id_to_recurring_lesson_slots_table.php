<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem participa neste tempo do horário: a turma inteira, ou um grupo dela.
 *
 * NULL = TURMA INTEIRA, e é esse o estado de todos os tempos que já existem —
 * nenhum backfill, nenhum professor tem de rever o horário que já configurou
 * (§22 do briefing). A coluna só passa a dizer alguma coisa quando alguém a
 * preenche de propósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_lesson_slots', function (Blueprint $table): void {
            $table->foreignId('class_group_id')->nullable()->after('class_id')
                ->constrained('class_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_lesson_slots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('class_group_id');
        });
    }
};
