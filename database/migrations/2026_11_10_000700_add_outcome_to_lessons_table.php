<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «O que aconteceu realmente nesta aula» (0.146.0) — separado de `status`.
 *
 * `status` continua a dizer em que ponto está a PREPARAÇÃO (por preparar,
 * preparada, lecionada). `outcome` diz como a ocorrência FECHOU:
 *  - NULL: ainda não fechada;
 *  - `taught`: lecionada;
 *  - `teacher_absent`: o professor esteve ausente — não numera, não conta;
 *  - `class_external_activity`: a turma esteve noutra atividade letiva —
 *    numera e conta para o serviço docente, mas não como desenvolvimento
 *    efetivo da disciplina.
 *
 * `outcome_reason` é só uma CATEGORIA (training, official_duty, other) e só
 * existe numa ausência do professor: nunca texto livre sobre a pessoa.
 * `outcome_note` é a descrição curta, opcional, da atividade da turma.
 *
 * Backfill: toda a aula já `taught` fecha como `taught`. Nada mais é inferido.
 *
 * Sem CHECK constraints (MySQL 1553/rollback, 0.139.1) — os valores são
 * garantidos pelos enums e pela validação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('outcome', 32)->nullable()->after('status');
            $table->string('outcome_reason', 32)->nullable()->after('outcome');
            $table->string('outcome_note', 160)->nullable()->after('outcome_reason');
            $table->timestamp('outcome_recorded_at')->nullable()->after('outcome_note');
            $table->foreignId('outcome_recorded_by')->nullable()->after('outcome_recorded_at')->constrained('users')->nullOnDelete();
        });

        DB::table('lessons')->where('status', 'taught')->update(['outcome' => 'taught']);
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('outcome_recorded_by');
            $table->dropColumn(['outcome', 'outcome_reason', 'outcome_note', 'outcome_recorded_at']);
        });
    }
};
