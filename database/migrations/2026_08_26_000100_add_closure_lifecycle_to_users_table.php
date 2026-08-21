<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voluntary, recoverable account closure — distinct from `deactivated_at`
 * (administrative, immediate, reversible only by an operator). Requesting
 * closure never deletes anything; it stamps a deadline that ClosureRetention
 * already knows how to read (docs/account-closure.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('closure_requested_at')->nullable()->after('deactivated_at');
            $table->timestamp('scheduled_deletion_at')->nullable()->after('closure_requested_at');

            $table->index('scheduled_deletion_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['scheduled_deletion_at']);
            $table->dropColumn(['closure_requested_at', 'scheduled_deletion_at']);
        });
    }
};
