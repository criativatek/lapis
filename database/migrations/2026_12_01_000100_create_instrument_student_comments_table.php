<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O comentário privado do professor sobre UM aluno num elemento de avaliação
 * («Apanhado a copiar na Q2», «Não teve tempo para a Q6»). Uma linha por par
 * (elemento, inscrição): ancorado na inscrição e nunca no aluno, como tudo o
 * que se escreve sobre uma criança numa turma.
 *
 * Todas as chaves estrangeiras de história são RESTRICT, como em
 * `enrollment_instrument_applicability` (a outra tabela por elemento × inscrição):
 * é texto que o professor escreveu sobre uma criança e não desaparece em
 * silêncio por trás de um botão «Remover». `EnrollmentHistory` conhece a
 * tabela e a aplicação recusa antes de a base de dados o fazer.
 *
 * `lock_version` dá ao comentário o seu próprio controlo de concorrência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrument_student_comments', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // instrument_id à esquerda: serve também a FK do elemento no MySQL 8
            // (uma FK precisa de um índice que comece por ela). A inscrição tem o
            // seu, para a FK e para «os comentários deste aluno».
            $table->unique(['instrument_id', 'enrollment_id'], 'isc_instrument_enrollment_unique');
            $table->index('enrollment_id', 'isc_enrollment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_student_comments');
    }
};
