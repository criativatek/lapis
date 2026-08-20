<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;

class OrganizationMembershipPolicy
{
    public function remove(User $user, Organization $organization): bool
    {
        return $this->manages($user, $organization);
    }

    public function transferOwnership(User $user, Organization $organization): bool
    {
        return $this->manages($user, $organization);
    }

    public function viewReassignments(User $user, Organization $organization): bool
    {
        return $this->manages($user, $organization);
    }

    protected function manages(User $user, Organization $organization): bool
    {
        return $organization->type === OrganizationType::Institutional
            && $user->owns($organization);
    }
}
