<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número da lição indicado pelo professor (0.169.0).
 *
 * Até aqui `lessons.lesson_number` era TOTALMENTE derivado da cronologia da
 * turma, a começar em 1. Quem começa a usar o Lapispro a meio do ano — a primeira
 * aula registada é a Lição 11 — ou quem precisa de acertar a sequência não tinha
 * como o dizer. Estas duas colunas acrescentam o que faltava, sem mudar o que
 * existe:
 *
 *  - `lesson_number_end`: NULL = a aula corresponde a UMA lição. Preenchido = a
 *    aula corresponde às lições `lesson_number`..`lesson_number_end` (um bloco de
 *    duas horas que deu duas lições). Sempre maior do que `lesson_number`; um fim
 *    igual ao início normaliza-se para NULL em quem escreve;
 *  - `lesson_number_manual`: TRUE = o número (e o fim) foram indicados pelo
 *    professor e nunca são recalculados.
 *
 * Sem backfill: as linhas existentes ficam NULL/false e a numeração comporta-se
 * exatamente como antes. Sem CHECK constraints (convenção da tabela `lessons`:
 * ver 2026_11_10_000700_add_outcome_to_lessons_table) — o intervalo é garantido
 * por quem escreve e pela validação. Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->unsignedInteger('lesson_number_end')->nullable()->after('lesson_number');
            $table->boolean('lesson_number_manual')->default(false)->after('lesson_number_end');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn(['lesson_number_end', 'lesson_number_manual']);
        });
    }
};
