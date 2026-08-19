<?php

namespace App\Support\Interventions;

use Illuminate\Support\Str;

/**
 * Whether a stored string is something a teacher wrote, or something the system
 * left behind.
 *
 * THIS EXISTS BECAUSE OF ONE REAL ROW. An intervention imported before the
 * module had types carries `title = "Legado sem dominio"` and
 * `description = "x"` — a label an old process generated to fill a NOT NULL
 * column, and a single character somebody typed to get past a required field.
 * Both are stored, both are history, and neither is a fact about a child. Shown
 * in a list they read as a pedagogical category and a pedagogical observation,
 * which is exactly what they are not.
 *
 * TWO RULES, BOTH NARROW ON PURPOSE (§6). A blind filter — hiding every short
 * string, or every string containing «legado» — would eventually swallow
 * something a teacher meant. So:
 *
 *   a technical label   is one of a short, named list of phrases this
 *                       application is known to have generated. The list is
 *                       here, in one place, and it is read rather than guessed.
 *
 *   a placeholder       is a string with no word in it: «x», «-», «...», «??».
 *                       One letter is not a word, and no observation about a
 *                       student has ever been one character long.
 *
 * Everything else is the teacher's, however short, and is passed through
 * untouched. «Ok» survives. «Falta» survives. Nothing is rewritten, nothing is
 * deleted, and the database is not touched at all — this is a reading rule, and
 * the row keeps saying whatever it says (§4).
 */
class PedagogicalText
{
    /**
     * Labels this application generated to fill a column, and that were never
     * meant to be read by anybody.
     *
     * Compared case-insensitively and without accents, so «Legado sem dominio»
     * and «Legado sem domínio» are one entry. Kept deliberately short: a phrase
     * only belongs here once it is known to be machine-written.
     *
     * @var list<string>
     */
    protected const TECHNICAL_LABELS = [
        'legado sem dominio',
        'legado',
        'sem dominio',
        'sem dominio especifico',
        'sem tipo',
        'tipo nao especificado',
        'null',
        'unknown',
        'n/a',
        'na',
    ];

    /**
     * The text if it means something, or null.
     *
     * Null is the answer a screen wants: it renders nothing, rather than
     * rendering a sentence about the absence. An absence of information is not
     * a pedagogical category (§14).
     */
    public static function meaningful(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (in_array(self::fold($value), self::TECHNICAL_LABELS, strict: true)) {
            return null;
        }

        // A word, defined as two or more letters in a row. «x» is a letter and
        // not a word; «Ok» is a word.
        if (preg_match('/\p{L}{2,}/u', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * Lowercased and accent-folded, so one entry covers its spellings.
     *
     * `Str::ascii()` and not iconv's TRANSLIT: iconv's transliteration depends
     * on the platform's locale tables, and on Windows «domínio» comes back as
     * «dom?nio» rather than «dominio» — which is how the accented spelling
     * walked past this list the first time. The same helper the AI guard folds
     * its lexicon with.
     */
    protected static function fold(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::lower(trim(Str::ascii($collapsed)));
    }
}
