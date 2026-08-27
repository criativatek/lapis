<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Que versão dos Termos esta conta aceitou, e quando.
 *
 * PORQUE É QUE ISTO PRECISA DE FICAR GRAVADO. Os Termos dizem que o professor
 * é o responsável pelo tratamento dos dados dos seus alunos e que o Lapispro é
 * subcontratante — e o Acordo de Tratamento de Dados, que fixa essa relação,
 * é aceite por remissão dos Termos. Uma repartição de responsabilidades que
 * ninguém consegue demonstrar ter sido aceite não é uma repartição: é uma
 * página no sítio.
 *
 * DUAS COLUNAS E MAIS NADA. Não se guarda endereço IP nem identificador de
 * navegador. Guardá-los seria recolher dados de tráfego para provar uma
 * aceitação — mais dados pessoais, com base legal mais frágil, para uma
 * finalidade que estas duas colunas já cumprem. O que a lei pede é a
 * demonstração de que foi aceite, não a reconstituição forense de onde a
 * pessoa estava sentada.
 *
 * A VERSÃO É A DATA DE ENTRADA EM VIGOR, e não um número à parte — ver
 * `LegalDocuments::termsVersion()`. Fica como `string` e não como `date`
 * porque é um identificador de versão que por acaso tem forma de data: se
 * algum dia a versão passar a ser `2027-01-15-b`, a coluna aguenta.
 *
 * NULO É UM ESTADO LEGÍTIMO, e é o das contas que já existiam antes desta
 * coluna. Não se preenche retroativamente uma aceitação que ninguém deu: uma
 * data inventada aqui é exatamente o género de facto falso que estas colunas
 * existem para evitar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('terms_version', 32)->nullable()->after('anonymized_at');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_version', 'terms_accepted_at']);
        });
    }
};
