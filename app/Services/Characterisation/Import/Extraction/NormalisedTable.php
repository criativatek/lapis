<?php

namespace App\Services\Characterisation\Import\Extraction;

use App\Services\Characterisation\Import\TableGrid;

/**
 * What NormaliseExtractedTable::normalise() hands back: the TableGrid it
 * built, together with the warnings that call — and only that call —
 * produced.
 *
 * THIS EXISTS SO WARNINGS NEVER LIVE ON THE NORMALISER ITSELF. They used to
 * be instance state, set during normalise() and read back afterwards through
 * a separate warnings() call — safe only because NormaliseExtractedTable is
 * built fresh per import today, and silently wrong the day it is ever bound
 * as a singleton (Octane, or an accidental container change): two imports
 * running through the same instance would leak one organization's dropped
 * rows into another's response. Returning both together, from one call,
 * makes that leak structurally impossible rather than a convention someone
 * has to remember.
 */
readonly class NormalisedTable
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public TableGrid $grid,
        public array $warnings,
    ) {}
}
