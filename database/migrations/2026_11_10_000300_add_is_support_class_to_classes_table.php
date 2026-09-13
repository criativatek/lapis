<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Turma de apoio» — uma turma que pode reunir alunos de várias turmas.
 *
 * UMA COLUNA, NÃO UMA TABELA. Uma turma de apoio é uma SchoolClass como
 * qualquer outra: tem aulas, horário, sumários e inscrições próprias. A única
 * coisa que muda é que o professor pode inscrever nela um aluno que já existe
 * (SupportClassStudentController), em vez de o criar de novo.
 *
 * SEM TURMA-BASE. Não há `base_class_id` nem nada parecido: os alunos podem
 * vir de quantas turmas o professor lecionar.
 *
 * Default false, sem backfill: todas as turmas existentes continuam normais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table): void {
            $table->boolean('is_support_class')->default(false)->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table): void {
            $table->dropColumn('is_support_class');
        });
    }
};
