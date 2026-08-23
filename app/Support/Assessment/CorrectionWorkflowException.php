<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Refusals in the correction workflow — always a readable message for the
 * teacher, never a database error or a 500.
 */
class CorrectionWorkflowException extends RuntimeException
{
    /**
     * The count comes from InstrumentCompleteness, so it means exactly what the
     * progress column on the Avaliações page means.
     */
    public static function stillPending(int $pending): self
    {
        return new self($pending === 1
            ? __('Ainda existe 1 classificação por registar.')
            : __('Ainda existem :count classificações por registar.', ['count' => $pending]));
    }

    public static function alreadyCompleted(): self
    {
        return new self(__('A correção deste elemento de avaliação já está concluída.'));
    }

    public static function notInCorrection(string $status): self
    {
        return new self(__(
            'Só é possível concluir a correção de um elemento de avaliação em correção. Estado atual: :status.',
            ['status' => $status],
        ));
    }

    public static function notCompleted(string $status): self
    {
        return new self(__(
            'Só é possível reabrir a correção de um elemento de avaliação concluído. Estado atual: :status.',
            ['status' => $status],
        ));
    }

    public static function cannotCompleteCancelled(): self
    {
        return new self(__('Um elemento de avaliação anulado não pode ser concluído.'));
    }

    public static function cannotReopenCancelled(): self
    {
        return new self(__('Um elemento de avaliação anulado não pode ser reaberto.'));
    }

    /**
     * Raised when a save would change a correction that is already closed.
     */
    public static function correctionIsClosed(): self
    {
        return new self(__('A correção deste elemento de avaliação está concluída. Reabra a correção para fazer alterações.'));
    }

    /**
     * Guards RecordScores::save() specifically — distinct from notInCorrection()
     * (which guards concluding), because "prepare the grid first" is a different
     * instruction than "this is already concluded, reopen it".
     */
    public static function notReadyForScoring(string $status): self
    {
        return new self(__(
            'Só é possível lançar resultados numa grelha preparada ou em correção. Estado atual: :status.',
            ['status' => $status],
        ));
    }
}
