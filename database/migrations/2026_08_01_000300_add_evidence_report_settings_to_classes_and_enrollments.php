<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a class's Evidence records ("Registos") are included in its
 * report — a report-level setting, not a per-note one (see the previous
 * migration). classes.include_evidence_in_report is the class-wide default;
 * enrollments.include_evidence_in_report is a per-student override, NULL
 * meaning "inherit the class default" — never a magic boolean standing in
 * for "not set".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->boolean('include_evidence_in_report')->default(false)->after('assessment_profile_version_id');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->boolean('include_evidence_in_report')->nullable()->after('is_late_entry');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('include_evidence_in_report');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('include_evidence_in_report');
        });
    }
};
