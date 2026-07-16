<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => OrganizationType::Personal,
            // withoutOrganization, or resolving the owner would create a second,
            // unrelated personal organization as a side effect of this one.
            'owner_id' => User::factory()->withoutOrganization(),
            'timezone' => 'Europe/Lisbon',
            'locale' => 'pt_PT',
        ];
    }

    public function institutional(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => OrganizationType::Institutional,
            'name' => 'Agrupamento de Escolas '.fake()->lastName(),
        ]);
    }

    /**
     * Attach a member after creation. Membership — not owner_id — is what the
     * middleware checks, so tests that authenticate a user need this.
     */
    public function withMember(User $user): static
    {
        return $this->afterCreating(function (Organization $organization) use ($user): void {
            $organization->members()->attach($user, ['joined_at' => now()]);
        });
    }
}
