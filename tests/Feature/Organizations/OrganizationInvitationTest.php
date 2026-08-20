<?php

namespace Tests\Feature\Organizations;

use App\Mail\OrganizationInvitationMail;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia 3 — creating (and renewing) an invitation into an institutional
 * organization. Owner-only, one live invitation per (organization, email).
 */
class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Organization, User, User} organization (Institutional plan), owner, member — both attached (Fatia 2's invariant: owner is also a member).
     */
    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $member = User::factory()->withoutOrganization()->create();

        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach([$owner->id, $member->id], ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner, $member];
    }

    private function asMemberOf(Organization $organization, User $user): self
    {
        return $this->actingAs($user)->withSession(['organization_id' => $organization->id]);
    }

    #[Test]
    public function the_owner_invites_someone_and_an_email_is_sent(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();

        $this->asMemberOf($organization, $owner)
            ->post('/team/invitations', ['email' => 'colega@escola.pt'])
            ->assertRedirect();

        $invitation = OrganizationInvitation::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame('colega@escola.pt', $invitation->email);
        $this->assertSame($owner->id, $invitation->invited_by);
        $this->assertNull($invitation->accepted_at);
        $this->assertNull($invitation->cancelled_at);
        $this->assertTrue($invitation->expires_at->isFuture());

        Mail::assertSent(OrganizationInvitationMail::class, fn ($mail) => $mail->hasTo('colega@escola.pt'));

        $trail = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::where('event', 'organization.invitation_created')->exists(),
        );
        $this->assertTrue($trail);
    }

    #[Test]
    public function a_member_cannot_invite_anyone(): void
    {
        Mail::fake();
        [$organization, , $member] = $this->institutionalOrganization();

        $this->asMemberOf($organization, $member)
            ->post('/team/invitations', ['email' => 'colega@escola.pt'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_personal_organizations_owner_cannot_reach_the_team_endpoints(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)->get('/team')->assertForbidden();
        $this->actingAs($teacher)->post('/team/invitations', ['email' => 'x@escola.pt'])->assertForbidden();
    }

    #[Test]
    public function an_invalid_email_is_refused(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();

        $this->asMemberOf($organization, $owner)
            ->post('/team/invitations', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function an_existing_member_cannot_be_invited_again(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganization();

        $this->asMemberOf($organization, $owner)
            ->post('/team/invitations', ['email' => $member->email])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, OrganizationInvitation::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function the_owner_cannot_invite_themselves(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();

        $this->asMemberOf($organization, $owner)
            ->post('/team/invitations', ['email' => $owner->email])
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function inviting_the_same_pending_email_twice_renews_it_instead_of_duplicating(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();

        $this->asMemberOf($organization, $owner)->post('/team/invitations', ['email' => 'colega@escola.pt']);
        $first = OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail();
        $firstTokenHash = $first->token_hash;

        $this->asMemberOf($organization, $owner)->post('/team/invitations', ['email' => 'colega@escola.pt']);

        $this->assertSame(1, OrganizationInvitation::withoutGlobalScope('organization')->count());

        $renewed = $first->fresh();
        $this->assertNotSame($firstTokenHash, $renewed->token_hash);
        Mail::assertSent(OrganizationInvitationMail::class, 2);
    }

    #[Test]
    public function the_team_page_lists_members_and_pending_invitations(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganization();
        $this->asMemberOf($organization, $owner)->post('/team/invitations', ['email' => 'colega@escola.pt']);

        $this->asMemberOf($organization, $owner)->get('/team')->assertInertia(fn ($page) => $page
            ->component('team/Index')
            ->has('members', 2)
            ->has('invitations', 1)
            ->where('invitations.0.email', 'colega@escola.pt'));
    }

    #[Test]
    public function a_member_never_sees_the_team_link_in_navigation_but_the_owner_does(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganization();

        $ownerNav = $this->asMemberOf($organization, $owner)->get('/dashboard');
        $ownerNav->assertInertia(fn ($page) => $page
            ->where('nav.sections', fn ($sections) => $sections->flatMap(fn ($s) => $s['items'])->pluck('key')->contains('team')));

        $memberNav = $this->asMemberOf($organization, $member)->get('/dashboard');
        $memberNav->assertInertia(fn ($page) => $page
            ->where('nav.sections', fn ($sections) => ! $sections->flatMap(fn ($s) => $s['items'])->pluck('key')->contains('team')));
    }
}
