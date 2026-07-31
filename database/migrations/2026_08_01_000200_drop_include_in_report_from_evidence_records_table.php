<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-record "include in report" never had a reader — ReportsController has
 * never looked at it. Replaced by a class-level default plus per-student
 * overrides (see the following migration) instead of a per-note decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_records', function (Blueprint $table) {
            $table->dropIndex('evidence_org_report_period_idx');
            $table->dropColumn('include_in_report');
        });
    }

    public function down(): void
    {
        Schema::table('evidence_records', function (Blueprint $table) {
            $table->boolean('include_in_report')->default(false)->after('description');
            $table->index(['organization_id', 'include_in_report', 'academic_period_id'], 'evidence_org_report_period_idx');
        });
    }
};
