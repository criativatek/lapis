<?php

namespace App\Services\Help\Ai;

use App\Support\Help\HelpArticle;

/**
 * One article an answer rests on, in the shape the screen links to it by.
 *
 * BUILT FROM A REAL `HelpArticle`, never from a string the engine produced —
 * the constructor takes the article itself, so a reference cannot exist for
 * an article that does not. The url is resolved through the same named route
 * the Centro de Ajuda's own listings use, so a reference and a search result
 * can never point at different places for the same id.
 */
readonly class HelpAnswerReference
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}

    public static function fromArticle(HelpArticle $article): self
    {
        return new self(id: $article->id, title: $article->title);
    }

    /**
     * @return array{id: string, title: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => route('help.show', ['article' => $this->id]),
        ];
    }
}
