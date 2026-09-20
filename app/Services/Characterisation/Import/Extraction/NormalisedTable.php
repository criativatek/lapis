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
 *
 * `structuralHeaders`/`structuralRows`/`hadMergedCells` are what §38's
 * "Rever tabela reconhecida" step renders: the WHOLE recognised table,
 * including the header row(s) and every row that was classified Group or
 * Legend and therefore never reached $grid — so the teacher can see WHY a
 * row is missing, not just how many survived. `$grid` stays Data-only, exactly
 * as every caller before §38 already expects.
 */
readonly class NormalisedTable
{
    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $structuralHeaders  the same joined headers as $grid->headers, repeated here so the structural step never has to reach into two places for one row of column captions
     * @param  list<array{number: int, kind: string, cells: list<string>}>  $structuralRows  EVERY row NormaliseExtractedTable saw in the body — data, group and legend alike — in original order
     * @param  bool  $hadMergedCells  whether the source table carried any colspan/rowspan>1 cell — see NormaliseExtractedTable::showStructuralStep()
     * @param  bool  $wasPreClassified  whether the source table's rows already carried an explicit kind — i.e. this table is itself the result of the §38 structural step, and must not be sent back through it a second time (see the controller's `show_structural_step`)
     */
    public function __construct(
        public TableGrid $grid,
        public array $warnings,
        public array $structuralHeaders = [],
        public array $structuralRows = [],
        public bool $hadMergedCells = false,
        public bool $wasPreClassified = false,
    ) {}
}
