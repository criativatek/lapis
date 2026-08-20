<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\CreateOrRenewOrganizationInvitation;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia 3 — a freshly accepted invitation must not weaken anything Fatia 1
 * (audit visibility, shared-config authority) or Fatia 2 (the switcher,
 * entitlements, cross-tenant isolation) already established. §48 of the
 * multi-user brief in particular: membership is never pedagogical access.
 */
class InvitationRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Organization, User} organization on the Institutional plan, its owner (also a member).
     */
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

    /** Invites, accepts as a fresh existing user, and returns that member. */
    private function inviteAndAccept(Organization $organization, User $owner, string $email): User
    {
        Mail::fake();

        app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => app(CreateOrRenewOrganizationInvitation::class)->invite($organization, $owner, $email),
        );

        $token = null;
        Mail::assertSent(OrganizationInvitationMail::class, function ($mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $member = User::factory()->create(['email' => $email]);
        $this->actingAs($member)->get("/invitations/{$token}");

        return $member;
    }

    // ------------------------------------------------------------------- Fatia 1

    #[Test]
    public function a_freshly_accepted_member_sees_only_their_own_audit_events(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->inviteAndAccept($organization, $owner, 'colega@escola.pt');

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->get('/activity')
            ->assertInertia(fn ($page) => $page->where('scope', 'own'));
    }

    #[Test]
    public function a_freshly_accepted_member_cannot_write_shared_configuration(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->inviteAndAccept($organization, $owner, 'colega@escola.pt');

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->get('/subjects')
            ->assertInertia(fn ($page) => $page->where('canManage', false));

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->post('/subjects', ['name' => 'Português', 'code' => 'PT7'])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------- Fatia 2

    #[Test]
    public function the_switcher_offers_the_organization_right_after_accepting(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->inviteAndAccept($organization, $owner, 'colega@escola.pt');

        $this->actingAs($member)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('auth.organizations', 2)
            ->where('auth.organizations', fn ($organizations) => $organizations->pluck('ulid')->contains($organization->ulid)));
    }

    #[Test]
    public function entitlements_follow_the_organization_the_member_just_joined(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->inviteAndAccept($organization, $owner, 'colega@escola.pt');

        // accept() already switched them into it.
        $this->actingAs($member)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('modules', fn ($modules) => $modules->contains('calendar')));

        $this->actingAs($member)->post('/organizations/switch', ['organization' => $member->personalOrganization()->ulid]);

        $this->actingAs($member)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('modules', fn ($modules) => ! $modules->contains('calendar')));
    }

    #[Test]
    public function cross_tenant_isolation_holds_for_a_freshly_accepted_member(): void
    {
        [$organizationA, $ownerA] = $this->institutionalOrganization();
        [$organizationB, $ownerB] = $this->institutionalOrganization();
        $member = $this->inviteAndAccept($organizationA, $ownerA, 'colega@escola.pt');

        $subjectB = Subject::factory()->recycle($organizationB)->create();

        $this->actingAs($member)
            ->put("/subjects/{$subjectB->ulid}", ['name' => 'X', 'code' => 'X9'])
            ->assertNotFound();
    }

    // ---------------------------------------------------------- §48: pedagogical isolation

    #[Test]
    public function accepting_an_invitation_grants_no_access_to_a_colleagues_classes(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();

        $ownersClass = app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $owner) {
            $subject = Subject::factory()->recycle($organization)->create();
            $class = SchoolClass::factory()->recycle($organization)->create(['subject_id' => $subject->id]);
            $class->teachers()->attach($owner, ['role' => 'owner']);

            return $class;
        });

        $member = $this->inviteAndAccept($organization, $owner, 'colega@escola.pt');

        // Membership alone does not add the member to class_teachers — "only
        // my classes" (§23) is untouched by this fatia.
        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->get("/classes/{$ownersClass->ulid}")
            ->assertForbidden();

        $this->assertFalse($ownersClass->teachers()->whereKey($member->getKey())->exists());
    }
}
