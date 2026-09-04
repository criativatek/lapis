<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_sheet_exports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->string('scope', 16);
            $table->foreignId('interim_assessment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('adapter', 32)->default('inovar');
            $table->string('moment_label', 200);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->string('file_disk', 32)->default('local');
            $table->string('file_path', 255);
            $table->char('file_checksum', 64);
            $table->string('original_extension', 8);
            $table->boolean('exported_with_warnings')->default(false);
            $table->unsignedSmallInteger('warning_count')->default(0);
            $table->foreignId('exported_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('exported_at');

            $table->index(
                ['organization_id', 'class_id', 'academic_period_id', 'exported_at'],
                'evaluation_sheet_exports_lookup_idx',
            );
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE evaluation_sheet_exports ADD CONSTRAINT evaluation_sheet_exports_scope_check CHECK (scope IN ('period', 'accumulated'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_sheet_exports');
    }
};
