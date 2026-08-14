<?php

namespace App\Models;

/**
 * Where an intervention's stored legal framing came from (§13 of the module
 * brief). This is what separates "the app filled this in from an unambiguous
 * catalogue entry" from "the teacher decided this" — a distinction reports must
 * be able to make, since only a human decision carries pedagogical authority.
 *
 * Note what is absent: there is no "suggested but unconfirmed" case. A
 * contextual suggestion the teacher never acted on is not stored at all
 * (§12.2), so it can never be read back as a decision.
 */
enum LegalMappingSource: string
{
    /** The type maps to exactly one framing, with no judgement involved. */
    case SystemDirect = 'system_direct';

    /** The app suggested a framing and the teacher explicitly confirmed it. */
    case SystemSuggestedConfirmed = 'system_suggested_confirmed';

    /** The teacher chose or edited the framing themselves. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::SystemDirect => __('Automático'),
            self::SystemSuggestedConfirmed => __('Sugerido e confirmado'),
            self::Manual => __('Definido pelo professor'),
        };
    }
}
