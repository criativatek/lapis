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
            'grade_level' => '7.º',
            'name' => 'Português – 7.º Ano',
            'description' => null,
        ];
    }
}
