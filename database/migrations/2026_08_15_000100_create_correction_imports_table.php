<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One session of importing a correction grid exported from another platform.
 *
 * This table holds the CONVERSATION, not the assessment. Once an import is
 * confirmed, the academic data lives where it always lives — instruments,
 * groups, items, allocations, scores — and this row is only the record of where
 * it came from. Nothing here is ever read to produce a classification.
 *
 * Two columns deserve explaining:
 *
 *  - `stored_path` is a random name on the private disk. `original_filename` is
 *    kept as metadata because a teacher recognises their own file by it, but it
 *    never decides anything and never becomes a path.
 *  - `canonical_snapshot` is the parsed grid. It exists so the wizard can be
 *    re-opened without re-reading the upload, and so the upload can be deleted
 *    as soon as the import ends — which is the point. The file is transient;
 *    the structured record of what it said is what survives, and it survives
 *    without student names in it beyond what the mapping needed.
 *
 * `file_sha256` is indexed rather than unique: re-importing the same export is a
 * legitimate thing to do (a correction redone, a class re-marked), so a repeat
 * is worth mentioning to the teacher and never worth refusing on its own (§25).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correction_imports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // The table is `classes`; the model is SchoolClass because `Class`
            // is a reserved word in PHP. Naming it after the model here would
            // point the foreign key at a table that does not exist.
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();

            // Set only once the teacher chooses (or the import creates) one.
            $table->foreignId('instrument_id')->nullable()->constrained('instruments')->nullOnDelete();

            $table->string('source', 40);
            $table->string('status', 30)->default('uploaded');

            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();

            $table->json('source_metadata')->nullable();
            $table->json('canonical_snapshot')->nullable();
            $table->json('mapping_snapshot')->nullable();
            $table->json('summary')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            // The wizard's own listing: this organization's imports for this
            // class, newest first.
            $table->index(['organization_id', 'class_id', 'status']);
            // "Has this file been here before?" — a question, not a refusal.
            $table->index(['organization_id', 'file_sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_imports');
    }
};
