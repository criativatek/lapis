<?php

namespace Tests\Support;

use App\Models\AcademicPeriod;
use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\MigrateClassProfile;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;

/**
 * The production case, reproduced on the demo class.
 *
 * The class is first assessed on «Percentagem (0 a 100)» (a scale with no
 * levels); its profile is then edited to «Escala 1 a 5» — same domains, same
 * weights — and the new version activated. The class stays on the percentage
 * version until it is migrated (§10.2).
 *
 * Use from a test class that has `$this->teacher` (a User) and has seeded the
 * demo scenario.
 */
trait MigratesPercentageClassToLevels
{
    protected AssessmentProfileVersion $percentageVersion;

    protected AssessmentProfileVersion $levelVersion;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    protected function seedTeacherAndDemo(): void
    {
        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    protected function demoClass(): SchoolClass
    {
        return $this->inTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    /**
     * Gives the profile of the class a new ACTIVE version on the given scale,
     * keeping every domain and weight as they are.
     */
    protected function activateVersionOn(SchoolClass $class, string $scaleName): AssessmentProfileVersion
    {
        $profile = $class->profileVersion->profile;

        $domains = $profile->currentVersion->domains()->with('domain')->get()
            ->map(fn ($profileDomain): array => ['name' => $profileDomain->domain->name, 'weight' => (float) $profileDomain->weight_percent])
            ->all();

        app(ProfileBuilder::class)->update(
            $profile,
            ['subject_id' => $profile->subject_id, 'name' => $profile->name, 'description' => $profile->description],
            Scale::where('name', $scaleName)->firstOrFail()->id,
            $domains,
            $profile->gradeLevels->pluck('grade_level')->all(),
        );

        return app(ActivateProfileVersion::class)->activate($profile->refresh()->draftVersion(), $this->teacher);
    }

    /**
     * The class on the percentage version, with open proposals in the first
     * period — and, when asked, one PUBLISHED decision written as a value.
     *
     * Does NOT migrate: the 1–5 version exists and is active, the class is still
     * on the percentage one.
     *
     * @return array{class: SchoolClass, period: AcademicPeriod}
     */
    protected function percentageClassWithProposals(?string $publishedValue = '65', string $publishedFor = 'Carolina Nunes'): array
    {
        return $this->inTenant(function () use ($publishedValue, $publishedFor): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            $this->percentageVersion = $this->activateVersionOn($class, 'Percentagem (0 a 100)');
            $class->update(['assessment_profile_version_id' => $this->percentageVersion->id]);
            $class->refresh();

            $this->levelVersion = $this->activateVersionOn($class, 'Escala 1 a 5');

            app(ProposeClassifications::class)->forPeriod($class, $period);

            if ($publishedValue !== null) {
                $classification = $this->classificationOf($publishedFor, $period->id);

                app(ConfirmClassification::class)->confirm($classification, $this->teacher, null, $publishedValue);
                app(PublishClassifications::class)->forPeriod($class, $period, ClassificationScope::Period);
            }

            return ['class' => $class->refresh(), 'period' => $period];
        });
    }

    protected function classificationOf(string $studentName, int $periodId): Classification
    {
        return Classification::query()
            ->where('academic_period_id', $periodId)
            ->where('scope', ClassificationScope::Period)
            ->get()
            ->first(fn (Classification $classification): bool => $classification->enrollment->student->identity->display_name === $studentName);
    }

    protected function migrateToTheLevelVersion(SchoolClass $class): void
    {
        $this->inTenant(fn () => app(MigrateClassProfile::class)->migrate(
            SchoolClass::whereKey($class->id)->firstOrFail(),
            $this->levelVersion,
            'A turma passa a ser avaliada na escala de 1 a 5.',
            $this->teacher,
        ));
    }
}
