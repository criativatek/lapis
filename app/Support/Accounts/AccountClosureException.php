<?php

namespace App\Support\Accounts;

use RuntimeException;

class AccountClosureException extends RuntimeException
{
    public static function alreadyRequested(): self
    {
        return new self(__('Já pediu o encerramento desta conta.'));
    }

    public static function notRequested(): self
    {
        return new self(__('Esta conta não tem nenhum pedido de encerramento em curso.'));
    }

    public static function noLongerRecoverable(): self
    {
        return new self(__('O prazo de recuperação desta conta já terminou.'));
    }

    public static function ownsInstitution(): self
    {
        return new self(__('Antes de encerrar a sua conta, transfira a responsabilidade das organizações institucionais que gere.'));
    }
}
