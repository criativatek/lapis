<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Português', 'Matemática', 'Ciências Naturais', 'História', 'Inglês', 'Educação Visual']);

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'code' => strtoupper(fake()->unique()->bothify('???##')),
        ];
    }
}
