<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One subscription in force at a time.
 *
 * An organization's history may hold as many subscriptions as it has had plans —
 * that is what `starts_at`/`ends_at` are for. What may never happen is two of
 * them being in force at the same instant, and until this was written that was
 * exactly what provisioning an account on a non-Base plan produced: the Base
 * subscription created with the organization, plus the chosen one on top, both
 * Active, both open-ended.
 *
 * `Entitlements` resolved it deterministically — newest wins — so nobody was
 * ever given the wrong plan, and the defect stayed invisible. What it did NOT
 * survive was suspension: suspending the newest one let the older Base
 * underneath quietly become effective again, so «suspended» in the backoffice
 * meant «downgraded to Base» in the product. That is the bug these tests pin.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    protected function account(): Organization
    {
        return User::factory()->create()->personalOrganization();
    }

    /**
     * @return Collection<int, OrganizationSubscription>
     */
    protected function subscriptions(Organization $organization)
    {
        return OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('plan')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<string> plan keys of every subscription currently in force
     */
    protected function inForce(Organization $organization): array
    {
        return $this->subscriptions($organization)
            ->filter(fn (OrganizationSubscription $subscription): bool => $subscription->isInForce())
            ->map(fn (OrganizationSubscription $subscription): string => $subscription->plan->key)
            ->values()
            ->all();
    }

    protected function effectivePlan(Organization $organization): ?string
    {
        $entitlements = app(Entitlements::class);
        $entitlements->flush();

        // Through the module list, which is what actually reaches a teacher.
        $modules = $entitlements->modulesFor($organization->fresh());

        if ($modules === []) {
            return null;
        }

        foreach (Plan::with('currentVersion.modules')->orderByDesc('id')->get() as $plan) {
            if (($plan->currentVersion?->modules->count() ?? -1) === count($modules)) {
                return $plan->key;
            }
        }

        return null;
    }

    // ------------------------------------------- A. provisioning (§3.A, §27)

    #[Test]
    public function provisioning_on_institutional_leaves_exactly_one_subscription_in_force(): void
    {
        $this->actingAs($this->admin())->post('/admin/accounts', [
            'name' => 'Escola Nova',
            'email' => 'escola@exemplo.invalido',
            'password' => 'segredo-forte-1234',
            'plan_key' => 'institutional',
        ])->assertRedirect();

        $organization = User::where('email', 'escola@exemplo.invalido')->firstOrFail()->personalOrganization();

        // The defect, exactly: Base created with the organization and
        // Institutional laid on top, both Active and both open-ended.
        $this->assertSame(['institutional'], $this->inForce($organization));
        $this->assertSame('institutional', $this->effectivePlan($organization));
    }

    #[Test]
    public function provisioning_on_pro_leaves_exactly_one_subscription_in_force(): void
    {
        $this->actingAs($this->admin())->post('/admin/accounts', [
            'name' => 'Professor Pro',
            'email' => 'pro@exemplo.invalido',
            'password' => 'segredo-forte-1234',
            'plan_key' => 'pro',
        ])->assertRedirect();

        $organization = User::where('email', 'pro@exemplo.invalido')->firstOrFail()->personalOrganization();

        $this->assertSame(['pro'], $this->inForce($organization));
    }

    #[Test]
    public function provisioning_on_base_still_leaves_one_and_only_one(): void
    {
        $this->actingAs($this->admin())->post('/admin/accounts', [
            'name' => 'Professor Base',
            'email' => 'base@exemplo.invalido',
            'password' => 'segredo-forte-1234',
            'plan_key' => 'base',
        ])->assertRedirect();

        $organization = User::where('email', 'base@exemplo.invalido')->firstOrFail()->personalOrganization();

        $this->assertSame(['base'], $this->inForce($organization));
        $this->assertCount(1, $this->subscriptions($organization), 'Base não pode nascer duplicada.');
    }

    #[Test]
    public function an_ordinary_signup_still_starts_on_base(): void
    {
        // The default path must not change: a teacher who registers gets Base,
        // one subscription, no plan argument anywhere in sight.
        $organization = $this->account();

        $this->assertSame(['base'], $this->inForce($organization));
        $this->assertCount(1, $this->subscriptions($organization));
    }

    // --------------------------------------------- B, C. plan changes (§3.B/C)

    #[Test]
    public function changing_from_base_to_pro_closes_base_and_keeps_it_in_the_history(): void
    {
        $organization = $this->account();

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro'])->assertRedirect();

        $this->assertSame(['pro'], $this->inForce($organization));
        $this->assertSame('pro', $this->effectivePlan($organization));

        $all = $this->subscriptions($organization);
        $this->assertCount(2, $all, 'A subscrição anterior fica no histórico.');

        $base = $all->first();
        $this->assertSame('base', $base->plan->key);
        $this->assertSame(SubscriptionStatus::Expired, $base->status);
        $this->assertNotNull($base->ends_at, 'Uma subscrição encerrada tem de dizer quando.');
    }

    #[Test]
    public function changing_from_pro_to_institutional_leaves_only_institutional_in_force(): void
    {
        $organization = $this->account();

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro']);
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'institutional']);

        $this->assertSame(['institutional'], $this->inForce($organization));
        $this->assertSame('institutional', $this->effectivePlan($organization));
        $this->assertCount(3, $this->subscriptions($organization));
    }

    #[Test]
    public function a_closed_subscription_ends_exactly_where_the_next_one_starts(): void
    {
        $organization = $this->account();

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro']);

        [$base, $pro] = $this->subscriptions($organization)->all();

        // No gap and no overlap: the history is continuous, so «what was this
        // organization on, on that day?» has exactly one answer for every day.
        $this->assertSame($pro->starts_at->toDateTimeString(), $base->ends_at->toDateTimeString());
    }

    // ------------------------------------------------------ D. suspension

    #[Test]
    public function suspending_removes_access_entirely_and_no_older_plan_resurfaces(): void
    {
        $organization = $this->account();
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'institutional']);

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/suspend")->assertRedirect();

        // The whole point: nothing grants access afterwards. Before this fix the
        // Base subscription underneath came back and the organization silently
        // became a Base account instead of a suspended one.
        $this->assertSame([], $this->inForce($organization));
        $this->assertNull($this->effectivePlan($organization));

        $entitlements = app(Entitlements::class);
        $entitlements->flush();
        $this->assertSame([], $entitlements->modulesFor($organization->fresh()));
    }

    #[Test]
    public function suspending_an_organization_that_already_has_overlapping_rows_still_removes_access(): void
    {
        // Defensive: whatever historical mess exists, suspension has to end with
        // nothing granting access (§8).
        $organization = $this->account();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        $this->assertCount(2, $this->inForce($organization), 'Cenário: duas em vigor.');

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/suspend")->assertRedirect();

        $this->assertSame([], $this->inForce($organization));
    }

    #[Test]
    public function suspending_does_not_rewrite_subscriptions_that_were_already_closed(): void
    {
        $organization = $this->account();
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro']);

        $base = $this->subscriptions($organization)->first();
        $endedAt = $base->ends_at->toDateTimeString();

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/suspend");

        $base->refresh();

        // An expired subscription stays expired. Suspension is about what is in
        // force, not about rewriting the past (§8).
        $this->assertSame(SubscriptionStatus::Expired, $base->status);
        $this->assertSame($endedAt, $base->ends_at->toDateTimeString());
    }

    #[Test]
    public function reactivating_restores_the_same_plan_and_only_that_plan(): void
    {
        $organization = $this->account();
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'institutional']);
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/suspend");

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/reactivate")->assertRedirect();

        $this->assertSame(['institutional'], $this->inForce($organization));
        $this->assertSame('institutional', $this->effectivePlan($organization));
    }

    // -------------------------------------------------- E. the full sequence

    #[Test]
    public function base_to_pro_to_institutional_to_suspended_never_has_two_in_force(): void
    {
        $organization = $this->account();
        $this->assertLessThanOrEqual(1, count($this->inForce($organization)));
        $this->assertSame(['base'], $this->inForce($organization));

        foreach (['pro', 'institutional'] as $planKey) {
            $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => $planKey]);

            $this->assertSame([$planKey], $this->inForce($organization), "Depois de mudar para {$planKey}.");
            $this->assertSame($planKey, $this->effectivePlan($organization));
        }

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/suspend");
        $this->assertSame([], $this->inForce($organization));

        // G. And the whole history survived: three subscriptions, three plans,
        // in the order they happened.
        $history = $this->subscriptions($organization);
        $this->assertSame(['base', 'pro', 'institutional'], $history->map(fn ($s) => $s->plan->key)->all());
        $this->assertSame(
            [SubscriptionStatus::Expired, SubscriptionStatus::Expired, SubscriptionStatus::Suspended],
            $history->map(fn ($s) => $s->status)->all(),
        );

        foreach ($history->take(2) as $closed) {
            $this->assertNotNull($closed->ends_at);
        }
    }

    // ------------------------------------------------ §5. asking for the same plan

    #[Test]
    public function asking_for_the_plan_already_in_force_changes_nothing(): void
    {
        $organization = $this->account();
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro']);

        $before = $this->subscriptions($organization)->map(fn ($s) => [$s->id, $s->status->value, $s->starts_at->toDateTimeString()])->all();

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro'])->assertRedirect();

        $after = $this->subscriptions($organization)->map(fn ($s) => [$s->id, $s->status->value, $s->starts_at->toDateTimeString()])->all();

        // No new row, no closed row: there is no billing period to renew in this
        // model, so re-stating the current plan is a no-op rather than a churn
        // of identical subscriptions (§5).
        $this->assertSame($before, $after);
        $this->assertSame(['pro'], $this->inForce($organization));
    }

    #[Test]
    public function asking_for_the_current_plan_while_suspended_does_reactivate_it(): void
    {
        $organization = $this->account();
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro']);
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/suspend");

        // Not a no-op: nothing is in force, so «put them on Pro» is a real
        // instruction and has to be carried out.
        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro']);

        $this->assertSame(['pro'], $this->inForce($organization));
    }

    // ------------------------------------------------------ the invariant itself

    #[Test]
    public function no_sequence_of_backoffice_actions_leaves_two_subscriptions_in_force(): void
    {
        $organization = $this->account();

        $acoes = [
            ['plan', 'pro'], ['plan', 'institutional'], ['suspend', null], ['reactivate', null],
            ['plan', 'base'], ['plan', 'base'], ['suspend', null], ['plan', 'institutional'],
            ['reactivate', null], ['plan', 'pro'],
        ];

        foreach ($acoes as [$acao, $planKey]) {
            $acao === 'plan'
                ? $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => $planKey])
                : $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/{$acao}");

            $this->assertLessThanOrEqual(
                1,
                count($this->inForce($organization)),
                "Duas subscrições em vigor depois de «{$acao} {$planKey}».",
            );
        }

        // And nothing was thrown away along the way.
        $this->assertGreaterThanOrEqual(4, $this->subscriptions($organization)->count());
    }
}
