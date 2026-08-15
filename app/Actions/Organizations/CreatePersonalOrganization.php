<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\OrganizationType;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Gives a newly registered teacher their own workspace, on the Base plan.
 *
 * Every account owns exactly one personal organization, created at registration,
 * so there is never an authenticated user without a tenant to resolve. The
 * subscription is created with it for the same reason: an organization with no
 * subscription is entitled to nothing and every module would 403.
 *
 * The plan is an argument, defaulting to Base, and that default is what an
 * ordinary signup uses. It exists because provisioning from the backoffice used
 * to create the Base subscription here and then lay the chosen plan on top of
 * it, leaving two Active, open-ended subscriptions on an organization one second
 * old. An organization whose plan is known at creation is created ON that plan;
 * nothing is written in order to be expired a moment later.
 */
class CreatePersonalOrganization
{
    public function create(User $user, ?Plan $initialPlan = null): Organization
    {
        $organization = Organization::create([
            'name' => $user->name,
            'type' => OrganizationType::Personal,
            'owner_id' => $user->getKey(),
        ]);

        $organization->members()->attach($user, ['joined_at' => Carbon::now()]);

        $this->subscribe($organization, $initialPlan ?? Plan::where('key', 'base')->first());

        return $organization;
    }

    protected function subscribe(Organization $organization, ?Plan $plan): void
    {
        // ponytail: no payment provider in the MVP (§8.2), so the plan is granted
        // outright with no end date. When billing arrives, this is where a trial
        // window and a real ends_at come from.
        if ($plan === null) {
            return;
        }

        // Deliberately a direct write and not ChangeOrganizationPlan: this is the
        // FIRST subscription of an organization that did not exist a line ago, so
        // there is nothing in force to close and nothing to serialize against.
        // Every LATER change goes through the service.
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);
    }
}
