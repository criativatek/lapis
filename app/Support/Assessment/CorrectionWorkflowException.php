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
        return new self(__('A correção deste instrumento já está concluída.'));
    }

    public static function notInCorrection(string $status): self
    {
        return new self(__(
            'Só é possível concluir a correção de um instrumento em correção. Estado atual: :status.',
            ['status' => $status],
        ));
    }

    public static function notCompleted(string $status): self
    {
        return new self(__(
            'Só é possível reabrir a correção de um instrumento concluído. Estado atual: :status.',
            ['status' => $status],
        ));
    }

    public static function cannotCompleteCancelled(): self
    {
        return new self(__('Um instrumento anulado não pode ser concluído.'));
    }

    public static function cannotReopenCancelled(): self
    {
        return new self(__('Um instrumento anulado não pode ser reaberto.'));
    }

    /**
     * Raised when a save would change a correction that is already closed.
     */
    public static function correctionIsClosed(): self
    {
        return new self(__('A correção deste instrumento está concluída. Reabra a correção para fazer alterações.'));
    }
}
