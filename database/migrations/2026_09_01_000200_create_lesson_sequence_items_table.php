<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ordered steps of a lesson sequence (Fatia 4) — each one a reusable
 * template of Sumario text, applied once into an independent LessonSummary
 * by ApplyLessonSequence (§ copy-on-use, not a live link, mirroring
 * `report_templates`/`ReportTemplate::snapshot()`).
 *
 * `summary` is required — it mirrors `lesson_summaries.content`, which is
 * NOT NULL for the same reason: a Sumario, even a template of one, is never
 * blank. The other three columns mirror `lesson_summaries`' own optional
 * columns and stay nullable for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_sequence_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_sequence_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('summary');
            $table->text('private_notes')->nullable();
            $table->text('resources')->nullable();
            $table->text('homework')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'lesson_sequence_id', 'position'], 'lesson_sequence_items_org_seq_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sequence_items');
    }
};
