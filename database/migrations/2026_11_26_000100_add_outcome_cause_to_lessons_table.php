<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Aula prevista e não realizada» (`outcome = not_held`) — aditiva.
 *
 * `outcome` já é `string(32)` e passa a poder valer `not_held`; nada muda nessa
 * coluna. Acrescenta:
 *  - `outcome_cause`: a causa estruturada (strike, school_not_operating,
 *    weather_closure, facilities_problem, organizational, other);
 *  - `outcome_teacher_situation`: a situação individual do professor perante a
 *    greve (not_adhered, not_informed), só com `strike`;
 *  - `outcome_cause_note`: descrição curta de «other», obrigatória nesse caso.
 *
 * Os três são dados reservados a quem registou o resultado (LessonOutcomeDetails).
 * Sem CHECK (MySQL 1553 / rollback, 0.139.1): os valores são garantidos pelos
 * enums, pela ação e pela validação do backup.
 *
 * ROLLBACK: o código antigo rebenta a turma inteira ao ler `outcome = not_held`
 * (o cast do enum lança). `lapis:lesson-outcome-recovery-check` tem de sair
 * com 0 antes de voltar o código atrás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('outcome_cause', 32)->nullable()->after('outcome_note');
            $table->string('outcome_teacher_situation', 32)->nullable()->after('outcome_cause');
            $table->string('outcome_cause_note', 160)->nullable()->after('outcome_teacher_situation');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn(['outcome_cause', 'outcome_teacher_situation', 'outcome_cause_note']);
        });
    }
};
