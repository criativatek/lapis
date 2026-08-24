<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reusable sequence of lesson content (Fatia 4) — «7A, 7B, 7C» share the
 * same subject and rhythm, and a teacher should not have to retype the same
 * plan into every one of them.
 *
 * PERSONAL, NOT SHARED. `user_id` is the owning teacher; LessonSequencePolicy
 * is the only place that decides who may see or change a row, exactly as
 * `report_templates` already separates schema (organization_id) from
 * authorship (user_id) — see that migration's own note.
 *
 * SCOPED LIKE A CLASS. subject_id and academic_year_id are required for the
 * same reason a class itself carries them: a sequence is meaningless without
 * a subject, and reuse across years would silently mix different curricula
 * and calendars. grade_level is nullable for the same reason
 * `classes.grade_level` is — higher-education contexts have no meaningful
 * grade level, and a null one matches any class of the right subject rather
 * than refusing every application (see ApplyLessonSequence).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_sequences', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('grade_level', 16)->nullable();
            $table->string('name', 160);
            $table->timestamps();

            $table->index(['organization_id', 'user_id'], 'lesson_sequences_org_user_idx');
            $table->index(['organization_id', 'subject_id', 'academic_year_id'], 'lesson_sequences_org_subject_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sequences');
    }
};
