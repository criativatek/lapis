<?php

namespace App\Policies;

use App\Models\AssessmentProfile;
use App\Models\User;

/**
 * The organization scope guarantees a teacher only sees their own profiles.
 * These add the pedagogical layer: a profile can always be viewed and a new draft
 * opened, but only one with no active version can be deleted outright.
 */
class AssessmentProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AssessmentProfile $profile): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AssessmentProfile $profile): bool
    {
        return true;
    }

    public function delete(User $user, AssessmentProfile $profile): bool
    {
        // No active version yet — still a draft-only profile. Deleting one that
        // has results behind it is a destructive, audited operation (§22.5).
        return $profile->current_version_id === null;
    }
}
