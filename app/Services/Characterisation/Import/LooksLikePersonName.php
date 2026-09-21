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
    public static function check(string $cell): bool
    {
        $trimmed = trim($cell);

        if ($trimmed === '' || preg_match('/\d/u', $trimmed) === 1) {
            return false;
        }

        return preg_match('/^\p{Lu}[\p{Ll}\'-]+(\s+(d[aeo]s?|e)\s+|\s+)\p{Lu}[\p{Ll}\'-]+/u', $trimmed) === 1;
    }
}
