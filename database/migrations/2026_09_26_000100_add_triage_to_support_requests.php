<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Por onde começar, e quem está a tratar disto.
 *
 * SEVERIDADE NÃO É PRIORIDADE, E A DISTINÇÃO NÃO É SEMÂNTICA. A ADR-0011 §13
 * rejeitou níveis e SLA, e com razão: um nível mostrado ao cliente é uma
 * promessa, e não há promessa nenhuma por trás. Isto é outra coisa — uma leitura
 * INTERNA do impacto, que ordena a fila de quem trabalha nela e **nunca é
 * devolvida ao professor**, exactamente como `technical_code` já não é. Se
 * algum dia aparecer num ecrã de utilizador, passou a ser a coisa que a §13
 * recusou.
 *
 * A ATRIBUIÇÃO SOBREVIVE À ANONIMIZAÇÃO. É o registo de um acto do operador, da
 * mesma natureza que `resolved_by`, que também sobrevive. Não diz nada sobre o
 * titular dos dados.
 *
 * O PAR DA ATRIBUIÇÃO NÃO TEM CHECK, E NÃO É ESQUECIMENTO. O MySQL recusa-o com
 * o erro 3823: uma coluna usada numa chave estrangeira **com acção referencial**
 * não pode aparecer num CHECK, e `assigned_to` tem `ON DELETE SET NULL`. Os
 * pares que este domínio já tem — suspensão, aceite — escapam porque nenhum
 * deles refere a coluna da FK dentro do CHECK.
 *
 * As alternativas eram piores: tirar o `nullOnDelete` faria com que apagar a
 * conta de um operador ficasse bloqueada por um pedido antigo que lhe estava
 * atribuído. Fica a invariante escrita numa só porta —
 * `TriageSupportRequest::assign()` grava as duas colunas de uma vez — e um teste
 * a afirmá-la, que é o que a base faria se pudesse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->string('severity', 20)->nullable()->after('technical_code');
            $table->foreignId('assigned_to')->nullable()->after('severity')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');

            // A fila do backoffice ordena-se por isto.
            $table->index(['status', 'severity']);
            $table->index('assigned_to');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE support_requests ADD CONSTRAINT support_requests_severity_check
                 CHECK (severity IS NULL OR severity IN ('blocks_work', 'workaround', 'cosmetic'))"
            );

        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE support_requests DROP CONSTRAINT support_requests_severity_check');
        }

        Schema::table('support_requests', function (Blueprint $table): void {
            // OS INDICES PRIMEIRO, E OS DOIS. Largar uma coluna que ainda tem um
            // indice a apontar-lhe e fatal no SQLite — onde a suite corre — com
            // um erro que fala do indice e nao da coluna, longe da causa.
            $table->dropIndex(['status', 'severity']);
            $table->dropIndex(['assigned_to']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['severity', 'assigned_at']);
        });
    }
};
