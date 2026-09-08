<?php

namespace Database\Factories;

use App\Models\ClassGroup;
use App\Models\Organization;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassGroup>
 *
 * Share the organization with `->recycle($organization)` so the class and the
 * group belong to the same tenant.
 */
class ClassGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'class_id' => SchoolClass::factory(),
            'label' => 'T'.fake()->unique()->numberBetween(1, 9999),
            'position' => 0,
            'archived_at' => null,
        ];
    }

    public function archived(): self
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
