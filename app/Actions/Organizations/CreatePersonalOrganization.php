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
 */
class CreatePersonalOrganization
{
    public function create(User $user): Organization
    {
        $organization = Organization::create([
            'name' => $user->name,
            'type' => OrganizationType::Personal,
            'owner_id' => $user->getKey(),
        ]);

        $organization->members()->attach($user, ['joined_at' => Carbon::now()]);

        $this->subscribeToBasePlan($organization);

        return $organization;
    }

    protected function subscribeToBasePlan(Organization $organization): void
    {
        $base = Plan::where('key', 'base')->first();

        // ponytail: no payment provider in the MVP (§8.2), so Base is granted
        // outright with no end date. When billing arrives, this is where a trial
        // window and a real ends_at come from.
        if ($base === null) {
            return;
        }

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $base->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);
    }
}
