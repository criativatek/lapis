<?php

namespace App\Policies;

use App\Models\ClassGroup;
use App\Models\SchoolClass;
use App\Models\User;

/**
 * Quem manda nos grupos de uma turma é quem a ensina — a mesma resposta que
 * RecurringLessonSlotPolicy e LessonPolicy já dão, e de propósito: um grupo só
 * existe para o horário, e seria incoerente que alguém pudesse criar «T1» e
 * não pudesse depois atribuí-lo a um tempo.
 *
 * A fronteira da organização não é repetida aqui: o global scope de
 * SchoolClass já a desenha, e uma turma de outra organização nunca chega a ser
 * resolvida pela rota.
 */
class ClassGroupPolicy
{
    public function viewAny(User $user, SchoolClass $schoolClass): bool
    {
        return $this->teaches($user, $schoolClass);
    }

    public function create(User $user, SchoolClass $schoolClass): bool
    {
        return $this->teaches($user, $schoolClass);
    }

    public function update(User $user, ClassGroup $classGroup): bool
    {
        return $this->teaches($user, $classGroup->schoolClass);
    }

    public function delete(User $user, ClassGroup $classGroup): bool
    {
        return $this->teaches($user, $classGroup->schoolClass);
    }

    protected function teaches(User $user, SchoolClass $schoolClass): bool
    {
        return $schoolClass->teachers()->whereKey($user->getKey())->exists();
    }
}
