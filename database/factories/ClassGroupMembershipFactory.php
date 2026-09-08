<?php

namespace Database\Factories;

use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassGroupMembership>
 */
class ClassGroupMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'class_group_id' => ClassGroup::factory(),
            'enrollment_id' => Enrollment::factory(),
            'effective_from' => '2026-09-14',
            'effective_until' => null,
        ];
    }
}
