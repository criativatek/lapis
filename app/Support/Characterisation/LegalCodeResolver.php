<?php

namespace App\Support\Characterisation;

use App\Models\SupportMeasureLevel;

/**
 * Turns what a school wrote in a spreadsheet cell into what this application is
 * willing to say it means.
 *
 * This interface is the seam. The implementation bound today reads the
 * Decreto-Lei 54/2018 enums that already live in app/Models. When the versioned
 * legal catalogue lands, it binds a different implementation here and nothing
 * else in the characterisation feature changes — no parser, no preview, no
 * controller, no table.
 *
 * That is deliberate: this feature must not grow a second, competing catalogue
 * while the real one is being designed elsewhere.
 *
 * NOT THE SAME THING AS LegalFrameworkResolver, and it does not replace it.
 * That one answers «which law applies to this organization on this date»; this
 * one answers «what does this piece of school shorthand mean». They compose:
 * the implementation asks that resolver first, and says nothing at all when the
 * answer is a jurisdiction with no legal taxonomy.
 */
interface LegalCodeResolver
{
    /**
     * @param  string  $cell  The raw cell contents, e.g. "MS b) + ACNS".
     * @param  SupportMeasureLevel|null  $columnLevel  The level implied by the column
     *                                                 header, when the header named one. This is what
     *                                                 lets a bare "b)" mean something — and its absence
     *                                                 is what keeps a bare "b)" ambiguous.
     * @return list<CodeResolution>
     */
    public function resolveCell(string $cell, ?SupportMeasureLevel $columnLevel = null): array;
}
