<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A VERSÃO DO SUMÁRIO VIVE NA AULA, NÃO NA LINHA DO SUMÁRIO.
 *
 * Uma linha de `lesson_summaries` é apagada e recriada por vários caminhos
 * (limpar sumário, fechar a aula como «professor ausente», deslocar o
 * planeamento para a aula seguinte). Um contador na própria linha voltava a
 * zero cada vez que isso acontecia, e um cartão aberto antes da recriação
 * ainda coincidiria com a versão nova. Na aula o contador só sobe.
 *
 * Quem escreve é o modelo `LessonSummary` (eventos `saved` e `deleted`), na
 * mesma transação da escrita — nenhum caminho precisa de se lembrar de o fazer.
 * As aulas existentes começam em 0: não há versões anteriores a comparar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->unsignedInteger('summary_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('summary_version');
        });
    }
};
