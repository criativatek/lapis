<?php

namespace Tests\Feature\Trial;

use App\Actions\Organizations\ActivateProTrial;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Trial\TrialEligibility;
use App\Support\Trial\TrialException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An admin suspending a Personal organization mid-trial, via the real
 * backoffice route, must never erase the fact that a trial happened, and must
 * never let the dormant Base fallback `startProTrial()` leaves behind
 * silently take over once its `starts_at` arrives while the organization is
 * meant to stay suspended — the two bugs `ChangeOrganizationPlan::suspend()`
 * now guards against.
 *
 * Mirrors ProTrialLifecycleTest/SupersedingSubscriptionsTest's helper style
 * (`subscriptions()`, `inForceCount()`, `freeze()`) rather than inventing a
 * new one. Ordinary, non-Trial suspend/reactivate behaviour is already fully
 * pinned by SubscriptionLifecycleTest and is not duplicated here.
 */
class SuspendingAnActiveTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
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

    protected function inForceCount(Organization $organization): int
    {
        return $this->subscriptions($organization)
            ->filter(fn (OrganizationSubscription $s): bool => $s->isInForce())
            ->count();
    }

    protected function freeze(CarbonInterface $at): CarbonInterface
    {
        $this->travelTo($at);

        return $at;
    }

    #[Test]
    public function suspending_mid_trial_preserves_the_trial_row_and_repurposes_the_dormant_fallback(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $startedAt = $this->freeze(now());
        app(ActivateProTrial::class)->activate($user, $organization->fresh());

        [, $trial, $fallback] = $this->subscriptions($organization)->all();
        $originalFallbackStartsAt = $fallback->starts_at;

        $suspendedAt = $this->freeze($startedAt->copy()->addDays(5));
        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/suspend")->assertRedirect();

        $trial->refresh();
        $fallback->refresh();

        // The historical fact must survive forever: never relabelled away
        // from Trial, only its own window cut short at the suspension instant.
        $this->assertSame(SubscriptionStatus::Trial, $trial->status);
        $this->assertSame($suspendedAt->toDateTimeString(), $trial->ends_at->toDateTimeString());

        // The dormant Base fallback is repurposed into the exact placeholder
        // reactivate() already resumes, not left scheduled for its original
        // far-future date.
        $this->assertSame(SubscriptionStatus::Suspended, $fallback->status);
        $this->assertSame($suspendedAt->toDateTimeString(), $fallback->starts_at->toDateTimeString());
        $this->assertNotSame($originalFallbackStartsAt->toDateTimeString(), $fallback->starts_at->toDateTimeString());
        $this->assertNull($fallback->ends_at);

        $this->assertSame(0, $this->inForceCount($organization));
        $this->assertTrue(app(TrialEligibility::class)->usedBefore($organization->fresh()));
    }

    #[Test]
    public function the_dormant_fallback_no_longer_silently_revives_the_organization_once_suspended(): void
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $startedAt = $this->freeze(now());
        $trial = app(ActivateProTrial::class)->activate($user, $organization->fresh());

        $this->freeze($startedAt->copy()->addDays(5));
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/suspend");

        $this->assertSame(0, $this->inForceCount($organization));

        // Travel past what would have been the original trial's ends_at,
        // without ever reactivating. Before the fix, the dormant fallback's
        // starts_at — still the far-future original date — would have
        // arrived and silently granted Base access again, with nobody
        // touching anything.
        $this->travelTo($trial->ends_at->copy()->addDay());

        $this->assertSame(0, $this->inForceCount($organization), 'suspension must not be silently undone by the calendar');
    }

    #[Test]
    public function reactivating_after_a_mid_trial_suspension_restores_base_and_leaves_the_trial_historical(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $startedAt = $this->freeze(now());
        app(ActivateProTrial::class)->activate($user, $organization->fresh());

        $suspendedAt = $this->freeze($startedAt->copy()->addDays(5));
        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/suspend");

        [, $trialAfterSuspension, $fallbackAfterSuspension] = $this->subscriptions($organization)->all();
        $trialSnapshot = $trialAfterSuspension->getAttributes();

        $this->freeze($suspendedAt->copy()->addDay());
        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/reactivate")->assertRedirect();

        $inForce = $this->subscriptions($organization)->filter(fn (OrganizationSubscription $s): bool => $s->isInForce());
        $this->assertCount(1, $inForce);
        $this->assertSame('base', $inForce->first()->plan->key);
        $this->assertSame(SubscriptionStatus::Active, $inForce->first()->status);
        $this->assertTrue(
            $inForce->first()->is($fallbackAfterSuspension->fresh()),
            'reactivate() resumes the exact row suspend() repurposed, never an indefinite Pro',
        );

        // Untouched by reactivation: still Trial, dates exactly as they were
        // right after the suspension.
        $trialAfterSuspension->refresh();
        $this->assertSame($trialSnapshot, $trialAfterSuspension->getAttributes());

        $this->assertTrue(app(TrialEligibility::class)->usedBefore($organization->fresh()));

        try {
            app(ActivateProTrial::class)->activate($user, $organization->fresh());
            $this->fail('Expected TrialException.');
        } catch (TrialException $exception) {
            $this->assertSame('Esta conta já utilizou o período experimental Pro.', $exception->getMessage());
        }
    }

    #[Test]
    public function no_instant_across_the_whole_suspend_then_reactivate_sequence_ever_has_more_than_one_in_force(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $startedAt = $this->freeze(now());
        $trial = app(ActivateProTrial::class)->activate($user, $organization->fresh());
        $this->assertLessThanOrEqual(1, $this->inForceCount($organization), 'right after starting the trial');

        $this->freeze($startedAt->copy()->addDays(3));
        $this->assertLessThanOrEqual(1, $this->inForceCount($organization), 'mid-trial');

        $suspendedAt = $this->freeze($startedAt->copy()->addDays(5));
        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/suspend");
        $this->assertLessThanOrEqual(1, $this->inForceCount($organization), 'immediately after suspension');

        // Past what would have been the original trial's own expiry, still
        // suspended, never reactivated.
        $this->freeze($trial->ends_at->copy()->addDay());
        $this->assertLessThanOrEqual(1, $this->inForceCount($organization), 'past the original trial end, while suspended');

        $this->freeze($suspendedAt->copy()->addDays(20));
        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/reactivate");
        $this->assertLessThanOrEqual(1, $this->inForceCount($organization), 'immediately after reactivation');

        $this->travelTo(now()->addYear());
        $this->assertLessThanOrEqual(1, $this->inForceCount($organization), 'long after reactivation');
    }

    #[Test]
    public function a_trial_scheduled_in_the_future_collapses_to_zero_width_when_suspended_defensively(): void
    {
        // Cannot happen through the real flow today — trials always start
        // immediately — but suspend() has to handle it the same defensive way
        // supersede()'s own scheduled bucket does, and not count it as a real
        // suspension.
        $organization = User::factory()->create()->personalOrganization();

        $scheduled = OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Trial,
            'starts_at' => now()->addDays(10),
            'ends_at' => null,
        ]);

        $suspended = app(ChangeOrganizationPlan::class)->suspend($organization->fresh());

        $scheduled->refresh();

        $this->assertSame(SubscriptionStatus::Trial, $scheduled->status, 'a Trial is never relabelled, even a scheduled one');
        $this->assertSame($scheduled->starts_at->toDateTimeString(), $scheduled->ends_at->toDateTimeString());
        $this->assertFalse($scheduled->fresh()->isInForce());

        // Only the organization's own real subscription (Base) was suspended,
        // not the never-in-force scheduled Trial. "Not counted" here means
        // exactly that — excluded from suspend()'s own $suspended tally, the
        // count the admin controller uses to decide whether to log an audit
        // event. It does NOT mean excluded from trial history: `status` was
        // never touched above, so TrialEligibility::usedBefore() must still
        // see this row and report true, collapsed or not.
        $this->assertSame(1, $suspended);
        $this->assertTrue(app(TrialEligibility::class)->usedBefore($organization->fresh()));
    }
}
