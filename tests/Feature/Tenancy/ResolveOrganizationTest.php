<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveOrganizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/current-organization', function () {
            return response()->json([
                'id' => app(CurrentOrganization::class)->id(),
            ]);
        });
    }

    #[Test]
    public function it_resolves_the_users_personal_organization_for_the_request(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/_test/current-organization');

        $response->assertOk()->assertJson(['id' => $user->personalOrganization()->getKey()]);
    }

    #[Test]
    public function it_ignores_a_session_organization_the_user_does_not_belong_to(): void
    {
        $user = User::factory()->create();
        $own = $user->personalOrganization();
        $someoneElses = Organization::factory()->create();

        // A tampered session cookie must not move the user into another tenant:
        // membership is re-checked against the database on every request.
        $response = $this->actingAs($user)
            ->withSession(['organization_id' => $someoneElses->getKey()])
            ->getJson('/_test/current-organization');

        $response->assertOk()->assertJson(['id' => $own->getKey()]);
    }

    #[Test]
    public function it_honours_a_session_organization_the_user_does_belong_to(): void
    {
        $user = User::factory()->create();
        $institution = Organization::factory()->institutional()->withMember($user)->create();

        $response = $this->actingAs($user)
            ->withSession(['organization_id' => $institution->getKey()])
            ->getJson('/_test/current-organization');

        $response->assertOk()->assertJson(['id' => $institution->getKey()]);
    }

    #[Test]
    public function it_leaves_the_tenant_unresolved_for_guests(): void
    {
        $this->get('/_test/current-organization')->assertStatus(500);

        $this->assertFalse(app(CurrentOrganization::class)->isResolved());
    }

    #[Test]
    public function it_does_not_resolve_a_tenant_for_a_user_without_an_organization(): void
    {
        $user = User::factory()->withoutOrganization()->create();

        // Best-effort: the middleware lets the request through unresolved. The
        // 500 comes from the test route asking for an id there is none of —
        // which is the point: it fails loudly rather than picking someone's org.
        $this->actingAs($user)->get('/_test/current-organization')->assertStatus(500);
    }
}
