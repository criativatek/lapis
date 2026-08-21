<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institutional organization closure — same shape as users.closure_requested_at
 * (2026_08_26_000100), on the tenant instead of the account. See
 * docs/account-closure.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('closure_requested_at')->nullable()->after('locale');
            $table->timestamp('scheduled_deletion_at')->nullable()->after('closure_requested_at');

            $table->index('scheduled_deletion_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex(['scheduled_deletion_at']);
            $table->dropColumn(['closure_requested_at', 'scheduled_deletion_at']);
        });
    }
};
