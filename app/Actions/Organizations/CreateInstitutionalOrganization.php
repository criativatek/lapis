<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gives a school its workspace, with a named owner from the first instant.
 *
 * THE INVARIANT THIS EXISTS TO PROTECT (Fatia 2, found while auditing Fatia 1):
 * ResolveOrganization resolves a tenant from `organization_memberships`, never
 * from `owner_id`. An owner who is not also a member can never have this
 * organization resolved as their own — so owner_id and the membership are
 * written together, in one transaction, and neither is optional. This mirrors
 * CreatePersonalOrganization exactly, for exactly the same reason.
 *
 * The subscription goes through SubscribeOrganization, the same collaborator
 * CreatePersonalOrganization uses — the FIRST subscription of a brand-new
 * organization is written in exactly one place, whichever kind of organization
 * it is.
 */
class CreateInstitutionalOrganization
{
    public function __construct(protected SubscribeOrganization $subscribe) {}

    public function create(string $name, User $owner, ?Plan $initialPlan = null): Organization
    {
        return DB::transaction(function () use ($name, $owner, $initialPlan): Organization {
            $organization = Organization::create([
                'name' => $name,
                'type' => OrganizationType::Institutional,
                'owner_id' => $owner->getKey(),
            ]);

            $organization->members()->attach($owner, ['joined_at' => Carbon::now()]);

            $this->subscribe->subscribe($organization, $initialPlan ?? Plan::where('key', 'institutional')->first());

            return $organization;
        });
    }
}
