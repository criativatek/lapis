<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

/**
 * "Only my classes" (§23, the menu's central principle). Beyond tenant isolation,
 * a teacher may only see and edit classes they are assigned to (class_teachers).
 */
class SchoolClassPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SchoolClass $class): bool
    {
        return $this->teaches($user, $class);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SchoolClass $class): bool
    {
        return $this->teaches($user, $class);
    }

    public function delete(User $user, SchoolClass $class): bool
    {
        // Only the owner, and only while nothing depends on it yet.
        return $class->teachers()
            ->wherePivot('role', 'owner')
            ->whereKey($user->getKey())
            ->exists()
            && $class->enrollments()->doesntExist();
    }

    protected function teaches(User $user, SchoolClass $class): bool
    {
        return $class->teachers()->whereKey($user->getKey())->exists();
    }
}
