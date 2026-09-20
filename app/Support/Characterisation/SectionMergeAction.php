<?php

namespace App\Support\Characterisation;

/**
 * What happens to ONE characterisation section when an import brings text for
 * it.
 *
 * There is deliberately no `Replace` here. Overwriting what a teacher wrote by
 * hand is exactly the failure this enum exists to make impossible to reach
 * from an import: the only path that removes or rewrites existing text is a
 * teacher, later, on the edit screen, making that choice on purpose. An import
 * that "won" would mean the file replaced a person, and nothing on this list
 * lets that happen by accident.
 */
enum SectionMergeAction: string
{
    /** Nothing was recorded yet, or the incoming text is genuinely new — appended. */
    case Add = 'add';

    /**
     * The incoming text (once normalised for comparison) is already contained
     * in what is recorded. Re-importing the same table must be a no-op, not a
     * second copy of the same sentence.
     */
    case AlreadyPresent = 'already_present';

    /**
     * Reserved for a caller that wants to flag a section for human attention
     * instead of writing to it automatically — never produced by the
     * comparison-only merge itself, but part of the vocabulary so a stricter
     * caller can express it without inventing a second enum.
     */
    case ReviewRequired = 'review_required';

    /** The section key the incoming value would go to does not exist on this record. */
    case NoDestination = 'no_destination';

    /** The incoming value is empty — there is nothing to merge. */
    case Ignore = 'ignore';

    public function label(): string
    {
        return match ($this) {
            self::Add => __('A acrescentar'),
            self::AlreadyPresent => __('Já registado'),
            self::ReviewRequired => __('A rever'),
            self::NoDestination => __('Sem destino'),
            self::Ignore => __('Sem alteração'),
        };
    }
}
