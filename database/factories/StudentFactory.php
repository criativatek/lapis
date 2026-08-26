<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            // 6 random chars, not the real service's 4 (StudentEnrollmentService::
            // uniquePseudonym() gets away with 4 because it loops on a real DB
            // check per organization; a factory has no such loop, and a test
            // that seeds hundreds of students in one organization — as Lote 3's
            // limit tests do — hits a real, non-negligible birthday-paradox
            // collision rate at 4 chars (~2.6% at n=300). 6 chars makes it
            // negligible without changing the visual ALU-XXXX convention.
            'pseudonym_code' => 'ALU-'.Str::upper(Str::random(6)),
        ];
    }
}
