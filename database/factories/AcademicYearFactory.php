<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // unique(): the label is unique per organization, and a small random range
        // collided whenever a test built two years for the same teacher.
        $startYear = fake()->unique()->numberBetween(2020, 2070);

        return [
            'organization_id' => Organization::factory(),
            'label' => sprintf('%d/%d', $startYear, $startYear + 1),
            'starts_on' => sprintf('%d-09-01', $startYear),
            'ends_on' => sprintf('%d-08-31', $startYear + 1),
            'status' => AcademicYearStatus::Draft,
            'country_code' => 'PT',
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => AcademicYearStatus::Active]);
    }

    public function closed(): static
    {
        return $this->state(['status' => AcademicYearStatus::Closed]);
    }
}
