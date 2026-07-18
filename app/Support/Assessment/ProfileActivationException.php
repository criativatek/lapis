<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Thrown when a draft profile version fails the checks required to activate
 * (§10.3: "um perfil só pode ser ativado se passar todas as validações").
 */
class ProfileActivationException extends RuntimeException
{
    public static function noDomains(): self
    {
        return new self(__('O perfil precisa de pelo menos um domínio para ser ativado.'));
    }

    public static function weightsMustTotal100(string $actual): self
    {
        return new self(__('A soma das ponderações dos domínios tem de ser 100%. Total atual: :total%.', ['total' => $actual]));
    }

    public static function notADraft(): self
    {
        return new self(__('Apenas uma versão em rascunho pode ser ativada.'));
    }
}
