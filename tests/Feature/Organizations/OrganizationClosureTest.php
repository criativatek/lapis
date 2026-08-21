<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\CancelOrganizationClosure;
use App\Actions\Organizations\RequestOrganizationClosure;
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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationClosureTest extends TestCase
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
    public function the_owner_can_request_closure_with_a_ninety_day_deadline_and_an_audit_event(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/team/closure')
            ->assertRedirect();

        $fresh = $organization->fresh();
        $this->assertNotNull($fresh->closure_requested_at);
        $this->assertEqualsWithDelta(
            $fresh->closure_requested_at->addDays(90)->getTimestamp(),
            $fresh->scheduled_deletion_at->getTimestamp(),
            2,
        );
        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $fresh,
            fn () => AuditEvent::where('event', 'organization.closure_requested')->exists(),
        ));
    }

    #[Test]
    public function a_member_a_cross_tenant_actor_and_an_impersonator_cannot_request_closure(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->member($organization);
        [$otherOrganization, $otherOwner] = $this->institutionalOrganization();

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->post('/team/closure')->assertForbidden();
        $this->actingAs($otherOwner)->withSession(['organization_id' => $otherOrganization->id])
            ->post('/team/closure')->assertRedirect(); // acting on THEIR OWN organization — allowed
        $this->assertNotNull($otherOrganization->fresh()->closure_requested_at);
        $this->assertNull($organization->fresh()->closure_requested_at);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id, 'impersonator_id' => 999])
            ->post('/team/closure')->assertForbidden();
        $this->assertNull($organization->fresh()->closure_requested_at);
    }

    #[Test]
    public function cancelling_within_the_window_restores_access_and_preserves_owner_members_and_data(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->member($organization);
        app(RequestOrganizationClosure::class)->request($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete('/team/closure')
            ->assertRedirect();

        $fresh = $organization->fresh();
        $this->assertNull($fresh->closure_requested_at);
        $this->assertNull($fresh->scheduled_deletion_at);
        $this->assertSame($owner->id, $fresh->owner_id);
        $this->assertTrue($fresh->members()->whereKey($owner->id)->exists());
        $this->assertTrue($fresh->members()->whereKey($member->id)->exists());
        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $fresh,
            fn () => AuditEvent::where('event', 'organization.closure_cancelled')->exists(),
        ));
    }

    #[Test]
    public function a_member_cannot_cancel_closure(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->member($organization);
        app(RequestOrganizationClosure::class)->request($organization, $owner);

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->delete('/team/closure')->assertForbidden();

        $this->assertNotNull($organization->fresh()->closure_requested_at);
    }

    #[Test]
    public function day_eighty_nine_is_recoverable_day_ninety_is_not(): void
    {
        [$recoverable, $owner1] = $this->institutionalOrganization();
        app(RequestOrganizationClosure::class)->request($recoverable, $owner1);
        $recoverable->forceFill(['closure_requested_at' => now()->subDays(89)])->save();
        app(CancelOrganizationClosure::class)->cancel($recoverable->fresh(), $owner1);
        $this->assertNull($recoverable->fresh()->closure_requested_at);

        [$expired, $owner2] = $this->institutionalOrganization();
        app(RequestOrganizationClosure::class)->request($expired, $owner2);
        $expired->forceFill(['closure_requested_at' => now()->subDays(90)])->save();

        $this->expectException(MembershipException::class);
        app(CancelOrganizationClosure::class)->cancel($expired->fresh(), $owner2);
    }

    #[Test]
    public function pedagogical_writes_are_blocked_for_every_member_but_reads_exports_and_the_owners_cancel_stay_available(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->member($organization);
        app(RequestOrganizationClosure::class)->request($organization, $owner);

        // Blocked for a regular member: normal pedagogical activity.
        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->post('/classes', ['label' => 'Turma X'])->assertForbidden();

        // Blocked for the owner too — closure is not a personal exemption.
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/classes', ['label' => 'Turma X'])->assertForbidden();

        // Allowed: reads still work for members.
        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->get('/dashboard')->assertOk();

        // Allowed: exporting data.
        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();

        // Allowed: the owner cancelling the closure.
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete('/team/closure')->assertRedirect();
        $this->assertNull($organization->fresh()->closure_requested_at);
    }

    #[Test]
    public function closing_one_institution_does_not_affect_the_owners_personal_organization_or_a_second_institution(): void
    {
        // A regular factory user — has a personal organization, owns
        // institution A, and is also a member of institution B.
        $owner = User::factory()->create();
        $personal = $owner->personalOrganization();
        $organizationA = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organizationA->members()->attach($owner, ['joined_at' => now()]);
        [$organizationB] = $this->institutionalOrganization();
        $organizationB->members()->attach($owner, ['joined_at' => now()]);

        app(RequestOrganizationClosure::class)->request($organizationA, $owner);

        // Switching to Personal: fully normal, unaffected by A's closure.
        $this->actingAs($owner)->withSession(['organization_id' => $personal->id])
            ->get('/dashboard')->assertOk();

        // Switching to B: also fully normal.
        $this->actingAs($owner)->withSession(['organization_id' => $organizationB->id])
            ->get('/dashboard')->assertOk();
        $this->assertNull($organizationB->fresh()->closure_requested_at);
    }
}
