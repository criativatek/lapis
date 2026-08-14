<?php

namespace Database\Factories;

use App\Models\Instrument;
use App\Models\InstrumentGroup;
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
            // Every question belongs to a group. A test that does not care
            // about structure gets the instrument's implicit one — created here
            // if the instrument has none yet, so factories keep working exactly
            // as they did before groups existed.
            'instrument_group_id' => fn (array $attributes) => InstrumentGroup::firstOrCreate(
                ['instrument_id' => $attributes['instrument_id'], 'sequence' => 1],
                ['organization_id' => $attributes['organization_id'], 'label' => null],
            )->id,
            'code' => 'Q'.fake()->unique()->numberBetween(1, 999),
            'label' => null,
            'sequence' => 1,
            'points_possible' => 10,
            'scoring_mode' => 'points',
            'is_bonus' => false,
        ];
    }
}
