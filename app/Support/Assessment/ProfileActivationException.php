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

    /**
     * A regra existe na coluna mas o motor não a cumpre.
     *
     * Recusar é a única resposta honesta: implementar o modo seria decidir uma
     * regra pedagógica por conta própria (§1), e deixar ativar seria calcular
     * por uma regra dizendo ao professor que se calculou por outra.
     */
    public static function unsupportedRule(string $field, string $value, string $supported): self
    {
        return new self(__(
            'A regra «:field = :value» ainda não está implementada no cálculo, por isso o perfil não pode ser ativado com ela. Neste momento o motor só cumpre «:supported». Fale connosco se precisa desta regra.',
            ['field' => $field, 'value' => $value, 'supported' => $supported],
        ));
    }
}
