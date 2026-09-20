<?php

namespace App\Services\Characterisation\Import;

/**
 * How sure the importer is about who a row is about.
 *
 * Only Confident arrives pre-selected in the preview. The other three arrive
 * switched off, waiting for a person — because the cost of being wrong here is
 * one child's pedagogical text landing on another child's record, and there is
 * no version of that which a convenience is worth.
 */
enum RowMatchState: string
{
    /** Matched on the school's own identifier, or on an exact and unique name. */
    case Confident = 'confident';

    /** One plausible student, found by a looser name comparison. Never applied on its own. */
    case Possible = 'possible';

    /** More than one student answers. The application does not break the tie. */
    case Ambiguous = 'ambiguous';

    /** Nobody on this class's roll answers to the row. */
    case NotFound = 'not_found';

    public function label(): string
    {
        return match ($this) {
            self::Confident => __('Correspondência segura'),
            self::Possible => __('Possível correspondência'),
            self::Ambiguous => __('Ambíguo'),
            self::NotFound => __('Não encontrado'),
        };
    }

    /** Whether the preview may tick this row for the teacher. */
    public function isPreselected(): bool
    {
        return $this === self::Confident;
    }
}
