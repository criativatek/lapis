<?php

namespace App\Services\Characterisation\Import;

use App\Models\SupportMeasureLevel;
use App\Support\Characterisation\CodeConfidence;
use App\Support\Characterisation\LegalCodeResolver;

/**
 * Splits an imported cell's text into what is worth asking
 * {@see LegalCodeResolver} about — BEFORE it
 * ever sees the cell.
 *
 * THE BUG THIS EXISTS TO FIX: a dual-purpose column («Outras medidas/recursos
 * / Observações» — see ClassifiedColumn::$alsoFreeText) hands its ENTIRE
 * text to the resolver, because the resolver's own splitStatements() only
 * cuts on ';' and line breaks (deliberately — see its own docblock on why not
 * ',' or '+'). A teacher's ordinary sentence — "Teve alta da Terapia da
 * Fala." — is one statement to that splitter, and the resolver, finding no
 * code in it, reports the WHOLE SENTENCE back as an unrecognised legal code.
 * That is not a wrong answer, it is the wrong QUESTION: prose was never a
 * candidate to be a legal code, and asking made noise the preview then had
 * to show as if it meant something.
 *
 * THIS CLASS ANSWERS A DIFFERENT, NARROWER QUESTION: not "what does this
 * text mean" (LegalCodeResolver's job, and it stays that job alone — this
 * class must never grow into a second, competing interpreter of prose), but
 * "does this fragment have the SHAPE of a legal code" — an alínea letter
 * ("b)") or a run of at least two upper-case letters (an acronym shape,
 * "MU", "ACNS", or even an OCR misread like "ACN5"). Shape is all it
 * decides. It holds NO list of real siglas and NO list of real alíneas —
 * that would be the second dictionary the task forbids, and it would drift
 * from AcronymDictionary/DecreeLaw54CodeResolver the moment either one
 * learned a new word. Whether a shape-passing fragment actually MEANS
 * anything is still entirely LegalCodeResolver's decision, unchanged: this
 * class only decides what is worth asking it.
 *
 * SCOPE DECISION (documented here because it was asked for explicitly): this
 * separation is applied to EVERY column that reaches the resolver — Measures
 * AND Resources, alsoFreeText or not. A pure "MU"/"MS"/"MA" column is not
 * immune to prose: "MS b) Preencher ACNS" is a real, demonstrated case of a
 * teacher writing an instruction to herself inside a plain measures cell,
 * with no "Observações" in the header to mark it as dual-purpose. Restricting
 * this to alsoFreeText columns would have left that cell's "Preencher ACNS"
 * fragment turning into unresolved noise regardless — the exact defect this
 * class exists to remove, just gated on the wrong signal (the HEADER's
 * wording) instead of the right one (the CELL's own content). The one
 * documented trade-off this choice makes: a Measures/Resources cell that is
 * ENTIRELY prose, in a column NOT marked alsoFreeText, now produces NOTHING
 * at all in the preview — previously it produced a noisy, never-persisted
 * "unresolved" entry. That entry was never actionable (unresolved rows are
 * shown, never stored), so trading it for silence is judged the better
 * default; see BuildCharacterisationPreview::resolutionsFor() for where this
 * is wired in, and the adversarial-review note on the class that consumes
 * this one.
 *
 * WITHIN A STATEMENT, NOT JUST BETWEEN STATEMENTS. "MS b) Preencher ACNS" is
 * ONE statement (no ';' or line break in it) and STILL mixes a real code
 * prefix with prose and a trailing acronym. This class extracts the FIRST
 * contiguous run of code-shaped words in a statement and stops there — it
 * does not resume looking for a second run after prose has appeared once.
 * That means the trailing "ACNS" in that example is NOT extracted as a
 * second candidate: once a statement has shown it is being written as prose,
 * anything the resolver would need to reassemble out of what follows would
 * be this class GUESSING at context the school's shorthand never gave it
 * (was "ACNS" a second, unrelated measure, or was it part of the sentence
 * — "Preencher ACNS" the form? Nothing here can tell). "MS b)" is preserved
 * because the run leading into the prose is unambiguous; "ACNS" on the far
 * side of a sentence is not, and stays inside the free text a person reads,
 * never silently promoted into a legal fact nobody confirmed.
 *
 * A SECOND ORACLE, FOR THE ONE SHAPE THIS CLASS CANNOT SEE ON ITS OWN: the
 * diploma's own designations — "Os percursos curriculares diferenciados" —
 * are ordinary lower-case Portuguese, indistinguishable BY SHAPE from real
 * narrative prose ("Teve alta da Terapia da Fala"). No shape rule can tell
 * them apart, and hard-coding the diploma's wording here would be exactly
 * the second dictionary this class refuses to hold. So when a statement has
 * NOTHING code-shaped in it at all, this class asks the SAME
 * LegalCodeResolver it feeds candidates to — as a read-only oracle, on that
 * one statement, in isolation — "does this actually mean something", and
 * keeps the statement whole only if the answer is yes. This is not this
 * class quietly becoming a second interpreter of prose: it is still asking
 * the one interpreter that exists, only earlier and about a smaller
 * question ("would you recognise this at all") than the real, final call
 * BuildCharacterisationPreview makes with the joined candidates.
 */
class SeparateLegalCandidates
{
    public function __construct(
        private readonly LegalCodeResolver $resolver,
    ) {}

    /**
     * The only characters this class treats as "invisible" inside a
     * code-shaped run — never breaking it, never counted as code on their
     * own. "+", specifically, because DecreeLaw54CodeResolver::
     * splitStatements() documents "MS b) + ACNS" as ONE statement about one
     * measure on purpose; a run that stopped at "+" would silently drop
     * ACNS from a cell the resolver was always meant to read whole.
     */
    private const NEUTRAL_CONNECTORS = ['+'];

    /**
     * @param  SupportMeasureLevel|null  $columnLevel  Passed straight through to the
     *                                                 oracle probe (below) so a bare designation
     *                                                 under a level-named header is judged with
     *                                                 the same context the real resolver call
     *                                                 will have — never a stricter, contextless
     *                                                 guess that rejects what the real call would
     *                                                 have accepted.
     * @return list<string> Legal-candidate statements, in the cell's own
     *                      order, ready to be joined with "\n" and handed to
     *                      LegalCodeResolver::resolveCell(). Empty when the
     *                      cell had nothing code-shaped in it anywhere.
     *                      A statement that was ENTIRELY code-shaped comes
     *                      back byte-for-byte as it was written (trimmed
     *                      only) — never rebuilt from its words — so a
     *                      resolution's rawToken still reads exactly what
     *                      the school's file said.
     */
    public function candidatesFor(string $cell, ?SupportMeasureLevel $columnLevel = null): array
    {
        $candidates = [];

        foreach ($this->splitStatements($cell) as $statement) {
            $candidate = $this->leadingCodeRun($statement);

            if ($candidate !== null) {
                $candidates[] = $candidate;

                continue;
            }

            // Nothing code-SHAPED anywhere in this statement — the only case
            // where the designation oracle is asked at all. A statement that
            // already yielded a shape-based run (however partial) is never
            // re-examined here: see the class docblock on why a second run
            // is never resumed after prose has interrupted the first one.
            if ($this->wholeStatementIsRecognised($statement, $columnLevel)) {
                $candidates[] = $statement;
            }
        }

        return $candidates;
    }

    /**
     * Whether LegalCodeResolver, asked about this ENTIRE statement in
     * isolation, actually recognises something in it — a storable measure, a
     * resource, or any confidence other than the bare "I found nothing"
     * catch-all. That last distinction is why this is not simply "is the
     * result non-empty": DecreeLaw54CodeResolver::resolveStatement() ALWAYS
     * returns at least one CodeResolution, even for pure narrative prose —
     * its own final fallback, tagged Unrecognised with the note "Sem código
     * reconhecido". Treating THAT as recognition would undo the entire point
     * of this class.
     */
    private function wholeStatementIsRecognised(string $statement, ?SupportMeasureLevel $columnLevel): bool
    {
        foreach ($this->resolver->resolveCell($statement, $columnLevel) as $resolution) {
            if ($resolution->confidence !== CodeConfidence::Unrecognised || $resolution->isResource()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The SAME boundaries DecreeLaw54CodeResolver::splitStatements() cuts
     * on — ';' and line breaks only, never ',' or '+' — so this class and
     * the resolver never disagree about where one statement ends and the
     * next begins.
     *
     * @return list<string>
     */
    private function splitStatements(string $cell): array
    {
        $parts = preg_split('/[;\r\n]+/u', $cell) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    /**
     * The first maximal run of code-shaped words in the statement, or null
     * when none exists. Uses BYTE offsets from PREG_OFFSET_CAPTURE and a
     * plain (byte-safe) substr() — not mb_substr() — to cut the ORIGINAL
     * string back out: a UTF-8 word boundary never falls mid-character, so
     * the byte slice is always a valid, exact substring, and the school's
     * accents and punctuation survive untouched.
     */
    private function leadingCodeRun(string $statement): ?string
    {
        if (preg_match_all('/\S+/u', $statement, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        $start = null;
        $end = null;

        foreach ($matches[0] as [$word, $offset]) {
            $shape = $this->shapeOf($word);

            if ($shape === 'prose') {
                if ($start !== null) {
                    // A run had begun; prose ends it here. Anything further
                    // along the statement is not re-examined — see the class
                    // docblock on why a second run is never resumed.
                    break;
                }

                continue;
            }

            if ($shape === 'connector') {
                // Never starts a run, never breaks one already started;
                // simply passed through so it can be swallowed into the
                // eventual substring.
                continue;
            }

            $start ??= $offset;
            $end = $offset + strlen($word);
        }

        if ($start === null || $end === null) {
            return null;
        }

        return trim(substr($statement, $start, $end - $start));
    }

    /**
     * 'code': a run of two or more upper-case letters (an acronym shape,
     * confirmed or not — CRI, PLNM, XPTO, and an OCR misread like ACN5 all
     * pass, because deciding whether the shape MEANS anything is
     * LegalCodeResolver's job, not this one's), or a single letter
     * immediately followed by ')' (an alínea shape — "b)"). Folded the same
     * way DecreeLaw54CodeResolver::extractAnnotations() folds before
     * matching, so "B)" is recognised exactly as "b)" is.
     *
     * 'connector': see NEUTRAL_CONNECTORS.
     *
     * 'prose': everything else — in particular, any word containing so much
     * as one lower-case letter that is not the single letter of an alínea.
     * Ordinary Portuguese words (school prose is never written in ALL CAPS)
     * are lower-case almost everywhere except a capitalised first letter, so
     * this one test is what keeps "Redução", "Preencher" and "Terapia" out
     * of the resolver while still admitting "MU", "ACNS" and "1R"'s absence
     * from that admission (a single upper-case letter beside a digit is not
     * enough letters to be a plausible acronym — see the "1R 25/26" case in
     * the docblock above and its dedicated regression test).
     */
    private function shapeOf(string $word): string
    {
        if (in_array($word, self::NEUTRAL_CONNECTORS, true)) {
            return 'connector';
        }

        if (preg_match('/^[a-z]\)$/ui', $word) === 1) {
            return 'code';
        }

        if (preg_match('/\p{Ll}/u', $word) === 1) {
            return 'prose';
        }

        $uppercaseLetters = preg_replace('/[^\p{Lu}]/u', '', $word) ?? '';

        return mb_strlen($uppercaseLetters) >= 2 ? 'code' : 'prose';
    }
}
