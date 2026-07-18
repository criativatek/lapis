<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Oralidade', 'Leitura', 'Escrita', 'Gramática', 'Educação Literária']);

        return [
            'organization_id' => Organization::factory(),
            'subject_id' => null,
            'name' => $name,
            'code' => strtoupper(fake()->unique()->bothify('DOM##')),
            'sequence' => fake()->numberBetween(1, 10),
            'is_active' => true,
        ];
    }
}
