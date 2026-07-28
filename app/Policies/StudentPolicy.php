<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

/**
 * A student has no single "owning" class — they may be enrolled in several.
 * Viewing their photo is authorized if the teacher teaches at least one class
 * this student is currently enrolled in.
 */
class StudentPolicy
{
    public function viewPhoto(User $user, Student $student): bool
    {
        return $student->enrollments()
            ->whereHas('schoolClass.teachers', fn ($query) => $query->whereKey($user->getKey()))
            ->exists();
    }
}
