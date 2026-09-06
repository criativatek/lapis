<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Quando a decisão sobre um domínio não pode ser escrita como foi pedida.
 *
 * Decidir DIFERENTE da proposta não está aqui, e nunca estará: é exatamente
 * para isso que a decisão por domínio existe (§3.3). O que está aqui são
 * pedidos que não descrevem coisa nenhuma — um domínio que não é deste perfil,
 * um nível que não é desta escala, um aluno que não é desta turma.
 */
class DomainDecisionException extends RuntimeException
{
    /** A turma não tem perfil ativo, logo não tem domínios nem escala. */
    public static function withoutProfile(): self
    {
        return new self(__('Esta turma não tem perfil de avaliação associado, por isso não há apreciações por domínio a decidir.'));
    }

    public static function domainNotInProfile(): self
    {
        return new self(__('Este domínio não pertence ao perfil de avaliação desta turma.'));
    }

    public static function levelNotOnScale(): self
    {
        return new self(__('A apreciação indicada não pertence à escala desta turma.'));
    }

    /**
     * A escala é um intervalo (0–20, percentagem): não tem menções para
     * escolher, e uma apreciação qualitativa por domínio não é expressável
     * nela. Dizê-lo é melhor do que inventar bandas (§10.4).
     */
    public static function scaleWithoutLevels(): self
    {
        return new self(__('A escala desta turma não tem menções qualitativas, por isso não há apreciação por domínio a atribuir.'));
    }
}
