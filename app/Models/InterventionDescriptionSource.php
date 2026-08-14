<?php

namespace App\Models;

/**
 * Where an intervention's description text came from (§8 of the module brief).
 *
 * Everything written today is Manual. Template and Ai exist so that the day
 * suggested or rewritten descriptions arrive, existing rows are already
 * distinguishable from them — a report that quotes a teacher's own words should
 * never be unable to tell them apart from generated text. Neither is produced
 * by any code path in this delivery.
 */
enum InterventionDescriptionSource: string
{
    case Manual = 'manual';
    case Template = 'template';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Escrita pelo professor'),
            self::Template => __('A partir de modelo'),
            self::Ai => __('Gerada com apoio de IA'),
        };
    }
}
