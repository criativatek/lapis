<?php

namespace App\Support\Characterisation;

/**
 * Offers a plausible correction for a token OCR may have misread — §19.
 *
 * THE DICTIONARY IS THE ONLY CATALOGUE THIS CLASS CONSULTS. There is no
 * second list of legal codes here (§20 forbids one): every candidate a token
 * can be corrected TO is a token AcronymDictionary already carries, confirmed
 * or not — this class never invents a plausible-looking acronym of its own.
 *
 * THE EDIT DISTANCE IS DELIBERATELY SMALL AND EXPLAINABLE: at most one
 * character different (a substitution, insertion or deletion) — the shape an
 * O/0, S/5 or l/1 misread actually takes. A wider distance would start
 * matching tokens that merely resemble each other, which is worse than no
 * suggestion at all (see the class using this one for why an invented
 * suggestion invites a wrong acceptance).
 *
 * CALLED ONLY FOR A TOKEN THAT IS ALREADY UNRECOGNISED. A token the
 * dictionary already resolves is not corrected — there is nothing to correct
 * it TO that would change what it means.
 */
class SuggestAcronymCorrection
{
    /** Only single-character edits — see the class docblock. */
    private const MAX_DISTANCE = 1;

    public function __construct(
        private readonly AcronymDictionary $dictionary,
    ) {}

    /**
     * @return ?AcronymSuggestion Null when nothing in the dictionary is a
     *                            plausible near-miss — no suggestion is
     *                            always safer than a wrong one.
     */
    public function suggest(string $rawToken): ?AcronymSuggestion
    {
        $candidate = mb_strtoupper(trim($rawToken));

        // Only acronym-shaped tokens are candidates at all — a free-text
        // sentence is never "close to" ACNS in any sense worth acting on.
        // The same shape DecreeLaw54CodeResolver::tokens() already looks for.
        if (preg_match('/^[A-Z0-9]{2,6}$/u', $candidate) !== 1) {
            return null;
        }

        $best = null;
        $bestDistance = self::MAX_DISTANCE + 1;

        foreach ($this->dictionary->all() as $normalisedToken => $entry) {
            if ($normalisedToken === $candidate) {
                // Already spelled correctly — the caller would not have
                // reached "unrecognised" for this token if it were merely a
                // dictionary lookup away, but this guards the invariant
                // directly rather than trusting the caller to have checked.
                continue;
            }

            $distance = levenshtein($candidate, $normalisedToken);

            if ($distance < $bestDistance) {
                $best = $entry;
                $bestDistance = $distance;
            }
        }

        if ($best === null || $bestDistance > self::MAX_DISTANCE) {
            return null;
        }

        return new AcronymSuggestion(token: $best->token, expansion: $best->expansion);
    }
}
