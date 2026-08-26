<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ChangeOrganizationPlan::to() has always closed whatever was in force by
 * setting `ends_at` to the moment of change. That was safe while every
 * subscription in the system was either open-ended or already in the past.
 *
 * Two shapes break that assumption: a subscription created with a pre-set
 * future `ends_at` (a time-boxed trial), and one scheduled to start later
 * (`starts_at > now`, a fallback row meant to take over automatically). These
 * tests pin how `to()` classifies and rewrites each of the three shapes it
 * now has to handle — already closed, scheduled for later, and in force right
 * now — and that a Trial is never relabelled away from Trial no matter which
 * shape it is wearing.
 */
class SupersedingSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function account(): Organization
    {
        return User::factory()->create()->personalOrganization();
    }

    protected function changePlan(): ChangeOrganizationPlan
    {
        return app(ChangeOrganizationPlan::class);
    }

    protected function plan(string $key): Plan
    {
        return Plan::where('key', $key)->firstOrFail();
    }

    /**
     * Freezes the clock at a given instant and hands it back, so assertions
     * can compare against the exact instant `to()` used without racing the
     * real wall clock.
     */
    protected function freeze(CarbonInterface $at): CarbonInterface
    {
        $this->travelTo($at);

        return $at;
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

    // ---------------------------------------------------------- classic shapes

    #[Test]
    public function an_open_ended_active_subscription_is_expired_at_the_moment_of_change(): void
    {
        $organization = $this->account();

        $changedAt = $this->freeze(now()->addDay());
        $this->changePlan()->to($organization->fresh(), $this->plan('pro'));

        $base = $this->subscriptions($organization)->first();

        $this->assertSame(SubscriptionStatus::Expired, $base->status);
        $this->assertSame($changedAt->toDateTimeString(), $base->ends_at->toDateTimeString());
        $this->assertSame(1, $this->inForceCount($organization));
    }

    #[Test]
    public function an_open_ended_suspended_subscription_keeps_its_status_when_superseded(): void
    {
        $organization = $this->account();
        $this->subscriptions($organization)->first()
            ->forceFill(['status' => SubscriptionStatus::Suspended])->save();

        $changedAt = $this->freeze(now()->addDay());
        $this->changePlan()->to($organization->fresh(), $this->plan('pro'));

        $base = $this->subscriptions($organization)->first();

        $this->assertSame(SubscriptionStatus::Suspended, $base->status, 'Suspenso não vira Expirado.');
        $this->assertSame($changedAt->toDateTimeString(), $base->ends_at->toDateTimeString());
        $this->assertSame(1, $this->inForceCount($organization));
    }

    // ------------------------------------------------ (c) in force, future ends_at

    #[Test]
    public function a_trial_in_force_with_a_future_ends_at_is_cut_short_but_stays_trial(): void
    {
        $organization = $this->account();
        $this->subscriptions($organization)->first()
            ->forceFill(['status' => SubscriptionStatus::Expired, 'ends_at' => now()->subDays(5)])->save();

        $trial = OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $this->plan('pro')->getKey(),
            'status' => SubscriptionStatus::Trial,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ]);

        $this->assertSame(1, $this->inForceCount($organization), 'Cenário: só o trial em vigor.');

        $changedAt = $this->freeze(now()->addDay());
        $this->changePlan()->to($organization->fresh(), $this->plan('institutional'));

        $trial->refresh();

        $this->assertSame(SubscriptionStatus::Trial, $trial->status, 'Trial nunca é reclassificado.');
        $this->assertSame($changedAt->toDateTimeString(), $trial->ends_at->toDateTimeString());
        $this->assertSame(1, $this->inForceCount($organization));
    }

    // -------------------------------------------------- (b) scheduled, not yet in force

    #[Test]
    public function a_subscription_scheduled_in_the_future_is_collapsed_to_a_zero_width_window(): void
    {
        $organization = $this->account();
        $this->subscriptions($organization)->first()
            ->forceFill(['status' => SubscriptionStatus::Expired, 'ends_at' => now()])->save();

        $scheduled = OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $this->plan('pro')->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->addDays(10),
            'ends_at' => null,
        ]);

        $this->assertSame(0, $this->inForceCount($organization), 'Cenário: nada em vigor ainda.');

        $this->changePlan()->to($organization->fresh(), $this->plan('institutional'));

        $scheduled->refresh();

        $this->assertSame(SubscriptionStatus::Expired, $scheduled->status, 'Nunca chegou a valer; a concessão é anulada.');
        $this->assertSame(
            $scheduled->starts_at->toDateTimeString(),
            $scheduled->ends_at->toDateTimeString(),
            'Janela de largura zero: ends_at = starts_at, nunca closedAt.',
        );

        // Never in force: not before its own starts_at, not exactly at it, not after.
        $this->travelTo($scheduled->starts_at->subDay());
        $this->assertFalse($scheduled->fresh()->isInForce());

        $this->travelTo($scheduled->starts_at);
        $this->assertFalse($scheduled->fresh()->isInForce());

        $this->travelTo($scheduled->starts_at->addDay());
        $this->assertFalse($scheduled->fresh()->isInForce());
    }

    #[Test]
    public function a_scheduled_trial_collapses_the_same_way_but_keeps_its_status(): void
    {
        $organization = $this->account();
        $this->subscriptions($organization)->first()
            ->forceFill(['status' => SubscriptionStatus::Expired, 'ends_at' => now()])->save();

        $scheduled = OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $this->plan('pro')->getKey(),
            'status' => SubscriptionStatus::Trial,
            'starts_at' => now()->addDays(10),
            'ends_at' => null,
        ]);

        $this->changePlan()->to($organization->fresh(), $this->plan('institutional'));

        $scheduled->refresh();

        $this->assertSame(SubscriptionStatus::Trial, $scheduled->status, 'Defensivo: mesmo agendado, um Trial fica Trial.');
        $this->assertSame($scheduled->starts_at->toDateTimeString(), $scheduled->ends_at->toDateTimeString());
        $this->assertFalse($scheduled->fresh()->isInForce());
    }

    // --------------------------------------------------------- (a) already historical

    #[Test]
    public function an_already_historical_subscription_is_left_byte_for_byte_untouched(): void
    {
        $organization = $this->account();
        $base = $this->subscriptions($organization)->first();
        $base->forceFill(['status' => SubscriptionStatus::Expired, 'ends_at' => now()->subDays(3)])->save();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $this->plan('pro')->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(3),
        ]);

        $before = $base->fresh()->getAttributes();

        $this->changePlan()->to($organization->fresh(), $this->plan('institutional'));

        $after = $base->fresh()->getAttributes();

        $this->assertSame($before, $after, 'Uma subscrição já histórica nem sequer é gravada de novo.');
        $this->assertSame(1, $this->inForceCount($organization));
    }

    // ------------------------------------------------------- ties back to the repair command

    #[Test]
    public function a_pre_set_future_ends_at_run_through_to_twice_leaves_nothing_for_the_repair_command(): void
    {
        $organization = $this->account();
        $base = $this->subscriptions($organization)->first();

        // The exact shape RepairOverlappingSubscriptionsTest refuses to touch on
        // its own: a deliberately-set future ends_at, currently in force. Proving
        // to() never leaves that shape behind is what makes the repair command's
        // refusal irrelevant in practice.
        $base->forceFill(['starts_at' => now()->subDays(2), 'ends_at' => now()->addMonth()])->save();

        $this->assertSame(1, $this->inForceCount($organization));

        $this->changePlan()->to($organization->fresh(), $this->plan('pro'));

        $this->freeze(now()->addDay());
        $this->changePlan()->to($organization->fresh(), $this->plan('institutional'));

        $this->assertSame(1, $this->inForceCount($organization));

        $this->artisan('lapis:repair-overlapping-subscriptions')
            ->expectsOutputToContain('Nenhuma sobreposição')
            ->assertSuccessful();

        $this->artisan('lapis:repair-overlapping-subscriptions --apply')
            ->expectsOutputToContain('Nenhuma sobreposição')
            ->assertSuccessful();
    }
}
