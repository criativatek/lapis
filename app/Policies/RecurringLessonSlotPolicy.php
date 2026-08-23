<?php

namespace App\Policies;

use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;

class RecurringLessonSlotPolicy
{
    public function create(User $user, SchoolClass $schoolClass): bool
    {
        return $this->teaches($user, $schoolClass);
    }

    public function update(User $user, RecurringLessonSlot $recurringLessonSlot): bool
    {
        return $this->teaches($user, $recurringLessonSlot->schoolClass);
    }

    public function delete(User $user, RecurringLessonSlot $recurringLessonSlot): bool
    {
        return $this->teaches($user, $recurringLessonSlot->schoolClass);
    }

    protected function teaches(User $user, SchoolClass $schoolClass): bool
    {
        return $schoolClass->teachers()->whereKey($user->getKey())->exists();
    }
}
