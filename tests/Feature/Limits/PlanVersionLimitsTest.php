<?php

namespace Tests\Feature\Limits;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiQuota;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Limits\LimitKey;
use App\Support\Limits\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PublishesPlanVersions;
use Tests\TestCase;

/**
 * A CAP IS PART OF WHAT WAS SOLD, SO IT IS FROZEN WITH IT.
 *
 * ADR-0008 §7 and the brief's §14. Versioning the modules while leaving
 * `plans.limits` a live column would have recreated the identical defect one
 * dimension over: publishing a smaller Base would have shrunk the turma cap of
 * every organization that had ever bought the larger one, retroactively and
 * silently. So the caps moved onto the version with the composition, and
 * `plans.limits` was dropped rather than deprecated.
 *
 * WHAT IS NOT VERSIONED, and why that is not an inconsistency: the technical
 * ceilings in `config('lapis.ai.quotas')`. Those are defaults an operator
 * tunes for the platform's own protection and they promise nothing to anybody.
 * A cap that IS a commercial promise — the 8 turmas of Base, a contracted AI
 * pool — lives on the version. The line is «was this sold to somebody», not
 * «is it a number».
 */
class PlanVersionLimitsTest extends TestCase
{
    use PublishesPlanVersions;
    use RefreshDatabase;

    #[Test]
    public function a_customer_of_an_earlier_version_keeps_its_cap_while_a_new_customer_gets_the_later_one(): void
    {
        // Two Pro versions with FINITE caps, so they differ by a number a
        // reader can follow rather than by "unlimited" — the seeded Pro is
        // unlimited on both keys, which would make the two indistinguishable.
        $this->publishNextVersionOf('pro', limits: ['active_classes' => 100, 'active_students' => 100]);

        $grandfathered = $this->organizationOn('pro');

        $this->publishNextVersionOf('pro', limits: ['active_classes' => 200, 'active_students' => 200]);

        $newcomer = $this->organizationOn('pro');

        $limits = app(Limits::class);

        $this->assertSame(100, $limits->limitFor($grandfathered->fresh(), LimitKey::ActiveClasses)->value());
        $this->assertSame(200, $limits->limitFor($newcomer->fresh(), LimitKey::ActiveClasses)->value());
    }

    #[Test]
    public function publishing_a_new_cap_does_not_move_an_existing_subscriber(): void
    {
        $organization = $this->organizationOn('base');
        $limits = app(Limits::class);

        $this->assertSame(8, $limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->value());

        $this->publishNextVersionOf('base', limits: ['active_classes' => 2, 'active_students' => 300]);

        // Nothing moved it, so nothing moved. The reduction is real for
        // whoever subscribes next, and reaches nobody already on v1.
        $this->assertSame(8, $limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->value());
        $this->assertSame(2, $this->currentVersionOf('base')->limits['active_classes']);
    }

    #[Test]
    public function moving_a_subscriber_forward_is_an_explicit_act_and_then_the_cap_follows(): void
    {
        $organization = $this->organizationOn('base');
        $limits = app(Limits::class);

        $tighter = $this->publishNextVersionOf('base', limits: ['active_classes' => 2, 'active_students' => 300]);

        app(ChangeOrganizationPlan::class)->to($organization->fresh(), $tighter);

        $this->assertSame(2, $limits->limitFor($organization->fresh(), LimitKey::ActiveClasses)->value());
    }

    #[Test]
    public function the_limit_resolver_reads_the_version_of_the_subscription_in_force(): void
    {
        $organization = $this->organizationOn('base');

        // A Pro version published AFTER the organization's Base subscription:
        // neither the newest version of another plan nor the newest version of
        // any plan may leak into this answer.
        $this->publishNextVersionOf('pro', limits: ['active_classes' => 999, 'active_students' => 999]);

        $this->assertSame(
            $this->currentVersionOf('base')->getKey(),
            app(Limits::class)->planVersionFor($organization->fresh())?->getKey(),
        );
        $this->assertSame(8, app(Limits::class)->limitFor($organization->fresh(), LimitKey::ActiveClasses)->value());
    }

    #[Test]
    public function a_contracted_ai_pool_is_frozen_on_the_version_like_any_other_cap(): void
    {
        // The AI pool is a contractual figure — «what this school's licence
        // includes» — so it is grandfathered exactly like a turma cap, and for
        // the same reason.
        $withPool = $this->publishNextVersionOf('institutional', limits: [
            ...($this->currentVersionOf('institutional')->limits ?? []),
            AiQuota::POOL_LIMIT_KEY => ['organization_monthly' => 5000],
        ]);

        $organization = $this->organizationOn('institutional');
        $this->assertSame($withPool->getKey(), app(Limits::class)->planVersionFor($organization->fresh())?->getKey());
        $this->assertSame(5000, app(AiQuota::class)->poolLimit($organization->fresh(), 'organization_monthly'));

        // A later, smaller pool reaches nobody already on the larger one.
        $this->publishNextVersionOf('institutional', limits: [
            ...($this->currentVersionOf('institutional')->limits ?? []),
            AiQuota::POOL_LIMIT_KEY => ['organization_monthly' => 100],
        ]);

        $this->assertSame(5000, app(AiQuota::class)->poolLimit($organization->fresh(), 'organization_monthly'));
    }

    #[Test]
    public function a_platform_default_quota_is_not_versioned_because_it_promises_nothing(): void
    {
        $organization = $this->organizationOn('base');

        // No version carries an `ai_quota`, so the answer comes from config —
        // and following config is the correct behaviour for a technical
        // ceiling, which is exactly what makes it NOT a candidate for
        // versioning.
        config(['lapis.ai.quotas.help_assistant.user_daily' => 7]);
        $this->assertSame(7, app(AiQuota::class)->limit($organization->fresh(), AiCapability::HelpAssistant, 'user_daily'));

        config(['lapis.ai.quotas.help_assistant.user_daily' => 9]);
        $this->assertSame(9, app(AiQuota::class)->limit($organization->fresh(), AiCapability::HelpAssistant, 'user_daily'));
    }

    private function organizationOn(string $planKey): Organization
    {
        $organization = User::factory()->create()->personalOrganization()->fresh();

        if ($planKey !== 'base') {
            app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', $planKey)->firstOrFail());
        }

        return $organization->fresh();
    }
}
