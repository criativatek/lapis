<?php

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\User;

/**
 * Um acontecimento é pessoal de quem o criou (Fase 5.3) — never a shared,
 * institutional calendar in this version. Any member of the tenant may create
 * their own; only the owner may see, change or remove one.
 *
 * The same shape as LessonSequencePolicy, and for the same reason: the
 * organization boundary is already drawn by the model's own global scope, so
 * what is left to decide here is authorship and nothing else.
 */
class CalendarEventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->owns($user, $calendarEvent);
    }

    public function update(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->owns($user, $calendarEvent);
    }

    public function delete(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->owns($user, $calendarEvent);
    }

    protected function owns(User $user, CalendarEvent $calendarEvent): bool
    {
        return (int) $calendarEvent->user_id === (int) $user->getKey();
    }
}
