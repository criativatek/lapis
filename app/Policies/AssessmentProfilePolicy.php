<?php

namespace App\Policies;

use App\Models\AssessmentProfile;
use App\Models\User;

/**
 * The organization scope guarantees a teacher only sees their own profiles.
 *
 * READING — and USING one to build a class, or to read results calculated
 * from it — is for every member. A colleague who did not write the profile
 * still needs it to teach.
 *
 * WRITING is the owner's (Fatia 1, §13 of the multi-user security brief).
 * There is no personal/institutional split in this schema — every profile
 * already lives at organization scope, unique per (year, subject, grade
 * level) FOR THE WHOLE ORGANIZATION, not per teacher — so a profile here is
 * always the "INSTITUTIONAL" case the brief describes, never the "PERSONAL"
 * one. Letting any member edit the rule that calculates a colleague's
 * classifications is exactly the access this fatia closes. Domains are
 * written through this same gate (ProfileBuilder, called from
 * AssessmentProfileController) and need no policy of their own.
 *
 * On a personal organization owner_id is the only user there is, so this
 * changes nothing for the case this app is mostly used in today.
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
        return $user->ownsCurrentOrganization();
    }

    public function update(User $user, AssessmentProfile $profile): bool
    {
        return $user->ownsCurrentOrganization();
    }

    public function delete(User $user, AssessmentProfile $profile): bool
    {
        // No active version yet — still a draft-only profile. Deleting one that
        // has results behind it is a destructive, audited operation (§22.5).
        return $user->ownsCurrentOrganization() && $profile->current_version_id === null;
    }
}
