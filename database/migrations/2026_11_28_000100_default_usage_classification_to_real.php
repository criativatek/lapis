<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Uma conta conta como «Utilização real» até prova em contrário.
 *
 * Até aqui toda a organização nascia `unclassified` e o segmento «Utilização
 * real» do painel ficava a zeros até alguém classificar à mão. A decisão de
 * 2026-11-03 (docs/usage-insights-decisions.md) muda o valor de ORIGEM: em
 * produção só há contas pessoais de professores, e as de QA e de teste marcam-se
 * como `test` à mão. «Por classificar» continua a existir, mas como estado
 * EXPLÍCITO («um operador olhou e ainda não decidiu»). Continua a não haver
 * inferência: nada é derivado de `is_test_account`, do nome, do email, do plano
 * ou da atividade.
 *
 * 1. O DEFAULT da coluna passa a `real`. Em MySQL/MariaDB é só metadados
 *    (instantâneo; o CHECK e o índice não se tocam). Em SQLite (só testes) não
 *    se toca na coluna: `change()` reconstrói a tabela e rebenta com as chaves
 *    estrangeiras; o valor de origem vem do modelo.
 * 2. BACKFILL ÚNICO E EXPLÍCITO: as organizações `unclassified` com
 *    `usage_classified_at` NULL, isto é, que nenhuma pessoa decidiu alguma vez,
 *    passam a `real`. Em produção são todas as organizações existentes à data
 *    da migration (nenhuma foi classificada). Uma organização que um operador
 *    pôs em «Por classificar» tem `usage_classified_at` e fica como está. O
 *    backfill NÃO escreve `usage_classified_at`: NULL continua a querer dizer
 *    «por defeito, ninguém decidiu».
 *
 * `down()` repõe o DEFAULT a `unclassified` mas NÃO desfaz o backfill: depois
 * dele já não há como distinguir uma conta que foi `unclassified` por origem de
 * uma que nasceu `real`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setDefault('real');
        $this->backfill();
    }

    public function down(): void
    {
        $this->setDefault('unclassified');
    }

    /** Público para o teste poder provar o backfill sem voltar a correr o `change()` do SQLite. */
    public function backfill(): void
    {
        DB::table('organizations')
            ->where('usage_classification', 'unclassified')
            ->whereNull('usage_classified_at')
            ->update(['usage_classification' => 'real']);
    }

    private function setDefault(string $value): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE organizations ALTER COLUMN usage_classification SET DEFAULT '{$value}'");
        }

        // Em SQLite não se toca na coluna: `change()` reconstrói a tabela com um
        // `drop table organizations`, que com as chaves estrangeiras ligadas
        // rebenta (e o mesmo aconteceria em qualquer rollback de testes que
        // atravesse esta migration). SQLite só serve os testes; o valor de origem
        // aí vem de `Organization::$attributes`, e o DEFAULT da coluna prova-se
        // em MySQL, que é o da CI e o de produção.
    }
};
