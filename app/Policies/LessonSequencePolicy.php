<?php

namespace App\Policies;

use App\Models\LessonSequence;
use App\Models\User;

/**
 * A sequence is personal to the teacher who built it — reusable across their
 * own classes, never shared org-wide in this slice (Fatia 4). Any member of
 * the tenant may create one; only its author may see, change, remove or
 * apply it.
 */
class LessonSequencePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, LessonSequence $lessonSequence): bool
    {
        return $this->authored($user, $lessonSequence);
    }

    public function update(User $user, LessonSequence $lessonSequence): bool
    {
        return $this->authored($user, $lessonSequence);
    }

    public function delete(User $user, LessonSequence $lessonSequence): bool
    {
        return $this->authored($user, $lessonSequence);
    }

    protected function authored(User $user, LessonSequence $lessonSequence): bool
    {
        return (int) $lessonSequence->user_id === (int) $user->getKey();
    }
}
