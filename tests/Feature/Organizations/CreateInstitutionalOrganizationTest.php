<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\OrganizationType;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia 2 — the invariant found while auditing Fatia 1: ResolveOrganization
 * resolves a tenant from `organization_memberships`, never from `owner_id`. An
 * owner who is not also a member could never have this organization resolved
 * as their own. So the action's whole reason to exist is writing owner_id and
 * the membership TOGETHER, atomically — these tests exist to hold that.
 */
class CreateInstitutionalOrganizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_organization_with_the_given_owner(): void
    {
        $owner = User::factory()->withoutOrganization()->create();

        $organization = app(CreateInstitutionalOrganization::class)->create('Escola Secundária X', $owner);

        $this->assertSame('Escola Secundária X', $organization->name);
        $this->assertSame(OrganizationType::Institutional, $organization->type);
        $this->assertSame($owner->id, $organization->owner_id);
    }

    #[Test]
    public function the_owner_is_also_a_member_never_owner_id_alone(): void
    {
        // The whole point: ResolveOrganization reads the pivot, not owner_id. An
        // owner without this row could never resolve their own organization.
        $owner = User::factory()->withoutOrganization()->create();

        $organization = app(CreateInstitutionalOrganization::class)->create('Escola Secundária X', $owner);

        $this->assertTrue($organization->members()->whereKey($owner->getKey())->exists());
        $this->assertTrue($owner->organizations()->whereKey($organization->id)->exists());
    }

    #[Test]
    public function it_subscribes_the_organization_to_the_given_plan(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        $pro = Plan::where('key', 'pro')->firstOrFail();

        $organization = app(CreateInstitutionalOrganization::class)->create('Escola Secundária X', $owner, $pro);

        $subscription = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame($pro->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status->value);
        $this->assertNull($subscription->ends_at);
    }

    #[Test]
    public function with_no_plan_argument_it_defaults_to_the_institutional_plan(): void
    {
        $owner = User::factory()->withoutOrganization()->create();

        $organization = app(CreateInstitutionalOrganization::class)->create('Escola Secundária X', $owner);

        $subscription = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame('institutional', $subscription->plan->key);
    }

    #[Test]
    public function a_teachers_own_personal_organization_survives_owning_a_school_too(): void
    {
        // The worked example from the brief: Professora Ana keeps her personal
        // workspace AND owns the school. Neither replaces the other.
        $teacher = User::factory()->create();
        $personal = $teacher->personalOrganization();

        $school = app(CreateInstitutionalOrganization::class)->create('Escola Secundária X', $teacher);

        $this->assertTrue($teacher->organizations()->whereKey($personal->id)->exists());
        $this->assertTrue($teacher->organizations()->whereKey($school->id)->exists());
        $this->assertSame(2, $teacher->organizations()->count());
        $this->assertSame(OrganizationType::Personal, $personal->fresh()->type);
    }

    #[Test]
    public function nothing_is_written_if_the_transaction_fails_partway(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        $countBefore = Organization::withoutGlobalScope('organization')->count();

        // A plan whose id does not exist — the subscription's foreign key
        // refuses it, partway through the action's work.
        $nonExistentPlan = Plan::make(['key' => 'nonexistent'])->forceFill(['id' => 999999]);

        try {
            app(CreateInstitutionalOrganization::class)->create('Escola Falhada', $owner, $nonExistentPlan);
            $this->fail('Expected the invalid plan reference to fail.');
        } catch (\Throwable) {
            // Expected.
        }

        $this->assertSame($countBefore, Organization::withoutGlobalScope('organization')->count());
        $this->assertDatabaseMissing('organizations', ['name' => 'Escola Falhada']);
    }
}
