<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\ImportMapping;

/**
 * A parser whose file does not explain itself, and which therefore needs the
 * teacher before it can produce a grid.
 *
 * Plickers and Intuitivo do not implement this and must never be made to: their
 * exports declare their own structure, and putting them through a mapping screen
 * would be asking a teacher to describe a file Lapispro already understands (§7).
 *
 * Anything that needs to know whether a source can be re-described asks for this
 * interface rather than comparing against a source name. That is the same rule
 * the registry already keeps — capability, never provider (§40).
 */
interface MappedCorrectionGridParser extends CorrectionGridParser
{
    /**
     * What the file looks like, for the screen that asks what it means: the
     * sheets, the columns, a sample of rows, and structural suggestions the
     * teacher confirms or changes.
     *
     * Never persisted. It carries the teacher's own students' names, so it is
     * built per request and travels only to the browser of the teacher who
     * uploaded it (§39).
     *
     * @return array<string, mixed>
     */
    public function describe(string $absolutePath, ImportMapping $mapping): array;

    /**
     * The canonical grid implied by the decisions taken so far.
     *
     * Called again whenever those decisions change, and deliberately so: the
     * grid is a pure function of the file plus the mapping, and recomputing it is
     * what stops a stored grid and a stored mapping from describing two
     * different imports.
     */
    public function parseWith(string $absolutePath, string $originalFilename, ImportMapping $mapping): CanonicalCorrectionGrid;
}
