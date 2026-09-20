<?php

namespace App\Support\Interventions;

/**
 * Where in a diploma a measure is named, and whether that naming still stands.
 *
 * This is the piece the module was missing: the catalogue knew a measure was
 * «seletiva», but not that it is article 9.º, alínea d) of Decreto-Lei
 * n.º 54/2018. Without the citation, a report can say what level a measure is
 * but never which text says so — and when a diploma is amended there is no way
 * to tell which citations moved.
 *
 * A reference belongs to a FRAMEWORK VERSION, never to the measure itself. The
 * same measure sits at a different alínea under a different regime, and old
 * records must keep citing the article that was in force when they were made.
 */
final readonly class LegalReference
{
    /**
     * @param  string  $article  e.g. '9.º'
     * @param  string|null  $number  the «n.º» within the article, when the article has numbered paragraphs
     * @param  string|null  $subparagraph  the alínea, e.g. 'd)'
     * @param  string  $designation  the wording the diploma itself uses, which may differ from the label shown in the UI
     * @param  LegalReferenceStatus  $status  whether this naming is still in force in this version
     * @param  string|null  $supersededBy  the stable measure code that replaced this one, when it was replaced rather than simply revoked
     */
    public function __construct(
        public string $article,
        public ?string $number,
        public ?string $subparagraph,
        public string $designation,
        public LegalReferenceStatus $status = LegalReferenceStatus::Active,
        public ?string $supersededBy = null,
    ) {}

    /**
     * The citation as it is written in Portuguese legal prose, e.g.
     * «artigo 9.º, n.º 1, alínea d)». Built rather than stored so the parts
     * stay machine-readable — a report may want the alínea on its own.
     */
    public function citation(): string
    {
        $parts = [__('artigo').' '.$this->article];

        if ($this->number !== null) {
            $parts[] = __('n.º').' '.$this->number;
        }

        if ($this->subparagraph !== null) {
            $parts[] = __('alínea').' '.$this->subparagraph;
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{article: string, number: ?string, subparagraph: ?string, designation: string, citation: string, status: string, superseded_by: ?string}
     */
    public function toPayload(): array
    {
        return [
            'article' => $this->article,
            'number' => $this->number,
            'subparagraph' => $this->subparagraph,
            'designation' => $this->designation,
            'citation' => $this->citation(),
            'status' => $this->status->value,
            'superseded_by' => $this->supersededBy,
        ];
    }
}
