<?php

namespace App\Domain\Import\Correction;

/**
 * How much an issue found while reading a correction grid should cost.
 *
 * The distinction is about who decides, not about how alarming the wording is:
 * an Error is the import saying "I cannot do this correctly"; a Warning is the
 * import saying "I can do this, but you should know what you are agreeing to".
 * Only the teacher resolves a Warning, and only by looking at it.
 */
enum IssueSeverity: string
{
    /** Something worth saying. Never blocks anything. */
    case Info = 'info';

    /** The import can proceed, but not without the teacher having seen this. */
    case Warning = 'warning';

    /** The import cannot be confirmed while this stands. */
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Info => __('Informação'),
            self::Warning => __('Aviso'),
            self::Error => __('Erro'),
        };
    }

    public function blocksConfirmation(): bool
    {
        return $this === self::Error;
    }
}
