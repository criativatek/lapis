<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an account's closure was actually carried out.
 *
 * IT IS A SEPARATE COLUMN AND NOT A DERIVED READING. «Já foi anonimizada?» has
 * to be answerable without inspecting the shape of a name or an email — a
 * derived check would compare against whatever placeholder the code happens to
 * write today, and would start lying the day that placeholder changes. It is
 * also what makes `retention:execute` idempotent: a second run skips a row that
 * carries this timestamp instead of anonymising already-anonymous data and
 * writing a second audit event for the same closure.
 *
 * DISTINCT FROM `deactivated_at`, which an operator sets and clears to suspend
 * somebody. This one is one-way: nothing in the application clears it, because
 * nothing can put back what it records having removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('scheduled_deletion_at');
            // The executor's own query: closure requested, not yet carried out.
            $table->index(['anonymized_at', 'closure_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['anonymized_at', 'closure_requested_at']);
            $table->dropColumn('anonymized_at');
        });
    }
};
