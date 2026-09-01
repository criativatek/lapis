<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A profile may now cover more than one grade level (multi-grade classes,
 * mixed-year subjects). This normalizes the previously single, free-text
 * `assessment_profiles.grade_level` column into a one-to-many detail table:
 * one row per (profile, grade level) pair, still a free-text string — there
 * is no canonical enum of grade levels anywhere in the project, so none is
 * invented here.
 *
 * This migration only creates the table and backfills it from the existing
 * column. The column itself is dropped in the next migration, once the
 * backfill is known to be complete and safe.
 *
 * Reads/writes go through the query builder (DB::table), not the
 * AssessmentProfile Eloquent model: that model carries the tenant
 * (BelongsToOrganization) global scope, which throws outside a resolved
 * tenant — exactly the context a migration runs in. DB::table also has no
 * notion of soft-deletes to begin with, so it already reads every row
 * regardless of `deleted_at` — the query-builder equivalent of withTrashed().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_profile_grade_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_profile_id')->constrained()->cascadeOnDelete();
            $table->string('grade_level', 16);
            $table->timestamps();

            $table->unique(['assessment_profile_id', 'grade_level'], 'apgl_profile_grade_level_unique');
            $table->index('grade_level', 'apgl_grade_level_index');
        });

        // Backfill: one row per existing profile that had a grade_level set,
        // soft-deleted profiles included (see class docblock).
        DB::table('assessment_profiles')
            ->select('id', 'grade_level')
            ->whereNotNull('grade_level')
            ->orderBy('id')
            ->chunkById(500, function ($profiles): void {
                $now = now();
                $rows = $profiles->map(fn ($profile): array => [
                    'assessment_profile_id' => $profile->id,
                    'grade_level' => $profile->grade_level,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('assessment_profile_grade_levels')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_profile_grade_levels');
    }
};
