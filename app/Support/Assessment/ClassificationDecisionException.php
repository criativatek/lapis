<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Thrown when a confirmation cannot proceed as asked: the proposal is stale
 * (scores changed since it was generated), the classification is not in a
 * confirmable state, or a manual override was requested without a reason (A10,
 * §7.1 — "the teacher decides", but a changed grade must always say why).
 */
class ClassificationDecisionException extends RuntimeException
{
    public static function stale(): self
    {
        return new self(__('A proposta está desatualizada — as pontuações mudaram desde que foi gerada. Gere a proposta novamente antes de confirmar.'));
    }

    public static function notProposed(): self
    {
        return new self(__('Só uma proposta por confirmar pode ser confirmada.'));
    }

    public static function missingOverrideReason(): self
    {
        return new self(__('Alterar o valor proposto exige um motivo.'));
    }
}
