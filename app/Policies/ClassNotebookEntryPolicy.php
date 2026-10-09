<?php

namespace App\Policies;

use App\Models\ClassNotebookEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Um registo do caderno da turma é privado do seu autor. Quem não é o autor —
 * incluindo um colega da MESMA turma — recebe 404: nem a existência do
 * registo se confirma.
 */
class ClassNotebookEntryPolicy
{
    public function view(User $user, ClassNotebookEntry $entry): Response
    {
        return $this->authored($user, $entry);
    }

    public function update(User $user, ClassNotebookEntry $entry): Response
    {
        return $this->authored($user, $entry);
    }

    public function delete(User $user, ClassNotebookEntry $entry): Response
    {
        return $this->authored($user, $entry);
    }

    protected function authored(User $user, ClassNotebookEntry $entry): Response
    {
        return (int) $entry->author_id === (int) $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
