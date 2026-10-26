<?php

namespace App\Policies;

use App\Models\Classification;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Assessment\RectificationAccess;

/**
 * Discovered by name (App\Models\Classification -> App\Policies\ClassificationPolicy),
 * like the other model policies. Both abilities read the organization of the
 * classification, not the user's current one — see RectificationAccess.
 */
class ClassificationPolicy
{
    public function rectify(User $user, Classification $classification): bool
    {
        return RectificationAccess::for($user, $classification)->allowed;
    }

    /**
     * The history of a classification: whoever teaches the class, plus the head of
     * an institution — who may rectify classes they do not teach once the period is
     * closed, and so has to be able to see what they are changing.
     */
    public function viewHistory(User $user, Classification $classification): bool
    {
        // Same reasoning as RectificationAccess: no tenant scope on these reads.
        $enrollment = Enrollment::query()->withoutGlobalScope('organization')
            ->findOrFail($classification->enrollment_id);
        $class = SchoolClass::query()->withoutGlobalScope('organization')
            ->findOrFail($enrollment->class_id);

        if ($class->teachers()->whereKey($user->getKey())->exists()) {
            return true;
        }

        $organization = Organization::query()->findOrFail($classification->organization_id);

        return ! $organization->isPersonal() && $user->owns($organization);
    }
}
