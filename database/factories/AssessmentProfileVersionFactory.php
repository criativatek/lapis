<?php

namespace Database\Factories;

use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Organization;
use App\Models\ProfileVersionStatus;
use App\Models\Scale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentProfileVersion>
 */
class AssessmentProfileVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'assessment_profile_id' => AssessmentProfile::factory(),
            'version_number' => 1,
            'status' => ProfileVersionStatus::Draft,
            'scale_id' => Scale::factory(),
            'domain_weight_mode' => 'must_total_100',
            'period_result_mode' => 'weighted_domain_average',
            'rounding_scale' => 0,
            'rounding_stage' => 'final_only',
        ];
    }
}
