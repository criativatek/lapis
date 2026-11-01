<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O HISTÓRICO DAS CORREÇÕES do resultado de uma aula. Corrigir não apaga: a
 * linha de `lessons` fica com o valor novo e esta tabela guarda, por correção,
 * quem corrigiu, quando, o valor anterior e o novo (resultado, motivo, nota,
 * causa, especificação, descrição) e o motivo da correção.
 *
 * Os valores anteriores e novos podem conter dado reservado ao titular (a
 * especificação perante a greve, o motivo da ausência): por isso o histórico só
 * se lê pela mesma regra que o dado original (LessonOutcomeDetails::mayRead) e
 * NUNCA viaja para a auditoria geral.
 *
 * Aditiva e sem backfill: nenhuma aula existente passa a ter correções.
 * As chaves seguem a aula (cascata): eliminar uma aula leva o seu histórico, e
 * só as abertas se eliminam. `rectified_by` fica a nulo se a conta desaparecer.
 *
 * ROLLBACK de código: o código anterior ignora a tabela; não há leitura que
 * rebente. O estado atual da aula está sempre em `lessons`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_outcome_rectifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('rectified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rectified_at');
            // `outcome` quando o resultado mudou; `details` quando só mudou a causa, o motivo ou a descrição.
            $table->string('kind', 16);
            $table->json('previous');
            $table->json('current');
            $table->string('motive', 200);
            $table->timestamps();

            $table->index(['lesson_id', 'rectified_at'], 'lesson_outcome_rectifications_lesson_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_outcome_rectifications');
    }
};
