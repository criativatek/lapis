<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ligação a um issue aberto num rastreador externo.
 *
 * DUAS COLUNAS E NÃO UMA. O número serve para falar com a API — expurgar o
 * conteúdo ao fim do prazo de conservação exige-o — e o URL serve para um
 * operador clicar. Guardar só o URL obrigaria a extrair o número dele com uma
 * expressão regular sobre uma cadeia que veio de fora.
 *
 * VÃO AMBAS NA ANONIMIZAÇÃO. Ao contrário da severidade e da atribuição, que
 * são actos do operador, isto aponta para um sítio onde ficou conteúdo sobre o
 * pedido — e um apontador para conteúdo é conteúdo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->unsignedInteger('github_issue_number')->nullable()->after('assigned_at');
            $table->string('github_issue_url', 255)->nullable()->after('github_issue_number');
        });
    }

    public function down(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->dropColumn(['github_issue_number', 'github_issue_url']);
        });
    }
};
