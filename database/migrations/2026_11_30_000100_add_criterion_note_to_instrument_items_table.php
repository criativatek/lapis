<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The teacher's private note on a question/criterion of a correction grid
     * (how it is scored, what a level requires). Pedagogical metadata, never a
     * mark and never about a student.
     */
    public function up(): void
    {
        Schema::table('instrument_items', function (Blueprint $table) {
            $table->text('criterion_note')->nullable()->after('source_group_label');
        });
    }

    public function down(): void
    {
        Schema::table('instrument_items', function (Blueprint $table) {
            $table->dropColumn('criterion_note');
        });
    }
};
