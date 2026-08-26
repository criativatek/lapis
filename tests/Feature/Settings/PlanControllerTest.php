<?php

namespace Tests\Feature\Settings;

use App\Actions\Organizations\ActivateProTrial;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `PlanController::edit()`'s `state` derivation — the CONTRACT
 * `resources/js/pages/settings/Plan.vue` renders off (§Trial).
 *
 * The bug this hotfix exists for lived exactly here: `state` used to key its
 * `'institutional'` branch off `$organization->type`, not off the IN-FORCE
 * PLAN's key. Two real Personal-type organizations administratively put on
 * the Institutional plan fell through every branch and landed on the
 * default, `'eligible'`, incorrectly offering the trial button on top of an
 * administrative assignment. These tests pin the corrected rule: the plan
 * actually in force decides `'institutional'`/`'pro_active'`, never the
 * organization's own type.
 */
class PlanControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function plan(string $key): Plan
    {
        return Plan::where('key', $key)->firstOrFail();
    }

    #[Test]
    public function a_personal_organization_on_base_that_never_trialed_is_eligible(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->get('/settings/plan')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'eligible'));
    }

    #[Test]
    public function a_personal_organization_on_paid_pro_is_pro_active_not_eligible(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), $this->plan('pro'));

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->get('/settings/plan')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'pro_active')
                ->where('currentPlanName', $this->plan('pro')->name));
    }

    /**
     * The exact production shape this hotfix exists for: a Personal-type
     * organization an operator put on the Institutional plan. Before the
     * fix, `state` checked `$organization->type` here, which is `Personal`
     * for this organization, so it fell through to `'eligible'`.
     */
    #[Test]
    public function a_personal_organization_administratively_put_on_institutional_reads_as_institutional_not_eligible(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), $this->plan('institutional'));

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->get('/settings/plan')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'institutional')
                ->where('currentPlanName', $this->plan('institutional')->name));
    }

    /**
     * Not a regression: an actual Institutional-TYPE organization is
     * normally also on the institutional PLAN, so keying the state off the
     * plan rather than the type must still read 'institutional' for it —
     * confirmed here rather than assumed.
     */
    #[Test]
    public function an_institutional_type_organization_on_the_institutional_plan_still_reads_as_institutional(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->getKey()]);
        $organization->members()->attach($owner, ['joined_at' => now()]);
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $this->plan('institutional')->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
        ]);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get('/settings/plan')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'institutional'));
    }

    #[Test]
    public function an_active_trial_reads_as_trial_active(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        app(ActivateProTrial::class)->activate($user, $organization->fresh());

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->get('/settings/plan')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'trial_active'));
    }

    #[Test]
    public function an_organization_that_already_used_its_trial_and_is_back_on_base_reads_as_trial_expired(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        $trial = app(ActivateProTrial::class)->activate($user, $organization->fresh());
        $this->travelTo($trial->ends_at);

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->get('/settings/plan')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'trial_expired'));
    }

    /**
     * The new default branch: nothing in force to offer a trial against, and
     * the organization never used one before — so it is neither 'eligible'
     * (which the pre-fix code incorrectly defaulted to) nor 'trial_expired'.
     */
    #[Test]
    public function a_suspended_personal_organization_reads_as_unavailable_not_eligible(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->first()
            ->forceFill(['status' => SubscriptionStatus::Suspended])
            ->save();

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->get('/settings/plan')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'unavailable')
                ->where('currentPlanName', null));
    }

    #[Test]
    public function a_personal_organization_with_no_subscription_at_all_reads_as_unavailable_not_eligible(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $organization->getKey())->delete();

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->get('/settings/plan')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'unavailable')
                ->where('currentPlanName', null));
    }
}
