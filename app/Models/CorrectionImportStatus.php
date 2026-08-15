<?php

namespace App\Models;

/**
 * Where an import session has got to.
 *
 * Deliberately NOT InstrumentStatus. An import is a conversation with the
 * teacher about a file; an instrument is an assessment. Confusing the two is how
 * a half-read spreadsheet ends up deciding that a test is «em correção».
 *
 * The flow is short on purpose: uploaded → parsed → (needs mapping) → ready →
 * imported. Failed and Cancelled are ends, and both mean the temporary file
 * should no longer exist.
 */
enum CorrectionImportStatus: string
{
    /** The file is stored and nothing has been read yet. */
    case Uploaded = 'uploaded';

    /** The file was read into a canonical grid. Nothing academic exists yet. */
    case Parsed = 'parsed';

    /** Read, but the teacher still has to resolve something before it can be confirmed. */
    case NeedsMapping = 'needs_mapping';

    /** Everything is decided; confirming would write. */
    case Ready = 'ready';

    /** Written. Terminal, and the file is gone. */
    case Imported = 'imported';

    /** Could not be read or could not be written. Terminal. */
    case Failed = 'failed';

    /** The teacher walked away deliberately. Terminal. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => __('Carregado'),
            self::Parsed => __('Analisado'),
            self::NeedsMapping => __('Por confirmar'),
            self::Ready => __('Pronto a importar'),
            self::Imported => __('Importado'),
            self::Failed => __('Falhou'),
            self::Cancelled => __('Cancelado'),
        };
    }

    /**
     * Nothing more will happen to this import. The uploaded file has no reason
     * to exist past this point.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Imported, self::Failed, self::Cancelled], true);
    }

    /**
     * Whether the teacher may still work on it — the states where the wizard is
     * a live conversation rather than a record of one.
     */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * Only a fully decided import may be written, and only once. `Imported` is
     * excluded here rather than merely guarded elsewhere: confirming twice must
     * be impossible, not merely unlikely (§77).
     */
    public function canBeConfirmed(): bool
    {
        return $this === self::Ready;
    }
}
