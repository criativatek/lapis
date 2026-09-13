<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 *
 * Share the organization with `->recycle($organization)`.
 */
class SchoolClassFactory extends Factory
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
            'label' => '7.º '.fake()->randomLetter(),
            'status' => 'preparation',
        ];
    }

    public function support(): static
    {
        return $this->state(['is_support_class' => true]);
    }
}
