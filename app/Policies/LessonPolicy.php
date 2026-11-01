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

    /**
     * Registar o resultado de uma aula (ausência, atividade, não realizada).
     * Um observador acompanha a turma mas não declara o que aconteceu numa
     * aula: a pertença não chega, como em SubjectParticipationPolicy::manage.
     */
    public function recordOutcome(User $user, Lesson $lesson): bool
    {
        return $lesson->schoolClass->teachers()
            ->whereKey($user->getKey())
            ->wherePivot('role', '!=', 'observer')
            ->exists();
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
