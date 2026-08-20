<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\CreateOrRenewOrganizationInvitation;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia 3 — accepting an invitation: token validity, an existing account
 * logging in to accept, a brand-new account registering to accept, the email
 * mismatch refusal, and what a cancelled/expired/already-accepted token does.
 */
class InvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner];
    }

    /**
     * Invites $email into $organization and returns the raw token — captured
     * from the faked mail, the only place it ever exists outside the moment
     * of creation. Callers must Mail::fake() before calling this.
     */
    private function inviteAndCaptureToken(Organization $organization, User $owner, string $email): string
    {
        // invite() reads/writes through the organization's own invitations()
        // relation, which still carries OrganizationInvitation's tenant scope —
        // called here directly (not through an HTTP request, where
        // ResolveOrganization already resolves one), it needs the same wrapper
        // jobs and commands use.
        app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => app(CreateOrRenewOrganizationInvitation::class)->invite($organization, $owner, $email),
        );

        $token = null;
        Mail::assertSent(OrganizationInvitationMail::class, function ($mail) use ($email, &$token) {
            if ($mail->hasTo($email)) {
                $token = $mail->token;

                return true;
            }

            return false;
        });

        $this->assertNotNull($token);

        return $token;
    }

    // ------------------------------------------------------------------- token

    #[Test]
    public function a_random_token_is_refused(): void
    {
        $this->get('/invitations/'.str_repeat('x', 64))
            ->assertInertia(fn ($page) => $page->component('auth/InvitationInvalid'));
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'colega@escola.pt');

        OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail()
            ->forceFill(['expires_at' => now()->subDay()])->save();

        $this->get("/invitations/{$token}")
            ->assertInertia(fn ($page) => $page->component('auth/InvitationInvalid'));
    }

    #[Test]
    public function a_cancelled_token_is_refused(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'colega@escola.pt');

        OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail()
            ->forceFill(['cancelled_at' => now()])->save();

        $this->get("/invitations/{$token}")
            ->assertInertia(fn ($page) => $page->component('auth/InvitationInvalid'));
    }

    #[Test]
    public function an_already_accepted_token_cannot_be_used_again(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'colega@escola.pt');

        $firstUser = User::factory()->create(['email' => 'colega@escola.pt']);
        $this->actingAs($firstUser)->get("/invitations/{$token}")->assertRedirect(route('dashboard'));

        // Same token, a second visit — single-use.
        $this->actingAs($firstUser)->get("/invitations/{$token}")
            ->assertInertia(fn ($page) => $page->component('auth/InvitationInvalid'));
    }

    #[Test]
    public function a_token_from_another_organization_cannot_be_escalated(): void
    {
        Mail::fake();
        [$organizationA, $ownerA] = $this->institutionalOrganization();
        [$organizationB, $ownerB] = $this->institutionalOrganization();

        $tokenA = $this->inviteAndCaptureToken($organizationA, $ownerA, 'colega@escola.pt');

        $user = User::factory()->create(['email' => 'colega@escola.pt']);
        $this->actingAs($user)->get("/invitations/{$tokenA}");

        $this->assertTrue($organizationA->members()->whereKey($user->getKey())->exists());
        $this->assertFalse($organizationB->members()->whereKey($user->getKey())->exists());
    }

    // -------------------------------------------------------------- existing account

    #[Test]
    public function an_authenticated_user_with_the_matching_email_accepts_immediately(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'colega@escola.pt');

        $teacher = User::factory()->create(['email' => 'colega@escola.pt']);
        $personal = $teacher->personalOrganization();

        $this->actingAs($teacher)->get("/invitations/{$token}")->assertRedirect(route('dashboard'));

        $this->assertTrue($organization->members()->whereKey($teacher->getKey())->exists());
        $this->assertNotNull(OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail()->accepted_at);

        // The personal organization survives untouched.
        $this->assertTrue($teacher->fresh()->organizations()->whereKey($personal->id)->exists());
        $this->assertSame(2, $teacher->fresh()->organizations()->count());

        // The switcher now offers the institutional organization, and the
        // session was moved into it as part of accepting.
        $this->assertSame($organization->id, session('organization_id'));
    }

    #[Test]
    public function a_guest_whose_email_already_has_an_account_is_sent_to_login_and_resumes_after(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'colega@escola.pt');
        $teacher = User::factory()->create(['email' => 'colega@escola.pt', 'password' => 'segredo-forte']);

        $this->get("/invitations/{$token}")->assertRedirect(route('login'));
        $this->assertSame($token, session('pending_invitation_token'));

        $this->post('/login', ['email' => 'colega@escola.pt', 'password' => 'segredo-forte'])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($organization->members()->whereKey($teacher->getKey())->exists());
        $this->assertNull(session('pending_invitation_token'));
        $this->assertSame($organization->id, session('organization_id'));
    }

    // ------------------------------------------------------------------- new account

    #[Test]
    public function a_guest_with_no_account_is_sent_to_register_and_resumes_after(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'nova@escola.pt');

        $this->get("/invitations/{$token}")->assertRedirect(route('register'));
        $this->assertSame($token, session('pending_invitation_token'));
        $this->assertSame('nova@escola.pt', session('invitation_email'));

        $this->post('/register', [
            'name' => 'Nova Professora',
            'email' => 'nova@escola.pt',
            'password' => 'segredo-forte-123',
            'password_confirmation' => 'segredo-forte-123',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'nova@escola.pt')->firstOrFail();
        $this->assertTrue($organization->members()->whereKey($user->getKey())->exists());

        // The self-chosen password works — nothing was mailed or generated.
        $this->assertTrue(Hash::check('segredo-forte-123', $user->password));

        // Registration's own personal-organization guarantee still holds —
        // this fatia does not bypass it.
        $this->assertNotNull($user->personalOrganization());
        $this->assertSame(2, $user->organizations()->count());

        // Verified without a separate email-verification step — the invitation
        // link itself already proved control of this mailbox.
        $this->assertNotNull($user->email_verified_at);
    }

    /**
     * The exact incognito scenario: a valid, current, uncancelled, unexpired
     * token, reached with no session at all — no auth, no resolved
     * organization. A bare GET must only ever present a page; it must never
     * accept on the visitor's behalf.
     */
    #[Test]
    public function a_bare_get_with_no_session_never_accepts_the_invitation(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'teste.convite@example.test');
        $invitation = OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail();

        $this->get("/invitations/{$token}")->assertRedirect(route('register'));

        $invitation->refresh();
        $this->assertNull($invitation->accepted_at);
        $this->assertNull($invitation->cancelled_at);
        $this->assertFalse(User::where('email', 'teste.convite@example.test')->exists());
    }

    #[Test]
    public function registering_with_a_different_email_than_invited_does_not_silently_accept(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'nova@escola.pt');

        $this->get("/invitations/{$token}");

        $this->post('/register', [
            'name' => 'Outra Pessoa',
            'email' => 'outra@escola.pt',
            'password' => 'segredo-forte-123',
            'password_confirmation' => 'segredo-forte-123',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'outra@escola.pt')->firstOrFail();
        $this->assertFalse($organization->members()->whereKey($user->getKey())->exists());
    }

    // ------------------------------------------------------------ email mismatch

    #[Test]
    public function a_user_authenticated_as_someone_else_cannot_accept_silently(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'colega@escola.pt');

        $intruder = User::factory()->create(['email' => 'intruso@escola.pt']);

        $this->actingAs($intruder)->get("/invitations/{$token}")
            ->assertInertia(fn ($page) => $page
                ->component('auth/InvitationMismatch')
                ->where('invitedEmail', 'colega@escola.pt')
                ->where('currentEmail', 'intruso@escola.pt'));

        $this->assertFalse($organization->members()->whereKey($intruder->getKey())->exists());
        $this->assertNull(OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail()->accepted_at);
    }

    // -------------------------------------------------------------- cancellation

    #[Test]
    public function the_owner_cancels_a_pending_invitation_and_the_link_stops_working(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organization, $owner, 'colega@escola.pt');
        $invitation = OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete("/team/invitations/{$invitation->ulid}")
            ->assertRedirect();

        $this->assertNotNull($invitation->fresh()->cancelled_at);

        $this->get("/invitations/{$token}")
            ->assertInertia(fn ($page) => $page->component('auth/InvitationInvalid'));
    }

    #[Test]
    public function a_member_cannot_cancel_an_invitation(): void
    {
        Mail::fake();
        [$organization, $owner] = $this->institutionalOrganization();
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);
        $token = $this->inviteAndCaptureToken($organization, $owner, 'colega@escola.pt');
        $invitation = OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->delete("/team/invitations/{$invitation->ulid}")
            ->assertForbidden();

        $this->assertNull($invitation->fresh()->cancelled_at);
    }

    #[Test]
    public function the_owner_of_another_organization_cannot_cancel_this_one(): void
    {
        Mail::fake();
        [$organizationA, $ownerA] = $this->institutionalOrganization();
        [$organizationB, $ownerB] = $this->institutionalOrganization();
        $token = $this->inviteAndCaptureToken($organizationA, $ownerA, 'colega@escola.pt');
        $invitation = OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($ownerB)->withSession(['organization_id' => $organizationB->id])
            ->delete("/team/invitations/{$invitation->ulid}")
            ->assertNotFound();

        $this->assertNull($invitation->fresh()->cancelled_at);
    }
}
