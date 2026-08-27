<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One session of restoring a Lapispro-generated backup (Fatia 6). Mirrors
 * `correction_imports` (2026_08_15_000100) deliberately — same shape, same
 * reasoning: `stored_path` is a random name on the private disk,
 * `original_filename` is metadata only and never becomes a path,
 * `canonical_snapshot` is the parsed-and-validated backup (only the fields
 * this importer recognises — never a raw passthrough of the upload, so
 * anything unexpected in the file is dropped here, not carried forward) so
 * the preview can be re-rendered without re-reading the upload.
 *
 * `file_sha256` is indexed, not unique, for the same reason as correction
 * imports: re-importing the same backup is legitimate (confirming nothing
 * new appears, recovering after an interrupted session) and never worth
 * refusing on its own — idempotency is enforced per-record at execution
 * time, not by rejecting a repeat file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default('uploaded');

            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // Read from the backup itself, not asserted by the uploader.
            $table->unsignedSmallInteger('source_schema_version')->nullable();
            $table->string('source_app_version', 32)->nullable();
            $table->dateTime('source_generated_at')->nullable();
            $table->json('source_organization')->nullable();

            $table->json('canonical_snapshot')->nullable();
            $table->json('summary')->nullable();
            $table->text('failure_reason')->nullable();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();

            $table->dateTime('expires_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'file_sha256']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
    }
};
