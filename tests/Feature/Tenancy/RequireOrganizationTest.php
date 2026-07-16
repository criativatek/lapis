<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RequireOrganizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_forbids_a_teacher_area_route_when_no_organization_is_resolved(): void
    {
        $user = User::factory()->withoutOrganization()->create();

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    #[Test]
    public function it_allows_a_teacher_area_route_once_an_organization_is_resolved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    #[Test]
    public function tenant_less_routes_stay_reachable_without_an_organization(): void
    {
        $user = User::factory()->withoutOrganization()->create();

        // Profile and logout are legitimately tenant-less. Requiring an
        // organization globally would lock a user out of their own account.
        $this->actingAs($user)->get('/settings/profile')->assertOk();
        $this->actingAs($user)->post('/logout')->assertRedirect();
    }
}
