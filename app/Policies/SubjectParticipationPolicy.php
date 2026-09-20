<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

/**
 * Quem pode abrir, fechar ou corrigir a frequência de uma disciplina — e
 * quem pode apenas ler o que já lá está.
 *
 * A mesma resposta de fundo que ClassGroupPolicy::teaches() já dá («quem
 * ensina a turma manda nela») com UMA EXCEÇÃO: um `observer` em
 * `class_teachers.role` foi convidado a acompanhar a turma, não a decidir
 * por ela — a mesma distinção leitura/escrita que já existe no pivot em si
 * (a coluna tem esse valor precisamente para isto, ainda que nenhuma outra
 * policy do módulo a tenha lido até agora). `manage()` é por isso a única
 * porta de entrada nova: escrever aqui exige ensinar a turma E não ser
 * `observer`; ler continua aberto a qualquer um dos três papéis, coerente
 * com `SchoolClassPolicy::view()`.
 */
class SubjectParticipationPolicy
{
    public function manage(User $user, SchoolClass $schoolClass): bool
    {
        return $schoolClass->teachers()
            ->whereKey($user->getKey())
            ->wherePivot('role', '!=', 'observer')
            ->exists();
    }
}
