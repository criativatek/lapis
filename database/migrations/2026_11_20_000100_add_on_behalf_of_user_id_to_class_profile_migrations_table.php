<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Em acesso técnico (ADR-0014), quem confirma uma migração de perfil é o
 * administrador de suporte, em nome do professor. `confirmed_by` passa a dizer
 * quem de facto confirmou; esta coluna diz em nome de quem — o professor
 * representado. Fica NULL quando o próprio professor confirma, e nas linhas já
 * existentes, que não se reatribuem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_profile_migrations', function (Blueprint $table) {
            $table->foreignId('on_behalf_of_user_id')->nullable()->after('confirmed_by')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('class_profile_migrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('on_behalf_of_user_id');
        });
    }
};
