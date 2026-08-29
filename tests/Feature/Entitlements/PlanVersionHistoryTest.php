<?php

namespace Tests\Feature\Entitlements;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Entitlements\AccessState;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PublishesPlanVersions;
use Tests\TestCase;

/**
 * PUBLISHING A NEW VERSION DOES NOT REWRITE THE PAST.
 *
 * The requirement ADR-0008 calls its number one, and the defect the whole lot
 * exists to close. `Entitlements::retainReadOnlyAfterDowngrade()` walks an
 * organization's HISTORICAL subscriptions to answer «what could this teacher
 * do when they wrote this?». Before this change it answered by reading
 * `$subscription->plan->modules` — the composition that plan carries TODAY —
 * so every run of the seeder silently rewrote the answer for every account
 * that had ever been on that plan. No migration, no record, nobody deciding.
 *
 * The method was born in `664c36f` and has never been in production, which is
 * why the window to fix it was open at all: the defect and its correction fit
 * in the same release, and no real history was ever interpreted through it.
 *
 * WHY THIS FILE COULD NOT HAVE EXISTED BEFORE. Its central step — «publish Pro
 * v2 without the capability» — had no meaning in the old model, where a plan
 * simply WAS its composition and changing it changed the past by construction.
 * The old-architecture equivalent, mutating the plan, is asserted here too:
 * the capability really is gone from what Pro sells today, and the
 * organization's historical reading is unmoved by that. Under the old resolver
 * the second half is exactly what would have failed.
 */
class PlanVersionHistoryTest extends TestCase
{
    use PublishesPlanVersions;
    use RefreshDatabase;

    /**
     * `lessons` is on `RetainedOnDowngrade`: it is a Pro capability that holds
     * a teacher's own historical data (sumários), so a downgrade leaves it
     * consultable rather than gone.
     */
    private const RETAINED_CAPABILITY = 'lessons';

    #[Test]
    public function a_future_composition_does_not_rewrite_the_past(): void
    {
        $organization = $this->organization();
        $plans = app(ChangeOrganizationPlan::class);

        // 1. The organization subscribes Pro v1, which includes `lessons`.
        $proV1 = $this->currentVersionOf('pro');
        $this->assertContains(self::RETAINED_CAPABILITY, $proV1->modules()->pluck('key')->all());

        $plans->to($organization, $proV1);
        $this->assertSame(AccessState::Allowed, $this->state($organization, self::RETAINED_CAPABILITY));

        // 2. It downgrades to Base. `lessons` is retained as read-only — read
        //    from the v1 it really had.
        $plans->to($organization->fresh(), Plan::where('key', 'base')->firstOrFail());
        $afterDowngrade = $this->states($organization);

        $this->assertSame(AccessState::ReadOnly, $this->state($organization, self::RETAINED_CAPABILITY));

        // 3. Pro v2 is published WITHOUT `lessons`.
        $withoutLessons = array_values(array_diff(
            $proV1->modules()->pluck('key')->all(),
            [self::RETAINED_CAPABILITY],
        ));

        $proV2 = $this->publishNextVersionOf('pro', moduleKeys: $withoutLessons);

        // The change is real: what Pro sells TODAY no longer includes it.
        // This is the half that used to reach backwards.
        $this->assertSame(2, $proV2->version);
        $this->assertNotContains(
            self::RETAINED_CAPABILITY,
            Plan::where('key', 'pro')->firstOrFail()->currentVersionOrFail()->modules()->pluck('key')->all(),
        );

        // 4. The organization's map is unmoved. Not «still allows something» —
        //    byte-for-byte the map of step 2.
        $this->assertSame($afterDowngrade, $this->states($organization));
        $this->assertSame(AccessState::ReadOnly, $this->state($organization, self::RETAINED_CAPABILITY));
    }

    #[Test]
    public function a_new_version_does_not_move_a_subscription_that_is_in_force(): void
    {
        $organization = $this->organization();
        app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', 'pro')->firstOrFail());

        $before = $this->states($organization);
        $subscription = app(ChangeOrganizationPlan::class)->inForce($organization->fresh());
        $this->assertNotNull($subscription);

        $this->publishNextVersionOf('pro', moduleKeys: ['classes', 'students']);

        // Grandfathering is the ABSENCE of an action, not a feature somebody
        // has to remember: nothing moved because nothing moved it.
        $this->assertSame($before, $this->states($organization));
        $this->assertSame(
            $subscription->plan_version_id,
            app(ChangeOrganizationPlan::class)->inForce($organization->fresh())?->plan_version_id,
        );
    }

    #[Test]
    public function a_new_customer_gets_the_new_version_while_the_old_one_keeps_v1(): void
    {
        $grandfathered = $this->organization();
        app(ChangeOrganizationPlan::class)->to($grandfathered, Plan::where('key', 'pro')->firstOrFail());

        $v2 = $this->publishNextVersionOf('pro', moduleKeys: ['classes', 'students', 'lessons']);

        $newcomer = $this->organization();
        app(ChangeOrganizationPlan::class)->to($newcomer, Plan::where('key', 'pro')->firstOrFail());

        $this->assertSame(1, app(ChangeOrganizationPlan::class)->inForce($grandfathered->fresh())?->planVersion->version);
        $this->assertSame($v2->getKey(), app(ChangeOrganizationPlan::class)->inForce($newcomer->fresh())?->plan_version_id);

        // The newcomer's map really is the smaller v2 one, and the
        // grandfathered organization's really is not.
        $this->assertSame(AccessState::Allowed, $this->state($grandfathered, 'reports'));
        $this->assertSame(AccessState::Locked, $this->state($newcomer, 'reports'));
    }

    #[Test]
    public function a_suspended_organization_is_read_only_on_its_own_version_not_on_the_current_one(): void
    {
        $organization = $this->organization();
        $plans = app(ChangeOrganizationPlan::class);

        $plans->to($organization, Plan::where('key', 'pro')->firstOrFail());
        $plans->suspend($organization->fresh());

        $suspendedMap = $this->states($organization);
        $this->assertSame(AccessState::ReadOnly, $this->state($organization, self::RETAINED_CAPABILITY));

        $this->publishNextVersionOf('pro', moduleKeys: ['classes']);

        // A suspension is a pause on WHAT WAS THERE. What is on sale today has
        // nothing to do with it.
        $this->assertSame($suspendedMap, $this->states($organization));
    }

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    /**
     * @return array<string, AccessState>
     */
    private function states(Organization $organization): array
    {
        $entitlements = app(Entitlements::class);
        $entitlements->flush();

        $states = $entitlements->accessStatesFor($organization->fresh());
        ksort($states);

        return $states;
    }

    private function state(Organization $organization, string $moduleKey): AccessState
    {
        return $this->states($organization)[$moduleKey] ?? AccessState::Locked;
    }
}
