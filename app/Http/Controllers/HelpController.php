<?php

namespace App\Http\Controllers;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Centro de Ajuda (A2, Onboarding & Help) — a static, authored knowledge
 * base, not tenant data. Like ChangelogController, this sits under
 * `auth`+`verified` only (see routes/web.php), with no `organization`
 * middleware: every organization reads the exact same article set.
 *
 * Every method below reads only its own request input (a query string, a
 * route parameter) against HelpCenter's STATIC article content — see
 * HelpCenter's own docblock for why that makes §11 (never receive, store or
 * log student data) true by construction here, not merely by care.
 */
class HelpController extends Controller
{
    public function __construct(protected HelpCenter $helpCenter) {}

    public function index(): Response
    {
        return Inertia::render('help/Index', [
            'categories' => $this->helpCenter->categories()
                ->map(fn ($articles, string $category): array => [
                    'category' => $category,
                    'articles' => $articles->map(fn (HelpArticle $article): array => $article->toArray())->values()->all(),
                ])
                ->values(),
        ]);
    }

    /**
     * `q` is the ONLY request input this method ever reads — never the rest
     * of the query string, never a request body. See
     * `tests/Feature/Help/HelpPrivacySearchTest.php`, which asserts exactly
     * that.
     */
    public function search(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));

        return Inertia::render('help/Search', [
            'query' => $query,
            'results' => $query === ''
                ? []
                : $this->helpCenter->search($query)->map(fn (HelpArticle $article): array => $article->toArray())->values()->all(),
        ]);
    }

    /**
     * Bound by a plain string, not Eloquent route-model-binding — an article
     * is a file, not a model. A non-existent id 404s cleanly rather than
     * throwing.
     */
    public function show(string $article): Response
    {
        $found = $this->helpCenter->find($article);

        abort_if($found === null, 404);

        return Inertia::render('help/Show', [
            'article' => $found->toArray(),
            'related' => collect($found->related)
                ->map(fn (string $id): ?HelpArticle => $this->helpCenter->find($id))
                ->filter()
                ->map(fn (HelpArticle $article): array => $article->toArray())
                ->values()
                ->all(),
        ]);
    }
}
