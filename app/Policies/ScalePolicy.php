<?php

namespace App\Policies;

use App\Models\Scale;
use App\Models\User;

/**
 * A system scale (organization_id null) belongs to nobody and no policy check
 * here ever opens it for writing — `!isSystem()` guards that regardless of who
 * is asking. An organization-owned scale is shared configuration exactly like
 * an assessment profile (Fatia 1, §16): every member reads it to build a
 * profile version, only the owner creates, edits or retires it.
 */
class ScalePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->ownsCurrentOrganization();
    }

    public function update(User $user, Scale $scale): bool
    {
        return ! $scale->isSystem() && $user->ownsCurrentOrganization();
    }

    public function delete(User $user, Scale $scale): bool
    {
        return ! $scale->isSystem() && $user->ownsCurrentOrganization();
    }
}
