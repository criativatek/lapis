<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Support\Entitlements\Entitlements;
use Illuminate\Support\Carbon;

/**
 * Put an organization on a plan, for tests that are about something else.
 *
 * Written for the Base/Pro realignment: several capabilities moved between
 * plans, and a test whose subject is «what does the restore wizard DO» now has
 * to say which plan it is running on, or it silently asserts a 403 that came
 * from the entitlement rather than from the rule it was written for. Setting
 * the plan explicitly is what keeps those tests about their own subject.
 *
 * NOT A WAY TO SKIP THE GATE. The tests that assert the gate itself —
 * RequireModuleTest, AccessStateTest, the plan-boundary tests in
 * tests/Feature/Entitlements — never use this: they build the exact
 * subscription shape they are about, because the shape IS the subject there.
 *
 * Deletes whatever the organization already had rather than laying a row on
 * top, so there is no history for `Entitlements`' downgrade rule to read and
 * no second subscription in force — the same discipline
 * `ChangeOrganizationPlan` keeps in production, expressed the short way a test
 * fixture may.
 */
trait SubscribesOrganizations
{
    protected function subscribeOrganizationTo(Organization $organization, string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);

        app(Entitlements::class)->flush();
    }
}
