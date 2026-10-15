<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma resposta de escala deixa de ter de apontar para um nível.
 *
 * A autoavaliação herda a escala da versão do perfil da turma, e nem todas as
 * escalas têm níveis: uma «0 a 20», uma percentagem ou uma escala própria da
 * organização são intervalos, sem nível para `scale_level_id` apontar. O que o
 * aluno escreve nessas escalas é um número, guardado aqui.
 *
 * DECIMAL e nunca float (§24.4): é um valor que será lido ao lado de uma
 * classificação. Nullable: vazio não é zero — uma pergunta por responder fica
 * sem valor, não com um 0. Aditiva e reversível; as respostas já guardadas
 * (todas por nível) ficam exatamente como estavam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('self_assessment_responses', function (Blueprint $table) {
            $table->decimal('numeric_value', 6, 3)->nullable()->after('scale_level_id');
        });
    }

    public function down(): void
    {
        Schema::table('self_assessment_responses', function (Blueprint $table) {
            $table->dropColumn('numeric_value');
        });
    }
};
