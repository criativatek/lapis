<?php

namespace Tests\Feature\Activity;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia 1 — who may read the audit trail (§22.4, and the multi-user security
 * brief §2, §7, §24).
 *
 * THE RULE: a member reads only what THEY caused (`causer_id = auth()->id()`).
 * The organization's owner reads the trail whole, because the audit log is an
 * institutional security function and the owner is the one authority this
 * schema has above "a teacher who belongs here" — not a role, not
 * `is_platform_admin`, just `organizations.owner_id`.
 *
 * The rule is deliberately NOT special-cased by organization type. A personal
 * organization's owner IS the only person who ever acts there, so the "owner
 * sees everything" branch and the "member sees only their own" branch return
 * identical rows — the uniform rule produces the right answer for free.
 *
 * `audit_log` is an Institucional-plan module (Lote 1): every organization
 * exercised here for its HTTP behaviour needs that plan on top of being an
 * "institutional" (multi-member) ORGANIZATION TYPE — the two are independent
 * axes, and the visibility rule under test is unaffected by which plan is
 * attached, so subscribing here changes nothing about what these tests prove.
 */
class AuditVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Organization, User, User} organization, owner, member — both attached as members, per how CreatePersonalOrganization actually establishes ownership (ResolveOrganization reads the pivot, never owner_id).
     */
    private function institutionalOrganizationWithMember(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $member = User::factory()->withoutOrganization()->create();

        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach([$owner->id, $member->id], ['joined_at' => now()]);
        $this->subscribeToInstitutionalPlan($organization);

        return [$organization, $owner, $member];
    }

    /**
     * Gives an organization the `audit_log` module by putting it on the
     * Institucional plan — the same subscribe-then-flush idiom used by
     * RequireModuleTest and PublicSelfAssessmentTest.
     */
    private function subscribeToInstitutionalPlan(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);

        app(Entitlements::class)->flush();
    }

    private function recordEvent(Organization $organization, ?User $causer, string $event = 'test.event'): AuditEvent
    {
        return app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): AuditEvent => app(AuditLog::class)->record($event, causer: $causer, summary: $event),
        );
    }

    // ---------------------------------------------------------- A: owner sees all

    #[Test]
    public function the_institutional_owner_sees_their_own_and_the_members_events(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();

        $this->recordEvent($organization, $owner, 'owner.action');
        $this->recordEvent($organization, $member, 'member.action');

        $visible = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::query()->visibleTo($owner)->pluck('event')->all(),
        );

        $this->assertEqualsCanonicalizing(['owner.action', 'member.action'], $visible);
    }

    #[Test]
    public function the_activity_page_shows_the_owner_the_whole_organization(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();

        $this->recordEvent($organization, $owner, 'owner.action');
        $this->recordEvent($organization, $member, 'member.action');

        $this->actingAs($owner)
            ->withSession(['organization_id' => $organization->id])
            ->get('/activity')
            ->assertInertia(fn ($page) => $page
                ->component('Activity')
                ->where('scope', 'organization')
                ->has('events', 2));
    }

    // --------------------------------------------------- B: member sees only their own

    #[Test]
    public function a_member_sees_only_their_own_events(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();

        $this->recordEvent($organization, $owner, 'owner.action');
        $this->recordEvent($organization, $member, 'member.action');

        $visible = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::query()->visibleTo($member)->pluck('event')->all(),
        );

        $this->assertSame(['member.action'], $visible);
    }

    #[Test]
    public function the_activity_page_shows_a_member_only_their_own_activity(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();

        $this->recordEvent($organization, $owner, 'owner.action');
        $this->recordEvent($organization, $member, 'member.action');
        $this->recordEvent($organization, $member, 'member.another_action');

        $this->actingAs($member)
            ->withSession(['organization_id' => $organization->id])
            ->get('/activity')
            ->assertInertia(fn ($page) => $page
                ->component('Activity')
                ->where('scope', 'own')
                ->has('events', 2)
                ->where('events.0.event', 'member.another_action')
                ->where('events.1.event', 'member.action'));
    }

    // --------------------------------------------------------- C: cross-tenant isolation

    #[Test]
    public function neither_owner_nor_member_of_one_organization_ever_sees_another_organizations_events(): void
    {
        [$organizationA, $ownerA, $memberA] = $this->institutionalOrganizationWithMember();
        [$organizationB, $ownerB] = $this->institutionalOrganizationWithMember();

        $this->recordEvent($organizationA, $ownerA, 'a.owner_action');
        $this->recordEvent($organizationA, $memberA, 'a.member_action');
        $this->recordEvent($organizationB, $ownerB, 'b.owner_action');

        // The owner of A reads A whole — never a row from B, tenant-scoped
        // below the visibility rule by AuditEvent's own global scope.
        $visibleToOwnerA = app(CurrentOrganization::class)->runFor(
            $organizationA,
            fn () => AuditEvent::query()->visibleTo($ownerA)->pluck('event')->all(),
        );
        $this->assertEqualsCanonicalizing(['a.owner_action', 'a.member_action'], $visibleToOwnerA);

        // The HTTP route confirms the same for B's owner: A's events never leak in.
        $this->actingAs($ownerB)
            ->withSession(['organization_id' => $organizationB->id])
            ->get('/activity')
            ->assertInertia(fn ($page) => $page
                ->has('events', 1)
                ->where('events.0.event', 'b.owner_action'));
    }

    #[Test]
    public function a_manipulated_organization_id_in_the_session_cannot_move_a_user_into_another_organizations_trail(): void
    {
        // ResolveOrganization re-checks membership against the database on
        // every request (ADR-0002) — a session id for an organization the
        // user does not belong to is simply ignored, not honoured.
        [$organizationA, , $memberA] = $this->institutionalOrganizationWithMember();
        [$organizationB, $ownerB] = $this->institutionalOrganizationWithMember();

        $this->recordEvent($organizationB, $ownerB, 'b.private_action');

        $this->actingAs($memberA)
            ->withSession(['organization_id' => $organizationB->id])
            ->get('/activity')
            ->assertInertia(fn ($page) => $page->missing('events.0.event'));
    }

    // ---------------------------------------------------------- D: platform admin

    #[Test]
    public function a_platform_admin_viewing_their_own_personal_organization_sees_the_ordinary_owner_view(): void
    {
        // Not a new capability — a platform admin's OWN workspace is a personal
        // organization like any other, and owner_id === user_id there already.
        // This is a regression guard, not new behaviour: is_platform_admin is
        // never read by AuditEvent::scopeVisibleTo at all.
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();
        $organization = $admin->personalOrganization();
        $this->subscribeToInstitutionalPlan($organization);

        $this->recordEvent($organization, $admin, 'admin.own_action');

        $this->actingAs($admin)
            ->get('/activity')
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'organization')
                ->has('events', 1));
    }

    // ------------------------------------------------------- E: causer_id null

    #[Test]
    public function a_system_caused_event_is_invisible_to_a_member_but_visible_to_the_owner(): void
    {
        // Decision: a null causer never equals anyone's id, so the plain
        // equality a member is filtered by already excludes it — no special
        // case needed. The owner's unscoped branch does not filter at all, so
        // they see it. This is the behaviour under test, not an assumption.
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();

        $this->recordEvent($organization, null, 'system.scheduled_close');

        $visibleToMember = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::query()->visibleTo($member)->pluck('event')->all(),
        );
        $visibleToOwner = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::query()->visibleTo($owner)->pluck('event')->all(),
        );

        $this->assertSame([], $visibleToMember);
        $this->assertSame(['system.scheduled_close'], $visibleToOwner);
    }

    // ------------------------------------------------------- personal organization

    #[Test]
    public function on_a_personal_organization_the_uniform_rule_shows_the_owner_everything_without_a_type_special_case(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $this->subscribeToInstitutionalPlan($organization);

        $this->recordEvent($organization, $teacher, 'teacher.own_action');

        $this->actingAs($teacher)
            ->get('/activity')
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'organization')
                ->has('events', 1));
    }

    // ------------------------------------------------------- F: plan gating (Lote 1)

    #[Test]
    public function a_base_organization_is_blocked_from_the_activity_page(): void
    {
        // A brand-new personal organization is on the Base plan by default
        // (CreatePersonalOrganization) — audit_log is Institucional-only.
        $teacher = User::factory()->create();

        $this->actingAs($teacher)->get('/activity')->assertForbidden();
    }

    #[Test]
    public function a_pro_organization_is_also_blocked_from_the_activity_page(): void
    {
        // audit_log sits above Pro too — only the Institucional plan carries it.
        $teacher = User::factory()->create();

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $teacher->personalOrganization()->getKey())
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $teacher->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($teacher)->get('/activity')->assertForbidden();
    }
}
