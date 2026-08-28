<?php

namespace App\Support\Help;

/**
 * One help article, read from `resources/help/articles/{id}.php` (§7 of the
 * Onboarding & Help brief).
 *
 * THE ID IS THE FILENAME, never a field inside the array. «classes.create»
 * names `resources/help/articles/classes.create.php` — one file per article,
 * git-reviewable and PHPStan-checked, no Markdown parser and no database
 * table (see HelpCenter's own docblock for the full rationale). Stable and
 * dot-namespaced on purpose: it is what `related`, `contexts` and a future
 * AI assistant address an article by, and it must never be derived from the
 * title, which is free to change.
 *
 * `content` IS A LIST OF PARAGRAPHS, not a single HTML/Markdown string —
 * the same shape `App\Support\Legal\LegalDocuments`' section bodies already
 * use, for the same reason: it renders as plain `<p>` tags with no `v-html`
 * and no injection surface, and needs no Markdown library.
 */
final readonly class HelpArticle
{
    /**
     * @param  list<string>  $content  Paragraphs, rendered in order.
     * @param  list<string>  $keywords  Free-text tags `HelpCenter::search()` matches against, in addition to title/summary/content.
     * @param  list<string>  $related  Other article ids, shown as "ver também".
     * @param  list<string>  $contexts  Route names this article is relevant to — read by `HelpCenter::forContext()` for the in-page "Precisa de ajuda?" block.
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $summary,
        public string $category,
        public array $content,
        public array $keywords = [],
        public array $related = [],
        public array $contexts = [],
        public int $order = 0,
        /**
         * A one-line note for articles whose subject varies by plan (e.g. "a
         * importação de pauta está disponível conforme o seu plano"). Purely
         * DISPLAY — never a reason to hide the article itself, since help
         * content is not a paid feature (§7). Null when the article's subject
         * is the same on every plan.
         */
        public ?string $planNote = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The array a `resources/help/articles/*.php` file returns.
     */
    public static function fromArray(string $id, array $data): self
    {
        /** @var list<string> $content */
        $content = $data['content'];

        return new self(
            id: $id,
            title: (string) $data['title'],
            summary: (string) $data['summary'],
            category: (string) $data['category'],
            content: $content,
            keywords: $data['keywords'] ?? [],
            related: $data['related'] ?? [],
            contexts: $data['contexts'] ?? [],
            order: (int) ($data['order'] ?? 0),
            planNote: $data['plan_note'] ?? null,
        );
    }

    /**
     * The shape this travels to the client in — every Inertia prop that
     * carries an article uses this, so the frontend type never has to track
     * two slightly different shapes for the same thing.
     *
     * @return array{id: string, title: string, summary: string, category: string, content: list<string>, keywords: list<string>, related: list<string>, contexts: list<string>, order: int, plan_note: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'category' => $this->category,
            'content' => $this->content,
            'keywords' => $this->keywords,
            'related' => $this->related,
            'contexts' => $this->contexts,
            'order' => $this->order,
            'plan_note' => $this->planNote,
        ];
    }
}
