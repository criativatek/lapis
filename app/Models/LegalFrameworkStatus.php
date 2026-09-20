<?php

namespace App\Models;

/**
 * Where one version of a legal framework stands in its own life cycle.
 *
 * The distinction that carries the weight: only Active and Historical are ever
 * APPLICABLE. A Draft or Future version may exist in the registry, be tested,
 * be inspected — and must never be handed to a teacher as the law, because it
 * is not. Putting a preliminary revision in front of a teacher as if it were in
 * force is the single worst failure mode this module has, so the guard lives in
 * the type rather than in a caller's `if`.
 */
enum LegalFrameworkStatus: string
{
    /**
     * A preliminary text — a proposal, a public-consultation draft. Not law,
     * and not scheduled to become law on any known date.
     */
    case Draft = 'draft';

    /**
     * A published text with a known future date of entry into force. Still not
     * applicable to anything happening today.
     */
    case Future = 'future';

    /** In force now. */
    case Active = 'active';

    /** Was in force, is no longer — still applicable to what happened then. */
    case Historical = 'historical';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Proposta preliminar'),
            self::Future => __('Publicada, ainda não em vigor'),
            self::Active => __('Em vigor'),
            self::Historical => __('Vigorou até'),
        };
    }

    /**
     * Whether Lapispro may apply this version to a real intervention.
     *
     * Historical is true on purpose: an intervention recorded in 2026 is still
     * read under the 2026 regime in 2030. That is the whole point of keeping
     * superseded versions rather than deleting them.
     */
    public function isApplicable(): bool
    {
        return $this === self::Active || $this === self::Historical;
    }
}
