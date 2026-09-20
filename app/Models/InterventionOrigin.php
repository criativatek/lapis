<?php

namespace App\Models;

/**
 * How an intervention came to exist — never a judgement about it, only where
 * the decision to record it happened.
 *
 * `Manual` covers the ordinary form AND every row written before this enum
 * existed (see the migration that added the column): a null `origin` reads as
 * manual, so there is nothing to distinguish there.
 *
 * `CharacterisationImport` is written only by
 * `App\Actions\Characterisation\ApplyCharacterisationImport`, and only for a
 * measure a teacher confirmed row-by-row in the import preview — never for one
 * a spreadsheet cell merely suggested. See that class's docblock for why that
 * confirmation is what makes creating the Intervention here honest rather than
 * invented.
 */
enum InterventionOrigin: string
{
    case Manual = 'manual';
    case CharacterisationImport = 'characterisation_import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Registo manual'),
            self::CharacterisationImport => __('Importação da caracterização'),
        };
    }
}
