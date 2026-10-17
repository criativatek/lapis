<?php

namespace App\Policies;

use App\Models\TimetableActivitySession;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A sessão de uma «outra atividade» é do professor que a escreveu (`user_id`).
 * Nem os coprofessores da turma associada a veem ou editam — a turma é só uma
 * referência, nunca uma permissão (por isso nada aqui passa por `teaches()` ou
 * pelas aulas acessíveis). Quem não é o dono recebe 404: nem a existência se
 * confirma.
 */
class TimetableActivitySessionPolicy
{
    public function view(User $user, TimetableActivitySession $session): Response
    {
        return $this->owned($user, $session);
    }

    public function update(User $user, TimetableActivitySession $session): Response
    {
        return $this->owned($user, $session);
    }

    protected function owned(User $user, TimetableActivitySession $session): Response
    {
        return (int) $session->user_id === (int) $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
