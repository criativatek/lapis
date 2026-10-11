<?php

namespace App\Support\Usage;

/**
 * Os «primeiros passos». Datas de primeira OBSERVAÇÃO, nunca prova de que o
 * passo não aconteceu antes de a recolha começar.
 */
enum UsageMilestone: string
{
    case FirstClassCreated = 'first_class_created';
    case FirstStudentsAdded = 'first_students_added';
    case FirstUsefulRecord = 'first_useful_record';

    public function label(): string
    {
        return match ($this) {
            self::FirstClassCreated => 'Primeira turma criada',
            self::FirstStudentsAdded => 'Primeiros alunos adicionados',
            self::FirstUsefulRecord => 'Primeiro registo útil',
        };
    }

    /**
     * Os tipos de evento que provam este passo.
     *
     * @return list<UsageEventType>
     */
    public function events(): array
    {
        return array_values(array_filter(
            UsageEventType::cases(),
            fn (UsageEventType $type): bool => $type->milestone() === $this,
        ));
    }

    /** A coluna de `usage_user_states` onde a primeira observação fica. */
    public function stateColumn(): string
    {
        return $this->value.'_on';
    }
}
