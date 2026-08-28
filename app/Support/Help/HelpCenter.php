<?php

namespace App\Support\Help;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
     * TOLERANT OF PORTUGUESE DIACRITICS: both the query and every haystack
     * are folded through `normalize()` before comparing, so "avaliaçao" and
     * "avaliação" match the same articles either way.
     *
     * Ranked by WHERE the match was found — title first, then keywords, then
     * summary, then content — not merely whether it matched, so a query that
     * names an article directly floats above one that merely mentions the
     * word in passing.
     *
     * @return Collection<int, HelpArticle>
     */
    public function search(string $query): Collection
    {
        $needle = trim(self::normalize($query));

        if ($needle === '') {
            return collect();
        }

        return $this->articles()
            ->map(fn (HelpArticle $article): array => ['article' => $article, 'score' => $this->score($article, $needle)])
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

    private function score(HelpArticle $article, string $needle): int
    {
        $score = 0;

        if (str_contains(self::normalize($article->title), $needle)) {
            $score += 8;
        }

        if (collect($article->keywords)->contains(fn (string $keyword): bool => str_contains(self::normalize($keyword), $needle))) {
            $score += 5;
        }

        if (str_contains(self::normalize($article->summary), $needle)) {
            $score += 3;
        }

        if (collect($article->content)->contains(fn (string $paragraph): bool => str_contains(self::normalize($paragraph), $needle))) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Lowercased and accent-folded, so "avaliaçao" and "avaliação" compare
     * equal. `Str::ascii()` and not iconv's TRANSLIT — same choice, and the
     * same reason, as `App\Support\Interventions\PedagogicalText::fold()`.
     */
    private static function normalize(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
