<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers the status an instrument had right before it was cancelled, so
 * "Reverter anulação" can restore it exactly rather than guessing (§ instrument
 * editing design, cancellation section). NULL whenever the instrument is not
 * currently cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->string('status_before_cancellation', 16)->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->dropColumn('status_before_cancellation');
        });
    }
};
