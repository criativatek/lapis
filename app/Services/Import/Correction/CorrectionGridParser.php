<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CorrectionGridSource;

/**
 * Reads one export format and says it in the canonical vocabulary. Nothing else.
 *
 * A parser NEVER touches Instrument, InstrumentGroup, InstrumentItem,
 * DomainAllocation, Enrollment or StudentItemScore. It does not know they exist.
 * That restraint is what lets a second and third format arrive without either
 * one's assumptions leaking into the assessment model — and what makes the
 * awkward parts of a format (a title row, a URL row, an answer-key row) stay
 * inside the file's own vocabulary instead of becoming rules elsewhere.
 *
 * A parser is also allowed to fail honestly: a file it cannot read produces a
 * grid carrying an Error issue, not a grid full of guesses.
 */
interface CorrectionGridParser
{
    /**
     * Which source this parser reads. One parser per source.
     */
    public function source(): CorrectionGridSource;

    /**
     * File extensions this parser accepts, lowercase and without the dot.
     *
     * @return list<string>
     */
    public function extensions(): array;

    /**
     * MIME types this parser accepts. Checked alongside the extension, because
     * an extension is a claim and a MIME type is evidence — and neither alone
     * is enough (§24).
     *
     * @return list<string>
     */
    public function mimeTypes(): array;

    /**
     * Whether this parser recognises the file well enough to attempt it. Cheap
     * and structural: a look at the shape, not a full parse.
     */
    public function supports(string $absolutePath, string $originalFilename): bool;

    /**
     * @param  string  $absolutePath  The stored upload. Read-only; a parser never writes.
     * @param  string  $originalFilename  Metadata only — never trusted to decide anything.
     */
    public function parse(string $absolutePath, string $originalFilename): CanonicalCorrectionGrid;
}
