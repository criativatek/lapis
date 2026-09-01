<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\Organization;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentProfile>
 *
 * Share the organization with `->recycle($organization)` so the year, subject
 * and profile all belong to the same tenant.
 *
 * grade_level is no longer a column on assessment_profiles — it lives in the
 * related assessment_profile_grade_levels table. A profile built by this
 * factory with no further state carries one grade level, '7.º', via
 * afterCreating, so existing tests that never mentioned grade levels keep
 * seeing exactly one. Pass ->withGradeLevels([...]) to control the set.
 */
class AssessmentProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'subject_id' => Subject::factory(),
            'name' => 'Português – 7.º Ano',
            'description' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (AssessmentProfile $profile): void {
            if ($profile->gradeLevels()->count() === 0) {
                $profile->gradeLevels()->create(['grade_level' => '7.º']);
            }
        });
    }

    /**
     * @param  list<string>  $gradeLevels
     */
    public function withGradeLevels(array $gradeLevels): static
    {
        return $this->afterCreating(function (AssessmentProfile $profile) use ($gradeLevels): void {
            $profile->gradeLevels()->delete();
            foreach (array_unique($gradeLevels) as $gradeLevel) {
                $profile->gradeLevels()->create(['grade_level' => $gradeLevel]);
            }
        });
    }
}
