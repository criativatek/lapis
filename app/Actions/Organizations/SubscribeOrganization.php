<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SubscriptionStatus;
use Illuminate\Support\Carbon;

/**
 * Puts a brand-new organization on a plan, outright.
 *
 * Shared by every action that creates an organization — personal or
 * institutional — so the FIRST subscription of an organization that did not
 * exist a line ago is written exactly once, in exactly one place. Deliberately
 * a direct write and not ChangeOrganizationPlan: there is nothing in force yet
 * to close and nothing to serialize against. Every LATER change goes through
 * that service instead.
 *
 * ponytail: no payment provider in the MVP (§8.2), so the plan is granted
 * outright with no end date. When billing arrives, this is where a trial
 * window and a real ends_at come from.
 */
class SubscribeOrganization
{
    /**
     * A `Plan` means the version that plan sells today — the same rule
     * `ChangeOrganizationPlan::to()` applies, and the right one for an
     * organization being created right now. A `PlanVersion` is used as given,
     * for the caller that already knows which offer it is provisioning.
     */
    public function subscribe(Organization $organization, Plan|PlanVersion|null $plan): void
    {
        if ($plan === null) {
            return;
        }

        $version = $plan instanceof PlanVersion ? $plan : $plan->currentVersionOrFail();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $version->plan_id,
            'plan_version_id' => $version->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);
    }
}
