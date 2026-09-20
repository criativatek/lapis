<?php

namespace App\Support\Characterisation;

/**
 * How sure the resolver is about what a cell meant.
 *
 * There is no fourth state and no numeric score: a teacher reading a preview
 * needs to know whether to look, not how confident a number feels.
 */
enum CodeConfidence: string
{
    /** Resolved to a measure the catalogue knows. */
    case Recognised = 'recognised';

    /** Something was understood — usually the level — but not which measure. */
    case Ambiguous = 'ambiguous';

    /** Read as a token, not understood. Never written without a human saying so. */
    case Unrecognised = 'unrecognised';

    public function label(): string
    {
        return match ($this) {
            self::Recognised => __('Reconhecido'),
            self::Ambiguous => __('Ambíguo'),
            self::Unrecognised => __('Não reconhecido'),
        };
    }
}
