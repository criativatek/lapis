<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O caderno da turma: registos privados de um professor sobre uma turma
 * (spec 2026-10-09-class-notebook-design §2). A turma dá o ano letivo — não se
 * duplica `academic_year_id`. `author_id` é quem escreveu e o único que alguma
 * vez lê o registo.
 *
 * Todas as chaves estrangeiras são RESTRICT, também a de `classes`: com
 * cascata, um colega dono da turma eliminava-a e levava consigo os registos
 * privados de outro professor (ver SchoolClassHistory). `lock_version` guarda
 * o conteúdo (título/registo) contra dois separadores do mesmo professor;
 * fixar não lhe toca. `edited_at` é a última alteração do conteúdo.
 *
 * Sem backfill: não toca em nenhum registo existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_notebook_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 160)->nullable();
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Serve a listagem (turma + autor, fixados primeiro, mais recentes
            // primeiro) e a FK de `class_id`.
            $table->index(['class_id', 'author_id', 'is_pinned', 'created_at'], 'class_notebook_entries_listing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_notebook_entries');
    }
};
