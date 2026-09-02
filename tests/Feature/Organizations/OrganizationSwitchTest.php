<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia 2 — POST /organizations/switch: how a user with more than one
 * organization picks which one they are working in, without a logout.
 *
 * Multi-organization does not mean pedagogical access widens (§38, §58 of the
 * multi-user brief) — these tests hold that a switch only ever changes
 * CONTEXT (session organization_id, entitlements, navigation), never who a
 * class, subject or report belongs to.
 */
class OrganizationSwitchTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------- A: single organization

    #[Test]
    public function a_user_with_one_organization_sees_no_switcher_and_nothing_changes(): void
    {
        $teacher = User::factory()->create();

        $response = $this->actingAs($teacher)->get('/dashboard');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('auth.organizations', 1)
            ->where('auth.organization.ulid', $teacher->personalOrganization()->ulid));
    }

    // ------------------------------------------------------ B: several organizations

    #[Test]
    public function auth_lists_every_organization_the_user_belongs_to(): void
    {
        $teacher = User::factory()->create();
        $personal = $teacher->personalOrganization();
        $school = Organization::factory()->institutional()->withMember($teacher)->create();

        $this->actingAs($teacher)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('auth.organizations', 2)
            ->where('auth.organizations.0.ulid', $personal->ulid)
            ->where('auth.organizations.0.is_owner', true)
            ->where('auth.organizations.1.ulid', $school->ulid)
            ->where('auth.organizations.1.is_owner', false));
    }

    // -------------------------------------------------------------------- C/D: switch

    #[Test]
    public function switching_to_an_organization_the_user_belongs_to_changes_the_session(): void
    {
        $teacher = User::factory()->create();
        $school = Organization::factory()->institutional()->withMember($teacher)->create();

        $this->actingAs($teacher)
            ->post('/organizations/switch', ['organization' => $school->ulid])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($school->id, session('organization_id'));
    }

    #[Test]
    public function switching_to_an_organization_the_user_does_not_belong_to_is_blocked(): void
    {
        $teacher = User::factory()->create();
        $ownOrganization = $teacher->personalOrganization();
        $stranger = Organization::factory()->create();

        $this->actingAs($teacher)
            ->post('/organizations/switch', ['organization' => $stranger->ulid])
            ->assertForbidden();

        // The tenant never moved — ResolveOrganization had already resolved (and
        // stamped in session) the teacher's own organization before the switch
        // attempt was even refused.
        $this->assertSame($ownOrganization->id, session('organization_id'));
    }

    #[Test]
    public function a_platform_admin_does_not_gain_implicit_membership_of_a_school_they_did_not_join(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();
        $school = Organization::factory()->institutional()->create();

        $this->actingAs($admin)
            ->post('/organizations/switch', ['organization' => $school->ulid])
            ->assertForbidden();
    }

    #[Test]
    public function an_unknown_organization_value_is_refused_the_same_as_one_that_belongs_to_someone_else(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)
            ->post('/organizations/switch', ['organization' => 'not-a-real-ulid'])
            ->assertForbidden();
    }

    // ------------------------------------------------------------- destination/context

    #[Test]
    public function switching_always_lands_on_the_dashboard_never_a_page_carrying_the_old_organizations_ids(): void
    {
        $teacher = User::factory()->create();
        $school = Organization::factory()->institutional()->withMember($teacher)->create();

        $response = $this->actingAs($teacher)
            ->from('/subjects')
            ->post('/organizations/switch', ['organization' => $school->ulid]);

        $response->assertRedirect(route('dashboard'));
    }

    // ------------------------------------------------------------------- entitlements

    #[Test]
    public function entitlements_follow_the_currently_selected_organizations_subscription(): void
    {
        $teacher = User::factory()->create(); // personal org, Base plan
        $school = app(CreateInstitutionalOrganization::class)->create(
            'Escola Secundária X',
            User::factory()->withoutOrganization()->create(),
            Plan::where('key', 'pro')->firstOrFail(),
        );
        $school->members()->attach($teacher, ['joined_at' => now()]);

        $this->actingAs($teacher)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('modules', fn ($modules) => ! $modules->contains('lessons')));

        $this->actingAs($teacher)->post('/organizations/switch', ['organization' => $school->ulid]);

        $this->actingAs($teacher)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('modules', fn ($modules) => $modules->contains('lessons')));
    }

    #[Test]
    public function switching_back_removes_the_capabilities_the_other_organization_granted(): void
    {
        $teacher = User::factory()->create();
        $school = app(CreateInstitutionalOrganization::class)->create(
            'Escola Secundária X',
            User::factory()->withoutOrganization()->create(),
            Plan::where('key', 'institutional')->firstOrFail(),
        );
        $school->members()->attach($teacher, ['joined_at' => now()]);

        $this->actingAs($teacher)->post('/organizations/switch', ['organization' => $school->ulid]);
        app(Entitlements::class)->flush();
        $this->assertTrue(app(Entitlements::class)->allowsFor($school, 'lessons'));

        $this->actingAs($teacher)->post('/organizations/switch', ['organization' => $teacher->personalOrganization()->ulid]);
        app(Entitlements::class)->flush();
        $this->assertFalse(app(Entitlements::class)->allowsFor($teacher->personalOrganization()->fresh(), 'lessons'));
    }

    // ------------------------------------------------------------------ cross-tenant

    #[Test]
    public function a_subject_from_the_organization_left_behind_is_unreachable_after_switching(): void
    {
        $teacher = User::factory()->create();
        $subjectInPersonal = Subject::factory()->recycle($teacher->personalOrganization())->create();
        $school = Organization::factory()->institutional()->withMember($teacher)->create();

        $this->actingAs($teacher)->post('/organizations/switch', ['organization' => $school->ulid]);

        $this->actingAs($teacher)
            ->put("/subjects/{$subjectInPersonal->ulid}", ['name' => 'Intruso', 'code' => 'INT'])
            ->assertNotFound();
    }

    #[Test]
    public function belonging_to_a_school_does_not_leak_its_data_while_still_working_in_the_personal_organization(): void
    {
        $teacher = User::factory()->create();
        $school = Organization::factory()->institutional()->withMember($teacher)->create();
        $subjectInSchool = Subject::factory()->recycle($school)->create();

        // Never switched — still resolved into the personal organization, even
        // though the teacher IS a real member of the school.
        $this->actingAs($teacher)
            ->get('/subjects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('subjects', []));

        $this->actingAs($teacher)
            ->put("/subjects/{$subjectInSchool->ulid}", ['name' => 'X', 'code' => 'X9'])
            ->assertNotFound();
    }

    // --------------------------------------------------------------------- audit log

    #[Test]
    public function audit_visibility_still_follows_ownership_of_whichever_organization_is_current(): void
    {
        // audit_log (Lote 1) is Institucional-only — both organizations this
        // test switches between need that plan, or the /activity assertions
        // below would 403 before ever reaching the visibility rule under test.
        $teacher = User::factory()->create();
        $this->subscribeToInstitutionalPlan($teacher->personalOrganization());

        $school = app(CreateInstitutionalOrganization::class)->create(
            'Escola Secundária X',
            User::factory()->withoutOrganization()->create(),
            Plan::where('key', 'institutional')->firstOrFail(),
        );
        $school->members()->attach($teacher, ['joined_at' => now()]);

        // In their own personal organization, the teacher is the owner and sees
        // the whole (their own) trail — unchanged from Fatia 1.
        $this->actingAs($teacher)->get('/activity')->assertInertia(fn ($page) => $page->where('scope', 'organization'));

        // After switching into a school they do NOT own, they are a member —
        // Fatia 1's member-scoped rule applies in the new context too.
        $this->actingAs($teacher)->post('/organizations/switch', ['organization' => $school->ulid]);
        $this->actingAs($teacher)->get('/activity')->assertInertia(fn ($page) => $page->where('scope', 'own'));
    }

    private function subscribeToInstitutionalPlan(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        app(Entitlements::class)->flush();
    }

    // ---------------------------------------------------------------------- settings

    #[Test]
    public function shared_configuration_write_access_still_follows_ownership_after_switching(): void
    {
        $teacher = User::factory()->create();
        $school = Organization::factory()->institutional()->withMember($teacher)->create();

        $this->actingAs($teacher)->post('/organizations/switch', ['organization' => $school->ulid]);

        // A member of the school, not its owner — Fatia 1's shared-config rule
        // (member reads, owner writes) still holds in the new context.
        $this->actingAs($teacher)->get('/subjects')->assertInertia(fn ($page) => $page->where('canManage', false));
        $this->actingAs($teacher)->post('/subjects', ['name' => 'Português', 'code' => 'PT7'])->assertForbidden();
    }
}
