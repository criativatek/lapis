<?php

namespace App\Services\Characterisation\Import;

/**
 * Two or more capitalised words, no digits — the shape a person's name takes
 * throughout this importer.
 *
 * PROMOTED OUT OF NormaliseExtractedTable, WHERE IT WAS BORN, SO THIS IS THE
 * ONLY PLACE THE SHAPE OF A NAME IS DEFINED. It started as a private helper
 * used only to rule a cell OUT of looking like a caption or a legend key —
 * deliberately loose, because a false positive there merely keeps a row as
 * Data, which is always the safe direction (see the callers still in
 * NormaliseExtractedTable for that use). ClassifyColumns::classify() now
 * reuses the exact same check to INFER an unlabelled name column from its
 * cell content (see that method's own comment on why), and a second,
 * independently-drifting "does this look like a name" regex living in two
 * files would be exactly the kind of bug this codebase's own conventions
 * warn against elsewhere (see e.g. ClassifyColumns::FREE_TEXT_FRAGMENTS).
 */
class LooksLikePersonName
{
    /**
     * JANELA N: the enumerator a class list prints in front of every name is
     * stripped FIRST — «01 - Bento Quaresma», «15 – Alda Varela», «03. Duarte
     * Alves». The digit veto below is what keeps a year or a grade out of
     * this check, and it was also, silently, throwing away every student in
     * a numbered class list: the column then looked like no name column at
     * all, so an entire real table answered «todas as linhas foram
     * identificadas como rodapé ou totais» with twelve perfectly readable
     * students in it. Stripped here, in the ONE place the shape of a name
     * is defined, rather than at each caller — see the class docblock.
     *
     * Only a leading run of at most three digits followed by a separator
     * goes: enough for a class number, never enough for a year («2026/2027»
     * has no separator after the fourth digit and stays disqualified).
     */
    public static function check(string $cell): bool
    {
        $trimmed = trim((string) preg_replace('/^\d{1,3}\s*[-\x{2013}\x{2014}.)]\s*/u', '', trim($cell)));

        if ($trimmed === '' || preg_match('/\d/u', $trimmed) === 1) {
            return false;
        }

        return preg_match('/^\p{Lu}[\p{Ll}\'-]+(\s+(d[aeo]s?|e)\s+|\s+)\p{Lu}[\p{Ll}\'-]+/u', $trimmed) === 1;
    }
}
