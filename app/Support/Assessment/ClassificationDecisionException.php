<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Thrown when a confirmation cannot proceed as asked: the proposal is stale
 * (scores changed since it was generated), the classification is not in a
 * confirmable state, or the decision is not one this scale can express.
 *
 * Deciding differently from the proposal is NOT one of these. The teacher
 * decides (§3.3); assigning a level other than the proposed one is the job, not
 * an exception to be justified before it is allowed.
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

    /** A level id that is not one of this scale's own — including one that does not exist. */
    public static function levelNotOnScale(): self
    {
        return new self(__('O nível indicado não pertence à escala de classificação desta turma.'));
    }

    public static function outsideScale(string $minimum, string $maximum): self
    {
        return new self(__('A classificação tem de estar entre :min e :max, os limites da escala desta turma.', [
            'min' => $minimum,
            'max' => $maximum,
        ]));
    }

    /** The class has no scale at all, so there is nothing to decide on. */
    public static function withoutScale(): self
    {
        return new self(__('Esta turma não tem escala de classificação definida no perfil de avaliação.'));
    }
}
