<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas de pessoa para as métricas de utilização: a autorização para as ver,
 * a oposição à recolha e o instante a partir do qual a recolha voltou a valer
 * para a pessoa (depois de retirar a oposição ou de cancelar o encerramento da
 * conta) — só a partir daí a pessoa volta a ser observada, sem histórico.
 * Nenhuma é mass-assignable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_view_usage_insights')->default(false);
            $table->timestamp('usage_opted_out_at')->nullable();
            $table->timestamp('usage_resumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_view_usage_insights', 'usage_opted_out_at', 'usage_resumed_at']);
        });
    }
};
