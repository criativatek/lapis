<?php

namespace App\Policies;

use App\Models\TimetableActivity;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Uma atividade não letiva é do horário de UM professor. Qualquer membro com o
 * módulo das aulas pode criar as suas; só o dono as vê, edita ou apaga — e
 * quem não é o dono recebe 404, nem a existência se confirma.
 */
class TimetableActivityPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TimetableActivity $activity): Response
    {
        return $this->owned($user, $activity);
    }

    public function delete(User $user, TimetableActivity $activity): Response
    {
        return $this->owned($user, $activity);
    }

    protected function owned(User $user, TimetableActivity $activity): Response
    {
        return (int) $activity->user_id === (int) $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
