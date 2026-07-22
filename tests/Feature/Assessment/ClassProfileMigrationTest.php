<?php

namespace Tests\Feature\Assessment;

use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\MigrateClassProfile;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Assessment\ProfileMigrationException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The auditable profile migration (§10.2, A4): a class only changes profile
 * version through a recorded, previewed migration once it has decisions — and the
 * history it already produced is never recalculated silently.
 */
class ClassProfileMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Activates a second version of the class's profile with different weights, so
     * the same raw marks yield a different result under it.
     */
    private function activateSecondVersion(SchoolClass $class, User $teacher): AssessmentProfileVersion
    {
        $profile = $class->profileVersion->profile;

        app(ProfileBuilder::class)->update(
            $profile,
            [
                'subject_id' => $profile->subject_id,
                'name' => $profile->name,
                'description' => $profile->description,
                'grade_level' => $profile->grade_level,
            ],
            $class->profileVersion->scale_id,
            [
                ['name' => 'Oralidade', 'weight' => 10],
                ['name' => 'Leitura', 'weight' => 40],
                ['name' => 'Escrita', 'weight' => 30],
                ['name' => 'Gramática', 'weight' => 10],
                ['name' => 'Educação Literária', 'weight' => 10],
            ],
        );

        return app(ActivateProfileVersion::class)->activate($profile->refresh()->draftVersion(), $teacher);
    }

    private function classificationFor($period, string $studentName, ClassificationScope $scope = ClassificationScope::Period): ?Classification
    {
        return Classification::query()
            ->where('academic_period_id', $period->id)
            ->where('scope', $scope)
            ->get()
            ->first(fn (Classification $classification) => $classification->enrollment->student->identity->display_name === $studentName);
    }

    #[Test]
    public function migrating_records_the_change_and_refreshes_open_proposals_but_not_history(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $version1 = $class->profileVersion;

            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher);

            $version2 = $this->activateSecondVersion($class->refresh(), $teacher);
            // The class still lags on v1 — activation does not migrate it (§10.2).
            $this->assertSame($version1->id, $class->refresh()->assessment_profile_version_id);

            $migration = app(MigrateClassProfile::class)->migrate($class->refresh(), $version2, 'Nova ponderação aprovada em conselho.', $teacher);

            // The class moves and the migration is recorded with its preview.
            $this->assertSame($version2->id, $class->refresh()->assessment_profile_version_id);
            $this->assertSame('Nova ponderação aprovada em conselho.', $migration->reason);
            $this->assertGreaterThan(0, $migration->affected_enrollment_count);
            $this->assertArrayHasKey('rows', $migration->impact_preview);
            $this->assertDatabaseHas('class_profile_migrations', ['to_version_id' => $version2->id, 'from_version_id' => $version1->id]);

            // The confirmed decision is history: still confirmed, still on v1.
            $carolina->refresh();
            $this->assertSame(ClassificationStatus::Confirmed, $carolina->status);
            $this->assertSame($version1->id, $carolina->assessment_profile_version_id);

            // An open proposal is refreshed onto the new version.
            $eva = $this->classificationFor($period, 'Eva Salgado');
            $this->assertSame(ClassificationStatus::Proposed, $eva->status);
            $this->assertSame($version2->id, $eva->assessment_profile_version_id);
        });
    }

    #[Test]
    public function migrating_to_the_same_version_is_refused(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            $this->expectException(ProfileMigrationException::class);
            app(MigrateClassProfile::class)->migrate($class, $class->profileVersion, 'Sem mudança.', $teacher);
        });
    }

    #[Test]
    public function a_class_with_decisions_is_routed_to_migration_instead_of_swapping(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        [$classUlid, $versionId, $versionUlid] = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period); // creates decisions

            $version2 = $this->activateSecondVersion($class->refresh(), $teacher);

            return [$class->ulid, $version2->id, $version2->ulid];
        });

        // With decisions in place, the bare swap is refused: the request is sent to
        // the auditable migration preview instead.
        $this->actingAs($teacher)
            ->put("/classes/{$classUlid}/profile", ['assessment_profile_version_id' => $versionId])
            ->assertRedirect("/classes/{$classUlid}/profile-migration?to={$versionUlid}");
    }

    #[Test]
    public function confirming_a_migration_over_http_moves_the_class_to_the_new_version(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        [$classUlid, $versionUlid, $versionId] = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $version2 = $this->activateSecondVersion($class->refresh(), $teacher);

            return [$class->ulid, $version2->ulid, $version2->id];
        });

        // The form posts the version's ULID, not its primary key — a regression
        // guard: resolving it as a key would coerce the string and hit the wrong row.
        $this->actingAs($teacher)
            ->post("/classes/{$classUlid}/profile-migration", ['to_version' => $versionUlid, 'reason' => 'Ponderação revista.'])
            ->assertRedirect("/classes/{$classUlid}");

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid, $versionId): void {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();
            $this->assertSame($versionId, $class->assessment_profile_version_id);
            $this->assertDatabaseHas('class_profile_migrations', ['to_version_id' => $versionId, 'reason' => 'Ponderação revista.']);
        });
    }
}
