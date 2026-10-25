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

    /**
     * Published is the closing act. Changing one is a rectification, which
     * writes a new row and keeps the old one as history — so a plain edit is
     * refused and points there, instead of editing the grade behind the back
     * of everyone it was communicated to.
     */
    public static function alreadyPublished(): self
    {
        return new self(__('Esta classificação já foi publicada. Para a alterar, use «Retificar».'));
    }

    public static function notChangeable(): self
    {
        return new self(__('Esta classificação já não está em condições de ser alterada.'));
    }

    /**
     * Changing a decision means stating the new one. There is no «use the
     * proposal» here: that is how the first decision is made, not how it is
     * revised.
     */
    public static function decisionRequired(): self
    {
        return new self(__('Indique o nível ou a classificação a atribuir.'));
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

    /** Only a published, live classification can be rectified; anything else is changed some other way. */
    public static function notPublished(): self
    {
        return new self(__('Só uma classificação publicada pode ser retificada.'));
    }

    /**
     * The row moved on between the click and the write: someone else rectified
     * it first, or its version is not the one the screen showed.
     */
    public static function alreadyRectified(): self
    {
        return new self(__('Esta classificação já foi retificada. Recarregue a página para ver o valor atual.'));
    }

    /** A rectification that changes nothing is not one. */
    public static function unchangedValue(): self
    {
        return new self(__('O valor indicado é igual ao atual. Indique o novo valor da classificação.'));
    }

    public static function rectificationNotAllowed(): self
    {
        return new self(__('Não tem permissão para retificar esta classificação.'));
    }

    public static function closedNeedsConfirmation(): self
    {
        return new self(__('O período está encerrado. Confirme que pretende retificar uma classificação já fechada.'));
    }

    public static function noteRequired(): self
    {
        return new self(__('Explique o motivo da retificação: «Outro» exige uma nota.'));
    }
}
