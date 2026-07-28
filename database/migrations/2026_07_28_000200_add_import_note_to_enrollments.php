<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text carried over from a roster import (Repetente/ASE/PLNM, as they
 * appear in the source file). Never read by any calculation or rule — display
 * only. NEE is deliberately never written here (docs/domain-model.md §11.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('import_note', 255)->nullable()->after('late_entry_note');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('import_note');
        });
    }
};
