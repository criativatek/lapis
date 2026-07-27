<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform administrators — the SaaS operator (criativatek), not a teacher. A
 * platform admin sees the backoffice (§ all organizations) and operates outside
 * any single tenant. Deliberately NOT mass-assignable (never in User's #[Fillable]).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};
