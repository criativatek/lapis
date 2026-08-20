<?php

namespace App\Support\Organizations;

use RuntimeException;

class MembershipException extends RuntimeException
{
    public static function institutionalOnly(): self
    {
        return new self(__('Esta operação só está disponível em organizações institucionais.'));
    }

    public static function notAMember(): self
    {
        return new self(__('A pessoa indicada não é membro desta organização.'));
    }

    public static function ownerCannotLeave(): self
    {
        return new self(__('Antes de sair, transfira a responsabilidade da organização para outro membro.'));
    }

    public static function notOwner(): self
    {
        return new self(__('Apenas o responsável da organização pode realizar esta operação.'));
    }

    public static function ownerCannotBeRemoved(): self
    {
        return new self(__('O responsável não pode ser removido da organização.'));
    }

    public static function cannotRemoveSelf(): self
    {
        return new self(__('Não pode remover-se através desta operação.'));
    }

    public static function ownershipTargetMustDiffer(): self
    {
        return new self(__('Escolha outro membro para receber a responsabilidade da organização.'));
    }

    public static function classAlreadyAssigned(): self
    {
        return new self(__('Esta turma já tem um professor atribuído.'));
    }
}
