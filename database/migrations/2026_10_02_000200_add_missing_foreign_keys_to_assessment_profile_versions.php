<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `superseded_by_version_id` and `created_from_version_id` were created
 * without foreign keys (2026_07_18_000500) — the only two `*_id` columns in
 * the whole schema without one. Both are self-references on
 * assessment_profile_versions, so they had to wait for the table to exist
 * before a constraint could point back at it; a fresh migration is how that
 * gets fixed without touching a migration that already ran in production.
 *
 * nullOnDelete(), never cascade or restrict: deleting a newer version must
 * never be blocked by an older version that points at it (restrict), and must
 * never drag that older version's history away with it (cascade). Both
 * columns are already nullable, so orphaning them on delete just means the
 * history stops naming a version that no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Local checked clean (0 orphans both sides), but production is not
        // local — this must not fail there if history drifted. A subquery
        // rather than a multi-table UPDATE JOIN, so it runs on SQLite too; the
        // extra `SELECT * FROM (...) AS existing_ids` wrapper is MySQL's own
        // workaround for "can't specify target table for update in FROM
        // clause" — without it MySQL refuses to read the very table it is
        // updating, even read-only, inside the subquery.
        DB::statement('
            UPDATE assessment_profile_versions
            SET superseded_by_version_id = NULL
            WHERE superseded_by_version_id IS NOT NULL
              AND superseded_by_version_id NOT IN (
                  SELECT id FROM (SELECT id FROM assessment_profile_versions) AS existing_ids
              )
        ');

        DB::statement('
            UPDATE assessment_profile_versions
            SET created_from_version_id = NULL
            WHERE created_from_version_id IS NOT NULL
              AND created_from_version_id NOT IN (
                  SELECT id FROM (SELECT id FROM assessment_profile_versions) AS existing_ids
              )
        ');

        Schema::table('assessment_profile_versions', function (Blueprint $table): void {
            $table->foreign('superseded_by_version_id')
                ->references('id')->on('assessment_profile_versions')
                ->nullOnDelete();

            $table->foreign('created_from_version_id')
                ->references('id')->on('assessment_profile_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_profile_versions', function (Blueprint $table): void {
            $table->dropForeign(['superseded_by_version_id']);
            $table->dropForeign(['created_from_version_id']);
        });
    }
};
