<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;

class LessonPolicy
{
    public function create(User $user, SchoolClass $schoolClass): bool
    {
        return $this->teaches($user, $schoolClass);
    }

    public function view(User $user, Lesson $lesson): bool
    {
        return $this->teaches($user, $lesson->schoolClass);
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->teaches($user, $lesson->schoolClass);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->teaches($user, $lesson->schoolClass);
    }

    protected function teaches(User $user, SchoolClass $schoolClass): bool
    {
        return $schoolClass->teachers()->whereKey($user->getKey())->exists();
    }
}
