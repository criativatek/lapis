<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

/**
 * The organization scope already guarantees a teacher only sees their own
 * subjects. These are placeholders for when institutional roles narrow this
 * further (a co-teacher who may view but not edit the shared catalogue).
 */
class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Subject $subject): bool
    {
        return true;
    }

    public function delete(User $user, Subject $subject): bool
    {
        return true;
    }
}
