<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duas colunas de pessoa para as métricas de utilização: a autorização para as
 * ver e a oposição à recolha. Nenhuma é mass-assignable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_view_usage_insights')->default(false);
            $table->timestamp('usage_opted_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_view_usage_insights', 'usage_opted_out_at']);
        });
    }
};
