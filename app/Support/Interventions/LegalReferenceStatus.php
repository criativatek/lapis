<?php

namespace App\Support\Interventions;

/**
 * Whether a measure is still named by the version of the law that cites it.
 *
 * A measure is never deleted from the catalogue when a diploma revokes it:
 * interventions recorded under it are history, and history must keep resolving
 * to a label and a citation. Revoking it here stops it being OFFERED, without
 * touching a single stored row.
 */
enum LegalReferenceStatus: string
{
    /** Named and in force in this version. */
    case Active = 'active';

    /** Revoked by this version — still readable, no longer offered. */
    case Revoked = 'revoked';

    /**
     * Replaced by another measure in this version. `supersededBy` on the
     * reference says by which, so a report can explain the continuity rather
     * than showing a gap.
     */
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Em vigor'),
            self::Revoked => __('Revogada'),
            self::Superseded => __('Substituída'),
        };
    }

    /** Whether a teacher may still choose this measure today. */
    public function isSelectable(): bool
    {
        return $this === self::Active;
    }
}
