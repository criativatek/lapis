<?php

namespace App\Models;

/**
 * Where a backup restore session has got to.
 *
 * Short by design, mirroring CorrectionImportStatus: uploaded → validated →
 * imported. No "needs mapping" state — unlike a correction grid, a Lapispro
 * backup has no ambiguous column mapping for a teacher to resolve; every
 * row is already classified (new/existing/conflict/unsupported) by the
 * preview builder itself.
 *
 * No `Invalid` case, deliberately: a file that fails validation (corrupt,
 * unsupported schema, secrets detected) never becomes a DataImport row at
 * all — DataImportController::store() rejects it with a form error before
 * anything is persisted (§6: "nenhum insert/update nesta fase" applies to
 * the row itself, not only to pedagogical data). There is nothing a
 * teacher could do with an "invalid" row on file — only a fresh upload
 * moves forward — so no state was invented to hold one.
 */
enum DataImportStatus: string
{
    /** The file is stored and nothing has been read yet. */
    case Uploaded = 'uploaded';

    /** Parsed, structurally valid, preview computed. Confirming would write. */
    case Validated = 'validated';

    /** Written. Terminal, and the file is gone. */
    case Imported = 'imported';

    /** Validated but the write transaction failed. Terminal. */
    case Failed = 'failed';

    /** The teacher walked away deliberately, or cleanup expired it. Terminal. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => __('Carregado'),
            self::Validated => __('Analisado'),
            self::Imported => __('Importado'),
            self::Failed => __('Falhou'),
            self::Cancelled => __('Cancelado'),
        };
    }

    /**
     * Nothing more will happen to this import. The uploaded file has no
     * reason to exist past this point.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Imported, self::Failed, self::Cancelled], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * Only a validated backup may be written, and only once. `Imported` is
     * excluded here rather than merely guarded elsewhere: confirming twice
     * must be impossible, not merely unlikely.
     */
    public function canBeConfirmed(): bool
    {
        return $this === self::Validated;
    }
}
