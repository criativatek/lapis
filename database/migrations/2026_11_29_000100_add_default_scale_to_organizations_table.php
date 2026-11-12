<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A ESCALA DA ORGANIZAÇÃO — definida uma vez, usada pelos perfis novos.
 *
 * Uma escola tem uma escala só (os seus níveis, cortes e códigos do Inovar), e
 * pedir ao professor que a refaça em cada perfil era pedir-lhe o mesmo trabalho
 * uma e outra vez. A organização passa a apontar para a sua escala por defeito;
 * um perfil NOVO abre com ela.
 *
 * É SÓ UM APONTADOR. Não muda nenhuma versão de perfil existente — uma versão
 * ativa é congelada e «sem migração automática» mantém-se. Os perfis antigos
 * escolhem a escala da organização na lista, quando o professor quiser.
 *
 * `NULL` = nada escolhido, e a «Escala 1 a 5» do sistema continua a ser o ponto
 * de partida, como sempre. `ON DELETE SET NULL`: apagar a escala nunca apaga a
 * organização, só desfaz o apontador. Aditiva, sem backfill, reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if ($this->isMysql()) {
                $table->foreignId('default_scale_id')->nullable()->after('jurisdiction')
                    ->constrained('scales')->nullOnDelete();

                return;
            }

            // SQLite (testes locais): sem a restrição. Largar uma coluna com FK
            // obriga o SQLite a reconstruir `organizations`, e isso falha com
            // linhas em tabelas que a referenciam — o que impediria os testes de
            // rollback de migrations. A leitura do apontador confirma sempre que a
            // escala existe, por isso o comportamento é o mesmo.
            $table->unsignedBigInteger('default_scale_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if ($this->isMysql()) {
                $table->dropForeign(['default_scale_id']);

                // Em MySQL o `dropForeign` deixa o índice com o mesmo nome: sem o
                // largar, um `migrate` seguinte falha com «Duplicate key name».
                if (Schema::hasIndex('organizations', 'organizations_default_scale_id_foreign')) {
                    $table->dropIndex('organizations_default_scale_id_foreign');
                }
            }

            $table->dropColumn('default_scale_id');
        });
    }

    private function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
