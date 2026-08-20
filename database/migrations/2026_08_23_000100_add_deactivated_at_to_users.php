<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a person may still get in.
 *
 * Deliberately on the USER and not on the organization: suspending a
 * subscription already answers "does this account still have a product", and it
 * answers it for everyone in the organization at once. This answers a different
 * question — "may this person sign in" — which is the only one that still makes
 * sense the day an organization has twenty members and one of them leaves.
 *
 * A nullable timestamp rather than a boolean, because the question support
 * actually asks is *when*, and a boolean throws that away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->index()->after('is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deactivated_at']);
            $table->dropColumn('deactivated_at');
        });
    }
};
