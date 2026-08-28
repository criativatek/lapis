<?php

namespace App\Support\Help;

use Illuminate\Support\Str;

/**
 * The typed reader over `config/help-search.php` — the stopwords and synonym
 * groups `HelpCenter::search()` turns a written question into terms with.
 * Same "static file as source + typed reader class on top" convention
 * `App\Support\Retention\RetentionPolicy` (over `config/retention.php`) and
 * `HelpCenter` itself already use.
 *
 * WHY THIS EXISTS. The Centro de Ajuda's search matched the query as one
 * literal string, so it answered «avaliação» and stayed silent for «por onde
 * começo», «o que faço primeiro» and «quais são as primeiras coisas a fazer
 * na aplicação?» — questions the «Começar a utilizar o Lapispro» article
 * answers in full. The gap was never the article set; it was that a written
 * question is not a substring of anything.
 *
 * WHAT IT IS NOT. There is no model here, no embedding, no external call and
 * no learning: `fold()`, `terms()` and `expand()` are pure functions of the
 * config file, so the same query always yields the same terms and the same
 * ranking. When search misses something, the fix is a word in the config —
 * reviewable in a diff, and reversible.
 *
 * PRIVACY (§11): the only input is a query string the user typed. Nothing
 * here reads, stores or logs anything else — see `HelpCenter`'s own docblock.
 */
final class HelpSearchVocabulary
{
    /**
     * Below this, a term is scaffolding rather than a subject: folding turns
     * «8.º» into «8 o», and a one- or two-letter fragment matches far too
     * much to be evidence of anything.
     */
    private const MIN_TERM_LENGTH = 3;

    /** @var array<string, list<string>>|null Folded term => every term it is interchangeable with. */
    private ?array $synonyms = null;

    /** @var array<string, true>|null Folded stopword => true, for O(1) lookup. */
    private ?array $stopwords = null;

    /**
     * Lowercased, accent-folded, and stripped of everything that is not a
     * letter or a digit, so «Avaliação?» and «avaliacao» compare equal and
     * punctuation never splits a match. `Str::ascii()` and not iconv's
     * TRANSLIT — same choice, and the same reason, as
     * `App\Support\Interventions\PedagogicalText::fold()`.
     *
     * The result is always `[a-z0-9 ]*` with single spaces and no edges,
     * which is what lets `HelpCenter` rely on `\b` meaning what it looks
     * like it means.
     */
    public static function fold(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
    }

    /**
     * The written question reduced to the words worth matching on: folded,
     * split, stripped of stopwords and of fragments too short to mean
     * anything, and deduplicated with the first occurrence winning.
     *
     * Returns an empty list for a question made entirely of scaffolding
     * («como?»). That is not a failure — `HelpCenter` still matches the
     * phrase itself, exactly as it did before this class existed.
     *
     * @return list<string>
     */
    public function terms(string $query): array
    {
        $stopwords = $this->stopwords();
        $synonyms = $this->synonyms();

        /** @var array<string, string> $terms Canonical form => the word actually typed. */
        $terms = [];

        foreach (explode(' ', self::fold($query)) as $word) {
            if ($word === '' || isset($stopwords[$word]) || strlen($word) < self::MIN_TERM_LENGTH) {
                continue;
            }

            // Two words from the same synonym group are ONE piece of
            // evidence, not two. Without this, «primeiros passos» clears
            // HelpCenter's corroboration gate purely by saying the same
            // thing twice — both words widen to the same set, so any article
            // one of them reaches the other reaches too, and every article
            // with «primeiro» loose in its body text would come back as a
            // corroborated result.
            $terms[$synonyms[$word][0] ?? $word] ??= $word;
        }

        return array_values($terms);
    }

    /**
     * Every term $term is interchangeable with, itself included — the whole
     * synonym group when it belongs to one, and just itself when it does not.
     *
     * @return list<string>
     */
    public function expand(string $term): array
    {
        return $this->synonyms()[$term] ?? [$term];
    }

    /**
     * Config groups are symmetric ("these all mean the same thing here"), so
     * they are flattened into a term => group map once and read from there.
     * A term listed in two groups gets the union of both, which is what a
     * reader of the config would expect and costs nothing to support.
     *
     * @return array<string, list<string>>
     */
    private function synonyms(): array
    {
        if ($this->synonyms !== null) {
            return $this->synonyms;
        }

        /** @var list<list<string>> $configured */
        $configured = config('help-search.synonyms', []);

        /** @var array<string, list<string>> $synonyms */
        $synonyms = [];

        foreach ($configured as $group) {
            $folded = array_values(array_unique(array_filter(array_map(self::fold(...), $group))));

            foreach ($folded as $term) {
                $synonyms[$term] = array_values(array_unique([...($synonyms[$term] ?? []), ...$folded]));
            }
        }

        return $this->synonyms = $synonyms;
    }

    /**
     * @return array<string, true>
     */
    private function stopwords(): array
    {
        if ($this->stopwords !== null) {
            return $this->stopwords;
        }

        /** @var list<string> $configured */
        $configured = config('help-search.stopwords', []);

        /** @var array<string, true> $stopwords */
        $stopwords = [];

        foreach ($configured as $word) {
            $folded = self::fold($word);

            if ($folded !== '') {
                $stopwords[$folded] = true;
            }
        }

        return $this->stopwords = $stopwords;
    }
}
