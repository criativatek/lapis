<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a teacher declared an instrument's correction finished.
 *
 * The status alone says THAT it is done; these say when and by whom — the same
 * pair the cancellation flow already keeps (cancelled_at/cancelled_by), for the
 * same reason.
 *
 * Deliberately NOT backfilled: an instrument sitting at 6/6 has every cell
 * resolved, but nobody has declared the correction over. Completing is the
 * teacher's decision (§3.3), and inventing a completion date for instruments
 * they never closed would be exactly the kind of assumption this project
 * refuses to make. Reopening clears both columns, so they always describe the
 * completion currently in force.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->dateTime('completed_at')->nullable()->after('allow_bonus');
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn('completed_at');
        });
    }
};
