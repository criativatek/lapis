<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 *
 * Share the organization with `->recycle($organization)` so class, student and
 * enrollment all belong to the same tenant.
 */
class EnrollmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'class_id' => SchoolClass::factory(),
            'student_id' => Student::factory(),
            'enrolled_on' => '2026-09-14',
            'status' => 'active',
            'is_late_entry' => false,
        ];
    }
}
