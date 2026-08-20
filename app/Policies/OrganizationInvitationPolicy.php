<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationType;
use App\Models\User;

/**
 * Who may see and manage a school's invitations (Fatia 3).
 *
 * Same authority the whole multi-user brief has used since Fatia 1: no role,
 * `organizations.owner_id` is the only governance this schema has. The
 * `module:institution_admin` route middleware already keeps a Base or Pro
 * organization out; this adds the two checks a plan gate cannot make —
 * whether the organization is institutional AT ALL (its type, not merely its
 * plan) and whether THIS user is its owner.
 */
class OrganizationInvitationPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->manages($user, $organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->manages($user, $organization);
    }

    public function cancel(User $user, OrganizationInvitation $invitation): bool
    {
        return $this->manages($user, $invitation->organization);
    }

    protected function manages(User $user, Organization $organization): bool
    {
        return $organization->type === OrganizationType::Institutional
            && $user->owns($organization);
    }
}
