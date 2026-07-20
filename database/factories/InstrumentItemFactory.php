<?php

namespace Database\Factories;

use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstrumentItem>
 */
class InstrumentItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'instrument_id' => Instrument::factory(),
            'code' => 'Q'.fake()->unique()->numberBetween(1, 999),
            'label' => null,
            'sequence' => 1,
            'points_possible' => 10,
            'scoring_mode' => 'points',
            'is_bonus' => false,
        ];
    }
}
