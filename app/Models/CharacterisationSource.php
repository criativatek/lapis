<?php

namespace App\Models;

/**
 * Where a characterisation revision came from.
 *
 * Worth a column of its own rather than an inference from `import_batch_id`
 * being present: a teacher reading the history needs to know whether a sentence
 * was written by a person or arrived from the school's paperwork, and that
 * question outlives whatever happens to the batch row.
 */
enum CharacterisationSource: string
{
    case Manual = 'manual';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Editado'),
            self::Import => __('Importado'),
        };
    }
}
