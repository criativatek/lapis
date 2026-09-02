<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O contexto técnico de um reporte feito de dentro da aplicação.
 *
 * UMA COLUNA, E NÃO UMA TABELA. Há exactamente uma linha destas por pedido, é
 * lida com ele e apaga-se com ele. Uma tabela 1:1 seria mais uma coisa que
 * `AnonymiseSupportRequest` teria de se lembrar de limpar — e o que essa acção
 * esquece não é um erro que se veja, é uma promessa de eliminação que deixa de
 * ser verdade em silêncio.
 *
 * NULLABLE COMO TUDO O QUE IDENTIFICA. Segue a regra que a migração original já
 * fixou para `technical_route` e `technical_reference`: passados 24 meses sobre
 * `resolved_at` isto vai a NULL de verdade, sem marcas de substituição
 * (ADR-0011 §8).
 *
 * SEM CHECK CONSTRAINT, ao contrário das colunas de vocabulário fechado deste
 * domínio. O MySQL valida que é JSON; o que lá pode estar DENTRO é uma lista
 * fechada declarada na FormRequest, porque é aí que ela é legível e testável.
 * A mesma divisão que a migração de suporte documenta para o SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->json('client_context')->nullable()->after('app_version');
        });
    }

    public function down(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->dropColumn('client_context');
        });
    }
};
