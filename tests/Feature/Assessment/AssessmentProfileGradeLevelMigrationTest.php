<?php

namespace Tests\Feature\Assessment;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the two migrations that moved `assessment_profiles.grade_level`
 * (a single free-text string) into the normalized
 * `assessment_profile_grade_levels` table (multi-grade profiles).
 *
 * Both migrations are rolled back and re-applied here against rows written
 * directly with the query builder (never through the AssessmentProfile
 * Eloquent model, which the migrations themselves cannot use either — see
 * their docblocks: it carries the tenant global scope, which a migration
 * runs outside of). Every test restores the schema to HEAD in a `finally`
 * block, the same discipline `RollsBackPlanVersions`/`PlanVersionBackfillTest`
 * use for the same reason: leaving the suite on a rolled-back schema would
 * corrupt every test that runs after this file.
 */
class AssessmentProfileGradeLevelMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** The first of the two migrations under test. Both come off together. */
    private const FIRST_MIGRATION = '2026_09_23_000100_create_assessment_profile_grade_levels_table';

    #[Test]
    public function backfill_gives_a_single_grade_profile_exactly_one_row_and_a_gradeless_one_zero(): void
    {
        [$organizationId, $academicYearId, $subjectId] = $this->context();

        $this->rollBackBothMigrations();

        try {
            $withGrade = $this->insertLegacyProfile($organizationId, $academicYearId, $subjectId, 'Com ano', '7.º');
            $withoutGrade = $this->insertLegacyProfile($organizationId, $academicYearId, $subjectId, 'Sem ano', null);
            $softDeletedWithGrade = $this->insertLegacyProfile($organizationId, $academicYearId, $subjectId, 'Eliminado com ano', '9.º', deleted: true);

            $this->artisan('migrate')->run();

            $this->assertSame(
                ['7.º'],
                DB::table('assessment_profile_grade_levels')->where('assessment_profile_id', $withGrade)->pluck('grade_level')->all(),
            );
            $this->assertSame(
                [],
                DB::table('assessment_profile_grade_levels')->where('assessment_profile_id', $withoutGrade)->pluck('grade_level')->all(),
            );
            $this->assertSame(
                ['9.º'],
                DB::table('assessment_profile_grade_levels')->where('assessment_profile_id', $softDeletedWithGrade)->pluck('grade_level')->all(),
                'A soft-deleted profile must not lose its grade level in the backfill.',
            );
            $this->assertFalse(Schema::hasColumn('assessment_profiles', 'grade_level'));
        } finally {
            $this->artisan('migrate')->run();
        }
    }

    #[Test]
    public function the_migration_refuses_to_run_when_dropping_grade_level_would_collide(): void
    {
        [$organizationId, $academicYearId, $subjectId] = $this->context();

        $this->rollBackBothMigrations();

        try {
            $this->insertLegacyProfile($organizationId, $academicYearId, $subjectId, 'Perfil Único', '7.º');

            // Two different grade levels for the same (org, year, subject, name)
            // were allowed under the OLD 5-column unique index — the exact shape
            // migration B's guard exists to catch before it ever drops the column.
            $conflictingId = $this->insertLegacyProfile($organizationId, $academicYearId, $subjectId, 'Perfil Único', '8.º');

            $caught = null;
            try {
                $this->artisan('migrate')->run();
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            $this->assertNotNull($caught, 'The migration must refuse to run when it would collide.');
            $this->assertStringContainsString('Cannot drop grade_level from assessment_profiles', $caught->getMessage());
            $this->assertTrue(Schema::hasColumn('assessment_profiles', 'grade_level'), 'The guard must fire before any structural change.');

            // Resolve the collision the guard caught, so the retry below (which
            // exercises the same migration this test just proved refuses to run
            // over a real conflict) succeeds and leaves the suite on a clean HEAD.
            DB::table('assessment_profiles')->where('id', $conflictingId)->delete();
        } finally {
            $this->artisan('migrate')->run();
        }
    }

    #[Test]
    public function rolling_back_the_column_drop_restores_the_single_grade_level_exactly(): void
    {
        [$organizationId, $academicYearId, $subjectId] = $this->context();

        // At HEAD: build a profile the normal way (one grade level).
        $profileId = DB::table('assessment_profiles')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'academic_year_id' => $academicYearId,
            'subject_id' => $subjectId,
            'name' => 'Perfil Restaurável',
            'is_institutional_template' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assessment_profile_grade_levels')->insert([
            'assessment_profile_id' => $profileId,
            'grade_level' => '7.º',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            // Roll back only migration B (the column drop) — one step.
            $this->artisan('migrate:rollback', ['--step' => 1])->run();

            $this->assertTrue(Schema::hasColumn('assessment_profiles', 'grade_level'));
            $this->assertSame(
                '7.º',
                DB::table('assessment_profiles')->where('id', $profileId)->value('grade_level'),
                'A profile that only ever had one grade level must come back with exactly that value.',
            );
        } finally {
            $this->artisan('migrate')->run();
        }
    }

    /** @return array{int, int, int} organization_id, academic_year_id, subject_id */
    private function context(): array
    {
        $user = User::factory()->create();
        $organizationId = Organization::factory()->create(['owner_id' => $user->id])->id;

        $academicYearId = DB::table('academic_years')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'label' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-31',
            'status' => 'draft',
            'country_code' => 'PT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subjectId = DB::table('subjects')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'name' => 'Português',
            'code' => 'POR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organizationId, $academicYearId, $subjectId];
    }

    private function insertLegacyProfile(int $organizationId, int $academicYearId, int $subjectId, string $name, ?string $gradeLevel, bool $deleted = false): int
    {
        return DB::table('assessment_profiles')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'academic_year_id' => $academicYearId,
            'subject_id' => $subjectId,
            'grade_level' => $gradeLevel,
            'name' => $name,
            'is_institutional_template' => false,
            'deleted_at' => $deleted ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rollBackBothMigrations(): void
    {
        $steps = DB::table('migrations')->where('migration', '>=', self::FIRST_MIGRATION)->count();

        $this->artisan('migrate:rollback', ['--step' => $steps])->run();

        $this->assertFalse(Schema::hasTable('assessment_profile_grade_levels'), 'the rollback must remove the detail table');
        $this->assertTrue(Schema::hasColumn('assessment_profiles', 'grade_level'), 'the rollback must restore the legacy column');
    }
}
