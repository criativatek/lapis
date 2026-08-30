<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando um professor fechou o aviso de que a Política de Privacidade mudou.
 *
 * O MESMO PADRÃO DE `onboarding_dismissed_at`, e de propósito: um carimbo
 * nullable, que o próprio utilizador limpa ao fechar o aviso, sem tabela nova
 * e sem estado que precise de ser reconciliado.
 *
 * NÃO É UMA ACEITAÇÃO. `terms_version`/`terms_accepted_at` existem ao lado e
 * registam uma coisa diferente — que a pessoa aceitou uma versão dos Termos. A
 * Política de Privacidade **não se aceita**: informa. Guardar aqui um
 * «aceitou» daria a entender um consentimento que não é o fundamento de
 * tratamento nenhum descrito no documento, e que a pessoa não poderia retirar
 * sem deixar de poder usar o produto.
 *
 * GUARDA A DATA E NÃO A VERSÃO, também de propósito: o aviso é mostrado
 * enquanto a data de entrada em vigor da Política for posterior a este
 * carimbo. Uma actualização futura volta a mostrá-lo sozinha, sem ninguém ter
 * de se lembrar de limpar uma coluna — e sem comparar strings de versão que
 * teriam de concordar em dois sítios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('privacy_notice_dismissed_at')->nullable()->after('onboarding_dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('privacy_notice_dismissed_at');
        });
    }
};
