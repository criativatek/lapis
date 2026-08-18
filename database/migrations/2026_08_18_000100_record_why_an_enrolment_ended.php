<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An enrolment that ended says WHY, beside saying that it did.
 *
 * `status` already answered «is this student on this roll», which is what the
 * screens filter by, and it stays exactly as it was. What it could not carry is
 * the difference between the four ways a Portuguese roll ends one — transferred
 * out, moved class, enrolment cancelled, excluded for absences — and an
 * importer that collapsed them was losing information the source file had.
 *
 * A NULLABLE STRING, matching how `status` itself is stored: varchar in the
 * database, meaning in a PHP enum. No database-level enum, so adding a fifth
 * reason later is a code change and not a migration on a live table.
 *
 * Not indexed: nothing filters by reason, and everything that filters by
 * status already has its index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->string('status_reason', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropColumn('status_reason');
        });
    }
};
