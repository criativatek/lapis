<?php

namespace App\Models;

/**
 * Where an intervention's description text came from (§8 of the module brief).
 *
 * Template and Ai exist so that the day suggested or rewritten descriptions
 * arrive, existing rows are already distinguishable from them — a report that
 * quotes a teacher's own words should never be unable to tell them apart from
 * generated text. Neither is produced by any code path in this delivery.
 *
 * `Import` covers the one description this application DOES compose itself
 * today: `ApplyCharacterisationImport` writes "Importado da caracterização —
 * o ficheiro indicava: …", quoting the school's own paperwork rather than the
 * teacher's own words. Filing that under Manual would be the exact confusion
 * this enum exists to prevent — a report quoting it as something a teacher
 * typed would be wrong.
 */
enum InterventionDescriptionSource: string
{
    case Manual = 'manual';
    case Template = 'template';
    case Ai = 'ai';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Escrita pelo professor'),
            self::Template => __('A partir de modelo'),
            self::Ai => __('Gerada com apoio de IA'),
            self::Import => __('Composta a partir da importação da caracterização'),
        };
    }
}
