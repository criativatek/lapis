<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Avaliação intercalar» — the state of a class on a date, kept.
 *
 * NOT AN ACADEMIC PERIOD. A period is an official division of the year with its
 * own weights, contributions and closing rules; an interim assessment is a
 * photograph taken inside one. Modelling it as a period would double the
 * academic structure and put a thing that has no weights where weights are
 * expected.
 *
 * SHAPED LIKE calculation_snapshots, deliberately. That table already answers
 * exactly this problem — an immutable frozen document, literal value copies
 * rather than live foreign keys, a hash to detect tampering, scalars lifted out
 * only where they are genuinely searched, and no `updated_at` because a
 * snapshot is never updated. Inventing a second shape for the same idea would
 * make the two drift.
 *
 * WHY JSON RATHER THAN TABLES. This document is written once and read whole:
 * to show a moment again, to compare it with the end of its period, and to fill
 * an INOVAR grid from it. Nothing ever filters on a value inside it or joins it
 * against live data. Normalising it would cost four tables that are only ever
 * read together, and — worse — every future change to what a snapshot holds
 * would need a migration ACROSS HISTORICAL ROWS, which is precisely what must
 * never happen to history. `snapshot_version` handles that instead: old
 * documents keep their old shape and are read by the reader that understands
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interim_assessments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            // The period it was taken INSIDE. The photograph belongs to a
            // period; it is not one.
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            // The teacher's own words. Suggested, never imposed.
            $table->string('name', 160);
            // The academic date the state was read at — not when it was saved.
            $table->date('reference_date');
            $table->text('note')->nullable();

            // Which reader understands this document. Starts at 1 and only ever
            // grows; a version-2 reader still has to read version 1.
            $table->unsignedSmallInteger('snapshot_version')->default(1);
            $table->json('snapshot');
            // SHA-256 of the payload, as calculation_snapshots does: it makes a
            // silent rewrite detectable rather than merely forbidden.
            $table->char('snapshot_hash', 64);

            $table->dateTime('created_at'); // No updated_at: a snapshot is never updated.

            $table->index(['organization_id', 'class_id', 'reference_date'], 'interim_org_class_date_idx');
            $table->index(['class_id', 'academic_period_id']);
        });

        // Several per period are expected and legitimate (§20), so nothing is
        // unique here beyond the ulid. What IS worth refusing is a document
        // that claims a version nobody can read.
        $this->addCheck('interim_assessments', 'interim_snapshot_version_check', 'snapshot_version >= 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('interim_assessments');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
