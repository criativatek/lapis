<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
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
    public function subscribe(Organization $organization, ?Plan $plan): void
    {
        if ($plan === null) {
            return;
        }

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);
    }
}
