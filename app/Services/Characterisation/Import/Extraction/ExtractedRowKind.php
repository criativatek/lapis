<?php

namespace App\Services\Characterisation\Import\Extraction;

/**
 * What a row of an extracted table turned out to be, decided BEFORE the table
 * is flattened into a TableGrid.
 *
 * This is the classification that keeps «Alunos com RTP» from becoming a
 * student called «Alunos com RTP», and keeps a trailing legend from becoming
 * one more row of nonsense data. Deciding it here, on the shape of the whole
 * table, is the only place it can be decided correctly — by the time rows are
 * flattened into TableGrid::rows the distinction no longer exists.
 */
enum ExtractedRowKind: string
{
    /** Names the columns. There can be more than one, joined top-to-bottom. */
    case Header = 'header';

    /** A student's row. The only kind that reaches TableGrid::rows. */
    case Data = 'data';

    /** A caption grouping the rows that follow it, naming no student. */
    case Group = 'group';

    /** A trailing caption/key, explaining abbreviations used above it. */
    case Legend = 'legend';

    /** Not yet decided; treated as Data unless something says otherwise. */
    case Unknown = 'unknown';
}
