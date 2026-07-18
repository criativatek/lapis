<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicPeriodStatus;
use App\Models\AcademicYear;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicPeriod>
 *
 * A period must share its organization with its year. In tests, use
 * `->recycle($organization)` so both relationships resolve to the same one:
 * `AcademicPeriod::factory()->recycle($organization)->for($year)->create()`.
 */
class AcademicPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'label' => '1.º Semestre',
            'kind' => AcademicPeriodKind::Semester,
            'sequence' => 1,
            'starts_on' => '2026-09-14',
            'ends_on' => '2027-01-29',
            'status' => AcademicPeriodStatus::Draft,
        ];
    }
}
