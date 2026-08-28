<?php

namespace App\Support\Privacy;

/**
 * Names out before text leaves the building, names back after it returns.
 *
 * PSEUDONYMISATION, AND THE WORD IS CHOSEN CAREFULLY (§5 of the AI Core brief).
 * This is not anonymisation and must never be called that. «Aluno A» is
 * reversible — this very class reverses it, three lines below — and the mapping
 * that reverses it exists in memory on this machine for the duration of one
 * request. Under Article 4(5) GDPR that is pseudonymised personal data: the
 * remote system holds a letter, the responsibility stays here, and calling it
 * «anonymous» in a privacy notice would be a false statement to a data subject.
 *
 * WHY IT LIVES IN Support\Privacy AND NOT IN THE FEATURE THAT FIRST NEEDED IT.
 * The algorithm below was written for `App\Services\Reporting\Writing\PseudonymMap`,
 * which still exists and still owns the Reporting-specific question of WHICH
 * names a report is about (its roster, plus the student an individual report
 * concerns). What it no longer owns is HOW a name becomes a pseudonym, because
 * the AI core needs the same rules for material that has nothing to do with a
 * report. One implementation, two entry points — `PseudonymMap` delegates here
 * and its public behaviour is unchanged.
 *
 * THE RULES, AND WHY EACH IS A COMPROMISE:
 *
 *   Longest first          «Maria Silva Costa» is substituted before «Maria», so
 *                          a full name is never half-replaced.
 *
 *   First names included   A teacher writes «a Maria», not «a Maria Silva
 *                          Costa». A map that only knew full names would cover
 *                          almost nothing of what people actually type.
 *
 *   Short tokens excluded  «Ana» is a name; so is the risk of replacing a
 *                          preposition. Single tokens under four characters are
 *                          left to the whole-word boundary and to the notice on
 *                          the screen, rather than mangling every sentence
 *                          containing «dos».
 *
 *   Whole words only       A name that is a substring of an ordinary word is not
 *                          a name in that word.
 *
 * WHAT IT CANNOT DO, WRITTEN DOWN RATHER THAN HOPED AWAY. It only knows the
 * names it was given. A sibling, a colleague, a student from another class typed
 * into a free-text field is a name this map has never seen. That is why
 * `AiPayloadSanitizer` runs pattern-based identifier removal AFTER this, why it
 * verifies rather than assumes, and why the screens that can carry names say so
 * out loud.
 */
final readonly class Pseudonyms
{
    /** Shorter than this and a name token is left alone. See the class docblock. */
    private const MINIMUM_TOKEN_LENGTH = 4;

    /**
     * PRIVATE, LIKE `SanitisedPayload`'s, AND FOR THE SAME REASON. A map handed
     * in from outside is a map nobody built by the rules above — it could be
     * empty when it should not be, or map a name to itself. `of()` and `none()`
     * are the two ways in, and `none()` says «no people are involved here» out
     * loud rather than by passing an empty array that might be an oversight.
     *
     * @param  array<string, string>  $byName  the real name => «Aluno A»
     */
    private function __construct(public array $byName) {}

    /** Nothing to substitute. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Build a map from a list of real names.
     *
     * The pseudonym is positional and ephemeral: the first name given becomes
     * «Aluno A», the second «Aluno B». It carries no information about the
     * person and is not stable between requests — two calls about the same class
     * in a different order produce different letters, which is the intended
     * property. A stable pseudonym is a pseudonymous identifier, and a
     * pseudonymous identifier accumulated across requests is a profile.
     *
     * @param  list<string>  $names
     */
    public static function of(array $names, string $prefix = 'Aluno'): self
    {
        $map = [];
        $next = 'A';

        foreach (array_values(array_unique(array_map('trim', $names))) as $name) {
            if ($name === '') {
                continue;
            }

            $pseudonym = $prefix.' '.$next;
            $next++;

            $map[$name] = $pseudonym;

            foreach (preg_split('/\s+/u', $name) ?: [] as $part) {
                if (mb_strlen($part) >= self::MINIMUM_TOKEN_LENGTH && ! array_key_exists($part, $map)) {
                    $map[$part] = $pseudonym;
                }
            }
        }

        // Longest first, so «Maria Silva Costa» is never half-replaced by the
        // entry for «Maria».
        uksort($map, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return new self($map);
    }

    public function isEmpty(): bool
    {
        return $this->byName === [];
    }

    /** Replace every name this map knows with its pseudonym. */
    public function apply(string $text): string
    {
        foreach ($this->byName as $name => $pseudonym) {
            $text = (string) preg_replace($this->pattern($name), $pseudonym, $text);
        }

        return $text;
    }

    /**
     * Put the names back.
     *
     * The fullest name that maps to a pseudonym wins, so a rewrite that kept
     * «Aluno A» restores the whole name rather than a fragment of it.
     */
    public function rehydrate(string $text): string
    {
        foreach ($this->fullNames() as $pseudonym => $name) {
            $text = str_replace($pseudonym, $name, $text);
        }

        return $text;
    }

    /**
     * Whether any name this map knows about survived the substitution.
     *
     * ASSERTED RATHER THAN ASSUMED. The substitution is a regex over text this
     * code did not write, and «it cannot happen» is not something to find out
     * from a support ticket.
     */
    public function coversEverythingIn(string $text): bool
    {
        foreach (array_keys($this->byName) as $name) {
            if (preg_match($this->pattern($name), $text) === 1) {
                return false;
            }
        }

        return true;
    }

    /** Whole-word, Unicode-aware, and not fooled by accents or by punctuation. */
    protected function pattern(string $name): string
    {
        return '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u';
    }

    /**
     * pseudonym => the fullest name that maps to it.
     *
     * @return array<string, string>
     */
    protected function fullNames(): array
    {
        $names = [];

        foreach ($this->byName as $name => $pseudonym) {
            if (! array_key_exists($pseudonym, $names) || mb_strlen($name) > mb_strlen($names[$pseudonym])) {
                $names[$pseudonym] = $name;
            }
        }

        return $names;
    }
}
