<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\LeaveOrganization;
use App\Actions\Organizations\TransferOrganizationOwnership;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Organizations\MembershipException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationMembershipTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Organization, User} */
    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner];
    }

    private function member(Organization $organization): User
    {
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);

        return $member;
    }

    #[Test]
    public function a_member_can_leave_without_losing_their_account_personal_organization_or_other_memberships(): void
    {
        [$organization] = $this->institutionalOrganization();
        [$otherOrganization] = $this->institutionalOrganization();
        $member = $this->member($organization);
        $otherOrganization->members()->attach($member, ['joined_at' => now()]);
        $personalOrganization = $member->personalOrganization();

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->post('/organizations/leave')
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($organization->members()->whereKey($member->id)->exists());
        $this->assertTrue(User::whereKey($member->id)->exists());
        $this->assertTrue($member->organizations()->whereKey($personalOrganization->id)->exists());
        $this->assertTrue($member->organizations()->whereKey($otherOrganization->id)->exists());
        $this->assertNull(session('organization_id'));
        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::where('event', 'organization.member_left')->exists(),
        ));

        $this->actingAs($member)
            ->post('/organizations/switch', ['organization' => $organization->ulid])
            ->assertForbidden();
    }

    #[Test]
    public function an_owner_cannot_leave_and_receives_the_exact_message(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/organizations/leave')
            ->assertSessionHasErrors([
                'organization' => 'Antes de sair, transfira a responsabilidade da organização para outro membro.',
            ]);

        $this->assertTrue($organization->members()->whereKey($owner->id)->exists());
    }

    #[Test]
    public function a_non_member_cannot_leave_an_organization(): void
    {
        [$organization] = $this->institutionalOrganization();
        $nonMember = User::factory()->create();

        $this->expectException(MembershipException::class);
        $this->expectExceptionMessage('A pessoa indicada não é membro desta organização.');

        app(LeaveOrganization::class)->leave($organization, $nonMember);
    }

    #[Test]
    public function leave_uses_only_the_resolved_organization_and_refuses_impersonation(): void
    {
        [$organizationA] = $this->institutionalOrganization();
        [$organizationB] = $this->institutionalOrganization();
        $member = $this->member($organizationA);
        $organizationB->members()->attach($member, ['joined_at' => now()]);

        $this->actingAs($member)->withSession([
            'organization_id' => $organizationA->id,
            'impersonator_id' => 999,
        ])->post('/organizations/leave', ['organization' => $organizationB->ulid])->assertForbidden();

        $this->assertTrue($organizationA->members()->whereKey($member->id)->exists());
        $this->assertTrue($organizationB->members()->whereKey($member->id)->exists());
    }

    #[Test]
    public function a_cross_tenant_organization_parameter_cannot_choose_which_membership_is_left(): void
    {
        [$organizationA] = $this->institutionalOrganization();
        [$organizationB] = $this->institutionalOrganization();
        $member = $this->member($organizationA);
        $organizationB->members()->attach($member, ['joined_at' => now()]);

        $this->actingAs($member)->withSession(['organization_id' => $organizationA->id])
            ->post('/organizations/leave', ['organization' => $organizationB->ulid])
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($organizationA->members()->whereKey($member->id)->exists());
        $this->assertTrue($organizationB->members()->whereKey($member->id)->exists());
        $this->assertTrue(User::whereKey($member->id)->exists());
    }

    #[Test]
    public function an_owner_removes_only_a_member_of_the_current_organization(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        [$otherOrganization] = $this->institutionalOrganization();
        $member = $this->member($organization);
        $personalOrganization = $member->personalOrganization();
        $otherOrganization->members()->attach($member, ['joined_at' => now()]);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete('/team/members', ['member' => $member->id])->assertRedirect();

        $this->assertFalse($organization->members()->whereKey($member->id)->exists());
        $this->assertTrue(User::whereKey($member->id)->exists());
        $this->assertTrue($member->organizations()->whereKey($personalOrganization->id)->exists());
        $this->assertTrue($member->organizations()->whereKey($otherOrganization->id)->exists());
        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::where('event', 'organization.member_removed')->exists(),
        ));
    }

    #[Test]
    public function remove_rejects_non_owner_self_cross_tenant_and_impersonated_requests(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        [$otherOrganization] = $this->institutionalOrganization();
        $member = $this->member($organization);
        $otherMember = $this->member($otherOrganization);

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->delete('/team/members', ['member' => $owner->id])->assertForbidden();
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete('/team/members', ['member' => $owner->id])->assertSessionHasErrors('organization');
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete('/team/members', ['member' => $otherMember->id])->assertSessionHasErrors('organization');
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id, 'impersonator_id' => 999])
            ->delete('/team/members', ['member' => $member->id])->assertForbidden();
    }

    #[Test]
    public function ownership_transfer_changes_authority_and_keeps_both_memberships(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->member($organization);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/team/members/transfer-ownership', ['member' => $member->id])
            ->assertRedirect(route('team.index'));

        $fresh = $organization->fresh();
        $this->assertSame($member->id, $fresh->owner_id);
        $this->assertNotNull($fresh->owner_id);
        $this->assertFalse($owner->owns($fresh));
        $this->assertTrue($member->owns($fresh));
        $this->assertTrue($fresh->members()->whereKey($owner->id)->exists());
        $this->assertTrue($fresh->members()->whereKey($member->id)->exists());
        $this->assertFalse(app(CurrentOrganization::class)->get()->owner_id === $owner->id);
        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $fresh,
            fn () => AuditEvent::where('event', 'organization.ownership_transferred')->exists(),
        ));

        $this->actingAs($owner)->withSession(['organization_id' => $fresh->id])
            ->get('/team')->assertForbidden();
        $this->actingAs($member)->withSession(['organization_id' => $fresh->id])
            ->get('/team')->assertOk();
    }

    #[Test]
    public function ownership_authority_changes_inside_the_same_http_response(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->member($organization);

        Route::middleware(['web', 'auth', 'verified', 'organization'])
            ->post('/_test/organizations/transfer-authority', function () use ($member): array {
                $currentOwner = request()->user();
                $organization = app(CurrentOrganization::class)->get();

                if (! $currentOwner instanceof User) {
                    abort(401);
                }

                app(TransferOrganizationOwnership::class)->transfer($organization, $currentOwner, $member);

                return [
                    'previous_owner_owns_current' => $currentOwner->ownsCurrentOrganization(),
                    'new_owner_owns_current' => $member->ownsCurrentOrganization(),
                    'resolved_owner_id' => app(CurrentOrganization::class)->get()->owner_id,
                ];
            });

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->postJson('/_test/organizations/transfer-authority')
            ->assertOk()
            ->assertExactJson([
                'previous_owner_owns_current' => false,
                'new_owner_owns_current' => true,
                'resolved_owner_id' => $member->id,
            ]);
    }

    #[Test]
    public function ownership_transfer_rejects_non_members_cross_tenant_targets_and_impersonation(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        [$otherOrganization] = $this->institutionalOrganization();
        $stranger = User::factory()->create();
        $otherMember = $this->member($otherOrganization);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/team/members/transfer-ownership', ['member' => $stranger->id])->assertSessionHasErrors('organization');
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/team/members/transfer-ownership', ['member' => $otherMember->id])->assertSessionHasErrors('organization');
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id, 'impersonator_id' => 999])
            ->post('/team/members/transfer-ownership', ['member' => $stranger->id])->assertForbidden();

        $this->assertSame($owner->id, $organization->fresh()->owner_id);
    }
}
