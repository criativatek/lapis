<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `assessment_profiles.grade_level`, now that its values live in
 * `assessment_profile_grade_levels` (previous migration), and narrows the
 * profile identity unique index from 5 columns (including grade_level) to 4
 * — a profile's identity no longer includes a single grade level, since it
 * may now cover several.
 *
 * Narrowing that unique index can only ever make it MORE permissive (fewer
 * columns = a stricter constraint), so two profiles that were previously
 * allowed to differ only by grade_level would now collide on
 * (organization, academic_year, subject, name). up() defends against that
 * before touching anything: it groups every profile — soft-deleted included
 * — by the 4 remaining identity columns and aborts loudly if any group has
 * more than one member. Confirmed empty locally; kept here because this
 * migration is not run in production during this change window and must
 * still protect any other database it runs against, including production
 * later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoIdentityCollisions();

        Schema::table('assessment_profiles', function (Blueprint $table) {
            $table->dropUnique('assessment_profiles_identity_unique');
        });

        Schema::table('assessment_profiles', function (Blueprint $table) {
            $table->dropColumn('grade_level');
        });

        Schema::table('assessment_profiles', function (Blueprint $table) {
            $table->unique(['organization_id', 'academic_year_id', 'subject_id', 'name'], 'assessment_profiles_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_profiles', function (Blueprint $table) {
            $table->dropUnique('assessment_profiles_identity_unique');
        });

        Schema::table('assessment_profiles', function (Blueprint $table) {
            $table->string('grade_level', 16)->nullable();
        });

        // Lossy by construction, and deliberately so: a profile that picked up
        // more than one grade level after this migration first ran collapses
        // back to its alphabetically-lowest one. That loss is only possible
        // for profiles created/edited during the window this feature was live
        // — every profile that existed before it (the only ones this
        // migration's up() ever had to protect) had at most one grade level,
        // so for them this restores the exact original value.
        $lowestByProfile = [];
        DB::table('assessment_profile_grade_levels')
            ->select('id', 'assessment_profile_id', 'grade_level')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$lowestByProfile): void {
                foreach ($rows as $row) {
                    $current = $lowestByProfile[$row->assessment_profile_id] ?? null;
                    if ($current === null || strcmp($row->grade_level, $current) < 0) {
                        $lowestByProfile[$row->assessment_profile_id] = $row->grade_level;
                    }
                }
            });

        foreach ($lowestByProfile as $profileId => $gradeLevel) {
            DB::table('assessment_profiles')->where('id', $profileId)->update(['grade_level' => $gradeLevel]);
        }

        Schema::table('assessment_profiles', function (Blueprint $table) {
            $table->unique(['organization_id', 'academic_year_id', 'subject_id', 'grade_level', 'name'], 'assessment_profiles_identity_unique');
        });
    }

    /**
     * Groups every profile (soft-deleted included, via the query builder —
     * see the migration-A docblock on why DB::table is used instead of the
     * Eloquent model) by the 4-column identity the new unique index will
     * enforce, and aborts with the full list of conflicting profiles if any
     * group has more than one row.
     */
    private function assertNoIdentityCollisions(): void
    {
        $groups = [];

        DB::table('assessment_profiles')
            ->select('id', 'organization_id', 'academic_year_id', 'subject_id', 'name')
            ->orderBy('id')
            ->chunkById(500, function ($profiles) use (&$groups): void {
                foreach ($profiles as $profile) {
                    $key = implode('|', [
                        $profile->organization_id,
                        $profile->academic_year_id,
                        $profile->subject_id,
                        $profile->name,
                    ]);
                    $groups[$key][] = $profile->id;
                }
            });

        $conflicts = array_filter($groups, fn (array $ids): bool => count($ids) > 1);

        if ($conflicts === []) {
            return;
        }

        $description = collect($conflicts)
            ->map(fn (array $ids, string $key): string => "[{$key}] => profile ids: ".implode(', ', $ids))
            ->implode('; ');

        throw new RuntimeException(
            'Cannot drop grade_level from assessment_profiles: dropping it from the identity unique index would '.
            'collide for the following (organization_id|academic_year_id|subject_id|name) groups, which currently '.
            "differ only by grade_level: {$description}. Resolve these profiles (rename or merge) before running ".
            'this migration.'
        );
    }
};
