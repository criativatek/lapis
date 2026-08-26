<?php

namespace Tests\Feature\Trial;

use App\Actions\Organizations\ActivateProTrial;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Trial\TrialException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The voluntary, self-service Pro trial (§Trial): who may start it, exactly
 * what it writes, and that the once-per-account rule cannot be bypassed by a
 * second request, a second Personal organization, or a Trial that has since
 * been superseded and no longer looks "active" by date.
 *
 * Mirrors SupersedingSubscriptionsTest/SubscriptionLifecycleTest's helper
 * style (`subscriptions()`, `freeze()`) rather than inventing a new one.
 */
class ActivateProTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function plan(string $key): Plan
    {
        return Plan::where('key', $key)->firstOrFail();
    }

    /**
     * @return Collection<int, OrganizationSubscription>
     */
    protected function subscriptions(Organization $organization): Collection
    {
        return OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('plan')
            ->orderBy('id')
            ->get();
    }

    /**
     * Freezes the clock at a given instant and hands it back, so assertions
     * can compare against the exact instant the action used without racing
     * the real wall clock.
     */
    protected function freeze(CarbonInterface $at): CarbonInterface
    {
        $this->travelTo($at);

        return $at;
    }

    // ------------------------------------------------------- registration

    #[Test]
    public function a_new_personal_organization_starts_on_base_with_no_trial_anywhere(): void
    {
        $organization = User::factory()->create()->personalOrganization();

        $rows = $this->subscriptions($organization);
        $this->assertCount(1, $rows, 'no plan argument anywhere in sight — registration is untouched');
        $this->assertSame('base', $rows->first()->plan->key);
        $this->assertSame(SubscriptionStatus::Active, $rows->first()->status);

        $this->assertFalse(
            OrganizationSubscription::withoutGlobalScope('organization')
                ->where('status', SubscriptionStatus::Trial)
                ->exists(),
            'an ordinary signup must never create a Trial-status row',
        );
    }

    // --------------------------------------------------------- the happy path

    #[Test]
    public function activating_an_eligible_organization_creates_a_trial_and_a_dormant_base_fallback(): void
    {
        config(['trial.pro_days' => 30]);
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $startedAt = $this->freeze(now());
        $trial = app(ActivateProTrial::class)->activate($user, $organization->fresh());

        $this->assertSame($this->plan('pro')->getKey(), $trial->plan_id);
        $this->assertSame(SubscriptionStatus::Trial, $trial->status);
        $this->assertSame($startedAt->toDateTimeString(), $trial->starts_at->toDateTimeString());
        $this->assertSame($startedAt->copy()->addDays(30)->toDateTimeString(), $trial->ends_at->toDateTimeString());

        [$originalBase, $createdTrial, $fallback] = $this->subscriptions($organization)->all();

        // The prior in-force Base subscription is correctly superseded —
        // exactly supersede()'s already-tested behaviour for an open-ended
        // Active subscription.
        $this->assertSame('base', $originalBase->plan->key);
        $this->assertSame(SubscriptionStatus::Expired, $originalBase->status);
        $this->assertSame($startedAt->toDateTimeString(), $originalBase->ends_at->toDateTimeString());

        $this->assertTrue($createdTrial->is($trial));

        // The dormant Base fallback: identical instant to the Trial's own
        // ends_at, never recomputed, open-ended.
        $this->assertSame($this->plan('base')->getKey(), $fallback->plan_id);
        $this->assertSame(SubscriptionStatus::Active, $fallback->status);
        $this->assertSame($trial->ends_at->toDateTimeString(), $fallback->starts_at->toDateTimeString());
        $this->assertNull($fallback->ends_at);

        $this->assertCount(3, $this->subscriptions($organization));
    }

    #[Test]
    public function duration_is_genuinely_configurable(): void
    {
        config(['trial.pro_days' => 7]);
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $startedAt = $this->freeze(now());
        $trial = app(ActivateProTrial::class)->activate($user, $organization->fresh());

        $this->assertSame($startedAt->copy()->addDays(7)->toDateTimeString(), $trial->ends_at->toDateTimeString());
        $this->assertNotSame($startedAt->copy()->addDays(30)->toDateTimeString(), $trial->ends_at->toDateTimeString());
    }

    #[Test]
    public function no_billing_or_payment_table_exists_for_the_trial_to_touch(): void
    {
        // Confirmed by grep across app/ and database/migrations: no billing,
        // payment, invoice or card table/model exists anywhere in this
        // codebase (§8.2 — no payment provider in the MVP). The trial is
        // entirely a date-window change on organization_subscriptions, the
        // same table every other plan change already writes to.
        $tables = collect(Schema::getTables())->pluck('name')->map(fn (string $name): string => strtolower($name));

        foreach (['billing', 'payment', 'invoice', 'card', 'checkout'] as $needle) {
            $this->assertEmpty(
                $tables->filter(fn (string $name) => str_contains($name, $needle))->all(),
                "Unexpected billing-related table matching [{$needle}].",
            );
        }
    }

    // ------------------------------------------------- the HTTP route itself

    #[Test]
    public function the_http_route_ignores_every_unexpected_request_field(): void
    {
        config(['trial.pro_days' => 30]);
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $startedAt = $this->freeze(now());

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->post('/settings/plan/trial', [
                'plan_id' => 999999,
                'organization_id' => 999999,
                'starts_at' => now()->subYear()->toIso8601String(),
                'ends_at' => now()->addYear()->toIso8601String(),
                'days' => 999,
            ])
            ->assertRedirect(route('settings.plan.edit'));

        $trial = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('status', SubscriptionStatus::Trial)
            ->firstOrFail();

        $this->assertSame($this->plan('pro')->getKey(), $trial->plan_id);
        $this->assertSame($startedAt->toDateTimeString(), $trial->starts_at->toDateTimeString());
        $this->assertSame($startedAt->copy()->addDays(30)->toDateTimeString(), $trial->ends_at->toDateTimeString());
    }

    // ----------------------------------------------------- once per account

    #[Test]
    public function a_second_activation_attempt_is_blocked_and_creates_no_new_rows(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        app(ActivateProTrial::class)->activate($user, $organization->fresh());

        $countBefore = $this->subscriptions($organization)->count();

        try {
            app(ActivateProTrial::class)->activate($user, $organization->fresh());
            $this->fail('Expected TrialException.');
        } catch (TrialException $exception) {
            $this->assertSame('Esta conta já utilizou o período experimental Pro.', $exception->getMessage());
        }

        $this->assertSame($countBefore, $this->subscriptions($organization)->count(), 'the blocked attempt created nothing');
    }

    #[Test]
    public function a_trial_already_superseded_by_an_upgrade_still_blocks_a_new_activation(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        app(ActivateProTrial::class)->activate($user, $organization->fresh());

        // Upgraded to paid Pro almost immediately, cutting the Trial short.
        // Its status stays Trial (supersede() never relabels it) even though
        // its ends_at now looks entirely historical.
        $this->travelTo(now()->addDay());
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), $this->plan('pro'));

        $this->travelTo(now()->addYear());

        try {
            app(ActivateProTrial::class)->activate($user, $organization->fresh());
            $this->fail('Expected TrialException.');
        } catch (TrialException $exception) {
            $this->assertSame('Esta conta já utilizou o período experimental Pro.', $exception->getMessage());
        }
    }

    #[Test]
    public function a_second_personal_organization_of_the_same_owner_having_used_a_trial_before_blocks_this_one(): void
    {
        // Defensive per §4 of the trial brief: nothing in the schema stops a
        // user from someday owning a second Personal organization, so the
        // once-per-account rule must not be bypassable through one.
        $owner = User::factory()->create();
        $mainOrganization = $owner->personalOrganization();

        $otherPersonalOrganization = Organization::factory()->create(['owner_id' => $owner->id]);
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $otherPersonalOrganization->getKey(),
            'plan_id' => $this->plan('pro')->getKey(),
            'status' => SubscriptionStatus::Trial,
            'starts_at' => now()->subDays(100),
            'ends_at' => now()->subDays(70),
        ]);

        try {
            app(ActivateProTrial::class)->activate($owner, $mainOrganization->fresh());
            $this->fail('Expected TrialException.');
        } catch (TrialException $exception) {
            $this->assertSame('Esta conta já utilizou o período experimental Pro.', $exception->getMessage());
        }

        $this->assertCount(1, $this->subscriptions($mainOrganization), 'the blocked attempt created nothing on THIS organization');
    }

    /**
     * Proves the eligibility recheck reads LIVE data at call time, under the
     * lock — never a value fixed earlier (e.g. at container-resolution time).
     * True concurrent transactions are not reproducible against SQLite's
     * single in-memory connection, so this stands in for "the other request
     * already committed its Trial in the gap": the competing row is written
     * AFTER `ActivateProTrial` is resolved, and the guard still catches it,
     * because `ChangeOrganizationPlan::startProTrial()` queries
     * `TrialEligibility::usedBefore()` again, fresh, strictly after
     * `lock($organization)` — never before it.
     */
    #[Test]
    public function the_eligibility_recheck_sees_a_trial_committed_after_the_action_was_resolved(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $action = app(ActivateProTrial::class);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $this->plan('pro')->getKey(),
            'status' => SubscriptionStatus::Trial,
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
        ]);

        try {
            $action->activate($user, $organization->fresh());
            $this->fail('Expected TrialException.');
        } catch (TrialException $exception) {
            $this->assertSame('Esta conta já utilizou o período experimental Pro.', $exception->getMessage());
        }
    }

    // -------------------------------------------------------------- guards

    #[Test]
    public function an_institutional_organization_is_rejected_before_any_write(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->getKey()]);
        $organization->members()->attach($owner, ['joined_at' => now()]);

        try {
            app(ActivateProTrial::class)->activate($owner, $organization);
            $this->fail('Expected TrialException.');
        } catch (TrialException $exception) {
            $this->assertSame('Este tipo de organização não é elegível para o período experimental Pro.', $exception->getMessage());
        }

        $this->assertSame(
            0,
            OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $organization->getKey())->count(),
            'nothing was written before the guard ran',
        );
    }

    #[Test]
    public function the_http_route_rejects_a_member_of_an_institutional_organization(): void
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
            ->post('/settings/plan/trial')
            ->assertSessionHasErrors(['trial' => 'Este tipo de organização não é elegível para o período experimental Pro.']);

        $this->assertSame(
            1,
            OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $organization->getKey())->count(),
            'no new subscription row was created',
        );
    }

    #[Test]
    public function a_member_who_is_not_the_owner_is_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->personalOrganization();
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);

        try {
            app(ActivateProTrial::class)->activate($member, $organization->fresh());
            $this->fail('Expected TrialException.');
        } catch (TrialException $exception) {
            $this->assertSame('Só o responsável pela organização pode ativar o período experimental Pro.', $exception->getMessage());
        }

        $this->assertCount(1, $this->subscriptions($organization));
    }

    #[Test]
    public function the_http_route_rejects_a_member_who_is_not_the_owner(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->personalOrganization();
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->post('/settings/plan/trial')
            ->assertSessionHasErrors(['trial' => 'Só o responsável pela organização pode ativar o período experimental Pro.']);

        $this->assertCount(1, $this->subscriptions($organization));
    }

    // ------------------------------------------------------ tenant isolation

    #[Test]
    public function activating_for_one_organization_never_touches_another(): void
    {
        $userA = User::factory()->create();
        $organizationA = $userA->personalOrganization();
        $userB = User::factory()->create();
        $organizationB = $userB->personalOrganization();

        app(ActivateProTrial::class)->activate($userA, $organizationA->fresh());

        $rowsB = $this->subscriptions($organizationB);
        $this->assertCount(1, $rowsB, 'organization B is completely untouched');
        $this->assertSame('base', $rowsB->first()->plan->key);
        $this->assertSame(SubscriptionStatus::Active, $rowsB->first()->status);

        // And B's own eligibility is entirely independent of A's activation.
        $trialB = app(ActivateProTrial::class)->activate($userB, $organizationB->fresh());
        $this->assertSame(SubscriptionStatus::Trial, $trialB->status);
    }
}
