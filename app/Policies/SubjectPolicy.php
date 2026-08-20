<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

/**
 * The organization scope already guarantees a teacher only sees their own
 * subjects — the school's own catalogue, shared by every class and profile in
 * it. Reading and using it is for every member; managing it — the exact "a
 * co-teacher who may view but not edit the shared catalogue" this class used
 * to describe as a placeholder — is the owner's (Fatia 1, §16 of the
 * multi-user security brief).
 */
class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->ownsCurrentOrganization();
    }

    public function update(User $user, Subject $subject): bool
    {
        return $user->ownsCurrentOrganization();
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $user->ownsCurrentOrganization();
    }
}
