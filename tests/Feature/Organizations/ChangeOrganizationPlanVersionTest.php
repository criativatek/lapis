<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\ActivateProTrial;
use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Actions\Organizations\CreatePersonalOrganization;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PublishesPlanVersions;
use Tests\TestCase;

/**
 * `to(Plan|PlanVersion)`, AND THE AMBIGUITY IT MUST NOT HAVE.
 *
 * ADR-0008 §6. Handed a plan, `ChangeOrganizationPlan` sells what that plan
 * sells today; handed a version, it uses that one. The half that needs its own
 * assertions is the no-op rule: it compares VERSIONS, not plans, so an
 * operator moving an organization from Pro v1 to «Pro» while v2 is published
 * really migrates it. Comparing `plan_id` would have made that a silent no-op
 * and left the operator believing they had moved somebody they had not — the
 * failure mode this file exists to exclude.
 *
 * It also covers §18 of the brief: EVERY path that creates a subscription —
 * registration, an operator's provisioning, a trial, an upgrade, a downgrade —
 * ends with a real, coherent `plan_version_id`. Not by convention: the model
 * cannot construct a row without one.
 */
class ChangeOrganizationPlanVersionTest extends TestCase
{
    use PublishesPlanVersions;
    use RefreshDatabase;

    // ------------------------------------------------ to(Plan) / to(Version)

    #[Test]
    public function passing_a_plan_contracts_the_version_that_plan_sells_today(): void
    {
        $organization = $this->organization();

        $v2 = $this->publishNextVersionOf('pro');

        $subscription = app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', 'pro')->firstOrFail());

        $this->assertSame($v2->getKey(), $subscription->plan_version_id);
        $this->assertSame($v2->plan_id, $subscription->plan_id);
    }

    #[Test]
    public function passing_a_version_contracts_exactly_that_version_even_when_a_newer_one_exists(): void
    {
        $organization = $this->organization();

        $v1 = $this->currentVersionOf('pro');
        $this->publishNextVersionOf('pro');

        $subscription = app(ChangeOrganizationPlan::class)->to($organization, $v1);

        $this->assertSame($v1->getKey(), $subscription->plan_version_id);
        $this->assertSame(1, $subscription->planVersion->version);
    }

    #[Test]
    public function asking_for_the_plan_while_a_newer_version_exists_is_a_migration_and_not_a_no_op(): void
    {
        $organization = $this->organization();
        $plans = app(ChangeOrganizationPlan::class);

        $v1 = $this->currentVersionOf('pro');
        $first = $plans->to($organization, $v1);

        $v2 = $this->publishNextVersionOf('pro');

        $second = $plans->to($organization->fresh(), Plan::where('key', 'pro')->firstOrFail());

        // A new row, on the new version, with the old one closed at the same
        // instant: continuous history, and an operator who can see that the
        // migration happened.
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame($v2->getKey(), $second->plan_version_id);
        $this->assertSame(SubscriptionStatus::Expired, $first->fresh()->status);
        $this->assertNotNull($first->fresh()->ends_at);
        $this->assertEquals($first->fresh()->ends_at, $second->starts_at);
    }

    #[Test]
    public function asking_for_the_exact_version_already_in_force_is_still_a_no_op(): void
    {
        $organization = $this->organization();
        $plans = app(ChangeOrganizationPlan::class);

        $v1 = $this->currentVersionOf('pro');
        $first = $plans->to($organization, $v1);

        $again = $plans->to($organization->fresh(), $v1);

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame(1, $this->subscriptionCount($organization) - $this->closedCount($organization));
    }

    #[Test]
    public function a_plan_with_nothing_published_refuses_the_sale_instead_of_selling_nothing(): void
    {
        $organization = $this->organization();
        $empty = Plan::create(['key' => 'vazio', 'name' => 'Vazio']);

        $this->expectException(\RuntimeException::class);

        app(ChangeOrganizationPlan::class)->to($organization, $empty);
    }

    #[Test]
    public function the_admin_plan_change_is_the_only_production_caller_and_it_means_to_migrate(): void
    {
        // THE ONE `to(Plan)` IN app/. An operator submitting the plan form is
        // making a deliberate choice, so resolving the current version is the
        // right reading: picking «Pro» for an account on Pro v1 while v2 is
        // published moves it to v2, which is what the operator asked for.
        //
        // This test exists because the OPPOSITE intent — calling `to($same)` as
        // an idempotent «make sure it is on Pro» — would silently migrate
        // grandfathered subscribers. No caller does that today, and this pins
        // what the one that exists means, so a future «ensure» helper written
        // on top of it fails here rather than in production.
        $organization = $this->organization();
        $v1 = $this->currentVersionOf('pro');
        app(ChangeOrganizationPlan::class)->to($organization, $v1);

        $v2 = $this->publishNextVersionOf('pro');

        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)
            ->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro'])
            ->assertRedirect();

        $inForce = app(ChangeOrganizationPlan::class)->inForce($organization->fresh());

        $this->assertSame($v2->getKey(), $inForce?->plan_version_id, 'the operator asked for Pro and got the Pro on sale');
        $this->assertSame(2, $inForce?->planVersion->version);

        // History stays continuous: the v1 row is closed, not rewritten.
        $closed = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('plan_version_id', $v1->getKey())
            ->firstOrFail();

        $this->assertNotNull($closed->ends_at);
        $this->assertSame(SubscriptionStatus::Expired, $closed->status);
    }

    // ------------------------------------------------------ the pair agrees

    #[Test]
    public function the_two_columns_can_never_name_different_plans(): void
    {
        $organization = $this->organization();
        $proVersion = $this->currentVersionOf('pro');
        $baseId = Plan::where('key', 'base')->firstOrFail()->getKey();

        $this->expectException(LogicException::class);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $baseId,
            'plan_version_id' => $proVersion->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
        ]);
    }

    #[Test]
    public function the_database_itself_refuses_a_mismatched_pair_with_no_php_involved(): void
    {
        // The model guard above is the readable error; THIS is the guarantee.
        // A raw insert bypasses every model event, and the composite foreign
        // key on `(plan_version_id, plan_id)` into `plan_versions (id,
        // plan_id)` still refuses the row — which is what «garantido pela base
        // de dados e não por convenção» has to mean.
        $organization = $this->organization();
        $proVersion = $this->currentVersionOf('pro');
        $baseId = Plan::where('key', 'base')->firstOrFail()->getKey();

        $this->expectException(QueryException::class);

        DB::table('organization_subscriptions')->insert([
            'organization_id' => $organization->getKey(),
            'plan_id' => $baseId,
            'plan_version_id' => $proVersion->getKey(),
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function naming_only_a_version_fills_in_the_plan(): void
    {
        $organization = $this->organization();
        $version = $this->currentVersionOf('institutional');

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->delete();

        $subscription = OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_version_id' => $version->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
        ]);

        $this->assertSame($version->plan_id, $subscription->plan_id);
    }

    // ---------------------------------------- §18: every creation path binds

    #[Test]
    public function every_path_that_creates_a_subscription_binds_a_real_version(): void
    {
        $plans = app(ChangeOrganizationPlan::class);

        // Registration.
        $owner = User::factory()->create();

        // An operator provisioning straight onto a chosen plan.
        $another = User::factory()->withoutOrganization()->create();
        app(CreatePersonalOrganization::class)->create($another, Plan::where('key', 'pro')->firstOrFail());

        // An institutional account.
        app(CreateInstitutionalOrganization::class)->create('Escola X', User::factory()->withoutOrganization()->create());

        // A trial, with its dormant fallback.
        $trialer = User::factory()->create();
        app(ActivateProTrial::class)->activate($trialer, $trialer->personalOrganization()->fresh());

        // An upgrade and a downgrade.
        $mover = $this->organization();
        $plans->to($mover, Plan::where('key', 'pro')->firstOrFail());
        $plans->to($mover->fresh(), Plan::where('key', 'base')->firstOrFail());

        $subscriptions = OrganizationSubscription::withoutGlobalScope('organization')->get();

        $this->assertGreaterThanOrEqual(8, $subscriptions->count());

        foreach ($subscriptions as $subscription) {
            $this->assertNotNull($subscription->plan_version_id, "subscription {$subscription->id} has no contracted version");
            $this->assertSame($subscription->plan_id, $subscription->planVersion->plan_id);
        }

        $this->assertNotNull($owner->personalOrganization());
    }

    // --------------------------------------------------------------- trials

    #[Test]
    public function the_trial_takes_the_current_pro_and_the_fallback_takes_the_current_base(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization()->fresh();

        $proNow = $this->currentVersionOf('pro');
        $baseNow = $this->currentVersionOf('base');

        $trial = app(ActivateProTrial::class)->activate($user, $organization);

        $this->assertSame($proNow->getKey(), $trial->plan_version_id);

        $fallback = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('starts_at', $trial->ends_at)
            ->firstOrFail();

        $this->assertSame($baseNow->getKey(), $fallback->plan_version_id);
    }

    #[Test]
    public function the_dormant_fallback_keeps_the_base_it_was_promised_even_if_base_changes_during_the_trial(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization()->fresh();

        $baseAtStart = $this->currentVersionOf('base');
        $trial = app(ActivateProTrial::class)->activate($user, $organization);

        // Base changes mid-trial. Nothing has to «wake up» to notice, which is
        // exactly the property the dormant row exists to have — and the
        // organization lands on the Base it was promised, not on a smaller one
        // published while it was not looking.
        $this->publishNextVersionOf('base', moduleKeys: ['classes']);

        $fallback = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('starts_at', $trial->ends_at)
            ->firstOrFail();

        $this->assertSame($baseAtStart->getKey(), $fallback->plan_version_id);
    }

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    private function subscriptionCount(Organization $organization): int
    {
        return OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->count();
    }

    private function closedCount(Organization $organization): int
    {
        return OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('ends_at')
            ->count();
    }
}
