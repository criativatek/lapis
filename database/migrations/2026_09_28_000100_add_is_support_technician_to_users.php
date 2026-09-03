<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support technicians are explicitly authorised to enter a teacher's account
 * for technical assistance. Deliberately NOT mass-assignable (never in User's
 * #[Fillable]); the capability is managed only through internal administration
 * paths, independently from ordinary account updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_support_technician')->default(false)->after('is_platform_admin');
        });

        DB::table('users')
            ->where('is_platform_admin', true)
            ->update(['is_support_technician' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_support_technician');
        });
    }
};
