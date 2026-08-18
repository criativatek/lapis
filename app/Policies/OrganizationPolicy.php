<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * Who may read the school's letterhead, and who may change it.
 *
 * READING IS FOR EVERYONE IN THE ORGANIZATION. A teacher building a report
 * needs the school's name and logo on it; asking permission to see the header
 * of a document they are authoring would be theatre.
 *
 * CHANGING IS THE OWNER'S. The project has no role column on memberships — the
 * only distinction the schema actually makes is `organizations.owner_id` — so
 * that is the distinction this uses, rather than inventing a permission matrix
 * this task was told not to invent (§9).
 *
 * On a personal account the two collapse into the same person, which is the
 * right answer there: the teacher IS the organization. On an institutional one
 * they separate, and a teacher who joined the school can read the identity but
 * not rewrite what goes on every colleague's documents.
 */
class OrganizationPolicy
{
    public function viewIdentity(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization);
    }

    public function updateIdentity(User $user, Organization $organization): bool
    {
        return (int) $organization->owner_id === (int) $user->getKey();
    }

    protected function belongsTo(User $user, Organization $organization): bool
    {
        return (int) $organization->owner_id === (int) $user->getKey()
            || $organization->members()->whereKey($user->getKey())->exists();
    }
}
