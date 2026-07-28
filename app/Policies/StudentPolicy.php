<?php

namespace App\Policies;

use App\Models\EnrollmentStatus;
use App\Models\Student;
use App\Models\User;

/**
 * A student has no single "owning" class — they may be enrolled in several.
 * Viewing their photo is authorized if the teacher teaches at least one class
 * this student is currently, actively enrolled in. History is kept forever
 * (§ historical data is never deleted), so a student who transferred out or
 * left must not leave that teacher with indefinite access to their photo —
 * hence the Active filter, not just "some enrollment exists."
 */
class StudentPolicy
{
    public function viewPhoto(User $user, Student $student): bool
    {
        return $student->enrollments()
            ->where('status', EnrollmentStatus::Active)
            ->whereHas('schoolClass.teachers', fn ($query) => $query->whereKey($user->getKey()))
            ->exists();
    }
}
