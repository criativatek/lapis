<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\Organization;
use App\Models\StudentItemScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentItemScore>
 */
class StudentItemScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'instrument_id' => Instrument::factory(),
            'instrument_item_id' => InstrumentItem::factory(),
            'enrollment_id' => Enrollment::factory(),
            'result_state' => 'assessed',
            'points_earned' => 5,
        ];
    }
}
