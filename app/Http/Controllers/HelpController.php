<?php

namespace App\Http\Controllers;

use App\Services\Help\Ai\HelpAssistant;
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
 *
 * THE ASSISTANT DOES NOT LIVE HERE, and the paragraph above is why: asking an
 * engine is not reading a file, so it is `HelpAssistantController` that makes
 * that call. What this controller does carry is the two things the PAGE needs
 * in order to draw the assistant honestly — whether it is available at all,
 * and the transient answer to the last question. Neither reads anything but
 * the availability resolver and the session.
 */
class HelpController extends Controller
{
    public function __construct(
        protected HelpCenter $helpCenter,
        protected HelpAssistant $assistant,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('help/Index', [
            'categories' => $this->helpCenter->categories()
                ->map(fn ($articles, string $category): array => [
                    'category' => $category,
                    'articles' => $articles->map(fn (HelpArticle $article): array => $article->toArray())->values()->all(),
                ])
                ->values(),
            ...$this->assistantProps($request),
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
            ...$this->assistantProps($request),
        ]);
    }

    /**
     * What a page needs to draw the assistant: its state, and the answer to
     * the last question if one was just asked.
     *
     * THE ANSWER COMES OUT OF THE SESSION, exactly as `student-progress`
     * already reads `aiSuggestion` — it is flash data put there by
     * `HelpAssistantController` on its way back, and it is gone on the next
     * visit. Nothing here queries anything, and nothing here stores anything.
     *
     * @return array<string, mixed>
     */
    protected function assistantProps(Request $request): array
    {
        return [
            'ai' => [
                'available' => $this->assistant->isAvailable(),
                'reason' => $this->assistant->unavailableReason(),
            ],
            'helpAnswer' => $request->session()->get('helpAnswer'),
            'helpAnswerError' => $request->session()->get('helpAnswerError'),
        ];
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
