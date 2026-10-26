<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationType;
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

    /**
     * The «Retificações» page of one class: the head of the INSTITUTION that owns
     * the class, whether or not they teach it. Read from the class's own
     * organization, never the user's current one; a personal account has no head.
     * Deliberately not `view`, which stays «only my classes». What may then be
     * rectified is still the classification's policy (RectificationAccess).
     */
    public function reviewRectifications(User $user, SchoolClass $class): bool
    {
        $organization = Organization::query()->findOrFail($class->organization_id);

        return $organization->type === OrganizationType::Institutional
            && $user->owns($organization);
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
        // Authorization only — WHO may attempt a permanent delete. Whether the
        // class is actually archived, retention-eligible, and free of
        // pedagogical history is a business rule checked by the controller
        // (never a raw 403 — the teacher gets a readable explanation instead).
        return $class->teachers()
            ->wherePivot('role', 'owner')
            ->whereKey($user->getKey())
            ->exists();
    }

    public function archive(User $user, SchoolClass $class): bool
    {
        return $this->teaches($user, $class);
    }

    public function restore(User $user, SchoolClass $class): bool
    {
        return $this->teaches($user, $class);
    }

    public function assignTeacher(User $user, SchoolClass $class): bool
    {
        return $user->ownsCurrentOrganization()
            && $class->teachers()->doesntExist();
    }

    protected function teaches(User $user, SchoolClass $class): bool
    {
        return $class->teachers()->whereKey($user->getKey())->exists();
    }
}
