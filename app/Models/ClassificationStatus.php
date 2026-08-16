<?php

namespace App\Models;

/**
 * The life of a decision (§7.1): the system proposes, the teacher confirms, it is
 * published, and a later decision supersedes it.
 *
 * CONFIRMED IS NOT CLOSED. It is the teacher's decision, recorded — but nothing
 * has been communicated yet, and a teacher who wants to change their mind before
 * publishing is doing an ordinary thing, not repairing a mistake. What `confirmed`
 * protects against is the PROPOSAL step overwriting a decision, which is what
 * `isFrozen()` answers; it was never meant to lock the teacher out of their own.
 *
 * Publication is the closing act. From there a decision only changes by being
 * superseded — a mechanism the schema is ready for (`superseded_by_id`, the
 * one-live unique index) and that nothing implements yet, so a published
 * classification is refused rather than quietly edited.
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

    /** A frozen decision: the PROPOSAL step must never overwrite it. */
    public function isFrozen(): bool
    {
        return $this !== self::Proposed;
    }

    /**
     * Whether the teacher may still set the classification on this row.
     *
     * True while it is a proposal, and still true once confirmed: until it is
     * published, the decision is theirs to revise.
     */
    public function allowsDecision(): bool
    {
        return $this === self::Proposed || $this === self::Confirmed;
    }

    /** Communicated. Only a supersession changes it now — and there is none yet. */
    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
