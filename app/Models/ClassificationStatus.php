<?php

namespace App\Models;

/**
 * The life of a decision (§7.1): the system proposes, the teacher confirms, it is
 * published, and a later decision supersedes it. A proposal can be regenerated;
 * everything from `confirmed` on is frozen and only changes by superseding.
 */
enum ClassificationStatus: string
{
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
    case Published = 'published';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => __('Proposta'),
            self::Confirmed => __('Confirmada'),
            self::Published => __('Publicada'),
            self::Superseded => __('Substituída'),
        };
    }

    /** A frozen decision: the proposal step must never overwrite it. */
    public function isFrozen(): bool
    {
        return $this !== self::Proposed;
    }
}
