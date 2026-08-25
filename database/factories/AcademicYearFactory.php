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
        //
        // AND THE RANGE STARTS WELL PAST THE YEARS THE FIXTURES WRITE BY HAND.
        // `unique()` only promises that two FACTORY-made labels never collide;
        // it knows nothing about the literals the tests hardcode — «2026/2027»
        // sixteen times over, «2025/2026», «2027/2028», «2030/2031». With the
        // old 2020-2070 range, any factory year built for an organization that
        // already held a hand-written «2026/2027» blew up on
        // UNIQUE(organization_id, label) the moment the random draw happened to
        // land on 2026, roughly one time in fifty — and the odds rose as the
        // process burned values out of the shared unique() pool, so ADDING an
        // unrelated test file anywhere in the suite could turn a rare, invisible
        // flake into a frequent one. Starting at 2040 puts the whole range out
        // of reach of every literal the suite writes (the latest is 2030/2031),
        // so the two can never name the same year again. A test that needs a
        // PARTICULAR year still passes it explicitly, exactly as before.
        $startYear = fake()->unique()->numberBetween(2040, 2090);

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
