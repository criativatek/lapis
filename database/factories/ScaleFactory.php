<?php

namespace Database\Factories;

use App\Models\Scale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scale>
 */
class ScaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // System scale by default (shared, no owning organization).
            'organization_id' => null,
            'name' => 'Escala '.fake()->unique()->numberBetween(1, 9999),
            'kind' => 'level',
            'min_value' => 1,
            'max_value' => 5,
        ];
    }
}
