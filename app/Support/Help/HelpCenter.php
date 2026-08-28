<?php

namespace App\Support\Help;

use Illuminate\Support\Collection;

/**
 * The Centro de Ajuda's read side (A2, Onboarding & Help) — a typed reader
 * over `resources/help/articles/*.php`, the same "static file as source +
 * typed reader class on top" convention `App\Support\Retention\RetentionPolicy`
 * (over `config/retention.php`) and `App\Support\Trial\TrialPolicy` (over
 * `config/trial.php`) already use. Articles live one-per-file rather than in
 * a single config array because each carries its own stable id (the
 * filename — see `HelpArticle`'s own docblock) and the set is expected to
 * grow; no Markdown parser, no database table, every article git-reviewable
 * and PHPStan-checked like the rest of this codebase's content.
 *
 * THIS CLASS IS THE A3 PREPARATION the Onboarding & Help brief asks for. A
 * future AI assistant answering "how do I…" questions is not a second
 * reading of the article set — it is another CALLER of `search()` and
 * `forContext()`, the exact same methods the Centro de Ajuda's own
 * controller uses today. Nothing about assistant integration is built here;
 * what is built is that there is only ever one place that knows how an
 * article is found, so the assistant (when it exists) and the Centro de
 * Ajuda page can never disagree about what a query or a context matches.
 *
 * PRIVACY (§11): every method below reads only its own scalar argument (a
 * query string, a route name, an id) against STATIC, authored article
 * content. Nothing here ever sees a request body, a student, or any
 * pedagogical data — there is nothing in this class's inputs that COULD
 * carry it.
 */
final class HelpCenter
{
    /**
     * Where a match was found, and what it is worth. Ordered by weight
     * descending — `score()` walks the fields in this order and keeps the
     * first (best) one a term reaches, so the order is load-bearing.
     */
    private const WEIGHTS = [
        'title' => 8,
        'keywords' => 5,
        'summary' => 3,
        'content' => 1,
    ];

    public function __construct(private HelpSearchVocabulary $vocabulary = new HelpSearchVocabulary) {}

    /**
     * Every article, sorted by category then by its own `order` — the order
     * `all()`, `categories()` and `forContext()` all present articles in.
     *
     * @return Collection<string, HelpArticle>
     */
    public function all(): Collection
    {
        return $this->articles();
    }

    public function find(string $id): ?HelpArticle
    {
        return $this->articles()->get($id);
    }

    /**
     * Simple, in-memory, diacritic-tolerant matching across title, summary,
     * keywords and content — no external search engine, no debounce
     * infrastructure: the whole article set is a few dozen entries at most,
     * so a full scan on every request costs nothing worth optimizing away.
     *
     * Ranked by WHERE the match was found — title first, then keywords, then
     * summary, then content — not merely whether it matched, so a query that
     * names an article directly floats above one that merely mentions the
     * word in passing.
     *
     * TWO PASSES, AND THE FIRST ONE IS THE OLD BEHAVIOUR UNCHANGED. The whole
     * folded query is still matched as a literal phrase against every field
     * and scored exactly as it always was, so a search that worked before
     * this method learned to read questions still finds the same articles
     * and still ranks them the same way against each other.
     *
     * The second pass is what makes a written question findable. Until now
     * the query was one literal string, which answered «avaliação» and stayed
     * silent for «por onde começo» — not because the answer was missing, but
     * because a question is not a substring of anything. So the query is also
     * reduced to its meaningful terms (`HelpSearchVocabulary::terms()`), each
     * term widened to the words it is interchangeable with (`expand()`), and
     * each term scores the BEST field it reaches — best, not sum, so a word
     * repeated through a body cannot outweigh a word in a title. «por onde
     * começo» reduces to «começo», widens to «começar», and lands on the
     * title of «Começar a utilizar o Lapispro».
     *
     * TOLERANT OF PORTUGUESE DIACRITICS, and now of punctuation and of the
     * scaffolding of a question: query and haystack are both folded through
     * `normalize()`, so "avaliaçao", "avaliação" and "Avaliação?" match the
     * same articles either way.
     *
     * @return Collection<int, HelpArticle>
     */
    public function search(string $query): Collection
    {
        $phrase = self::normalize($query);

        if ($phrase === '') {
            return collect();
        }

        $terms = $this->vocabulary->terms($phrase);

        return $this->articles()
            ->map(fn (HelpArticle $article): array => ['article' => $article, 'score' => $this->score($article, $phrase, $terms)])
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc(fn (array $row): int => $row['score'])
            ->map(fn (array $row): HelpArticle => $row['article'])
            ->values();
    }

    /**
     * Every article that names $routeName in its own `contexts` — what a
     * page's controller reads to fill a `ContextualHelp` block's "Precisa de
     * ajuda?" links (see the component's own docblock).
     *
     * @return Collection<int, HelpArticle>
     */
    public function forContext(string $routeName): Collection
    {
        return $this->articles()
            ->filter(fn (HelpArticle $article): bool => in_array($routeName, $article->contexts, true))
            ->values();
    }

    /**
     * Every article, grouped by category, each group already sorted by
     * `order` — exactly what the Centro de Ajuda's index page renders.
     *
     * @return Collection<string, Collection<int, HelpArticle>>
     */
    public function categories(): Collection
    {
        return $this->articles()
            ->values()
            ->groupBy(fn (HelpArticle $article): string => $article->category);
    }

    /**
     * Loads and parses every `resources/help/articles/*.php` file, keyed by
     * id (the filename) and sorted by category then `order`. Re-read on
     * every call rather than cached: the article set is a few dozen small
     * PHP files at most, `glob()` plus a handful of `require`s is not a cost
     * worth guarding against, and a stateless reader is the same trade-off
     * `RetentionPolicy`'s own docblock already makes.
     *
     * @return Collection<string, HelpArticle>
     */
    private function articles(): Collection
    {
        $paths = glob(resource_path('help/articles/*.php')) ?: [];

        return collect($paths)
            ->mapWithKeys(function (string $path): array {
                $id = basename($path, '.php');
                /** @var array<string, mixed> $data */
                $data = require $path;

                return [$id => HelpArticle::fromArray($id, $data)];
            })
            ->sortBy([
                fn (HelpArticle $a, HelpArticle $b): int => $a->category <=> $b->category,
                fn (HelpArticle $a, HelpArticle $b): int => $a->order <=> $b->order,
            ]);
    }

    /**
     * @param  list<string>  $terms
     */
    private function score(HelpArticle $article, string $phrase, array $terms): int
    {
        /** @var array<string, list<string>> $fields */
        $fields = [
            'title' => [self::normalize($article->title)],
            'keywords' => array_map(self::normalize(...), $article->keywords),
            'summary' => [self::normalize($article->summary)],
            'content' => array_map(self::normalize(...), $article->content),
        ];

        $phraseScore = 0;

        foreach ($fields as $field => $values) {
            foreach ($values as $value) {
                if (str_contains($value, $phrase)) {
                    $phraseScore += self::WEIGHTS[$field];

                    break;
                }
            }
        }

        $termScore = 0;
        $matchedTerms = 0;
        $matchedStrongly = false;

        foreach ($terms as $term) {
            $variants = $this->vocabulary->expand($term);
            $best = 0;

            foreach ($fields as $field => $values) {
                if (self::WEIGHTS[$field] <= $best) {
                    continue;
                }

                if (self::matches($values, $variants)) {
                    $best = self::WEIGHTS[$field];
                }
            }

            if ($best > 0) {
                $termScore += $best;
                $matchedTerms++;
                $matchedStrongly = $matchedStrongly || $best >= self::WEIGHTS['keywords'];
            }
        }

        // A literal match is evidence on its own — that is the pre-existing
        // behaviour, and nothing below is allowed to gate it.
        if ($phraseScore > 0) {
            return $phraseScore + $termScore;
        }

        // THE OVERLAP GATE, and the reason widening terms does not turn this
        // into a random article generator. Reaching an article through ONE
        // term that merely appears somewhere in its body text is not a
        // result, it is a coincidence: «xyzzy nada parecido» finds the word
        // "existe" inside a sentence about turmas and means nothing by it.
        // So a lone term has to land where an article declares what it is
        // ABOUT — its title or its keywords — and anything weaker needs a
        // second term to corroborate it.
        if (! $matchedStrongly && $matchedTerms < 2) {
            return 0;
        }

        return $termScore;
    }

    /**
     * True when any variant starts a word in any of $values. Prefix-at-a-
     * word-boundary rather than a bare `str_contains`, which is what keeps
     * «ano» out of "plano" while still letting «turma» reach "turmas" and
     * «escolar» reach "escolaridade". Both sides are folded to `[a-z0-9 ]*`
     * first, so `\b` has no surprises left in it.
     *
     * @param  list<string>  $values
     * @param  list<string>  $variants
     */
    private static function matches(array $values, array $variants): bool
    {
        foreach ($values as $value) {
            foreach ($variants as $variant) {
                if (preg_match('/\b'.preg_quote($variant, '/').'/', $value) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Lowercased, accent-folded and stripped of punctuation, so "avaliaçao",
     * "avaliação" and "Avaliação?" all compare equal. Delegates to
     * `HelpSearchVocabulary::fold()` so the query and the articles are
     * reduced by exactly the same rule — two folds drifting apart is a class
     * of bug that stays invisible until a search quietly stops matching.
     */
    private static function normalize(string $value): string
    {
        return HelpSearchVocabulary::fold($value);
    }
}
