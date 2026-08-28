<?php

namespace App\Services\Help\Ai;

/**
 * One answer from the Centro de Ajuda's assistant, and the articles it was
 * built from.
 *
 * «NOT ENOUGH DOCUMENTATION» IS AN ANSWER, not an error, and it is the whole
 * reason this class has a `sufficient` flag rather than an empty string. The
 * assistant is grounded exclusively in the articles the Centro de Ajuda
 * actually has; a question those articles do not cover must come back as a
 * plain statement that the documentation does not cover it, shown calmly on
 * the page, and must never come back as an invented feature. An error state
 * would be wrong — nothing failed — and a blank answer would be worse,
 * because a teacher reads silence as a bug.
 *
 * `references` ARE SERVER-CHOSEN, NEVER MODEL-CHOSEN. They are drawn from the
 * set of articles this application put in front of the engine, so a
 * hallucinated article id cannot reach the screen as a link: see
 * `HelpAnswerParser`, which intersects whatever the engine cited against what
 * it was actually given and keeps only the intersection.
 */
readonly class HelpAnswer
{
    /**
     * @param  list<HelpAnswerReference>  $references
     */
    public function __construct(
        public string $text,
        public array $references,
        public bool $sufficient,
    ) {}

    /**
     * The honest empty case: the Centro de Ajuda does not document this.
     *
     * Built without asking an engine anything when the search itself came
     * back empty — there is nothing to ground an answer in, and a request
     * that can only produce an invention is a request not worth making.
     */
    public static function insufficient(): self
    {
        return new self(
            text: 'A documentação do Centro de Ajuda não cobre esta pergunta. Experimente reformulá-la, ou procure diretamente nos artigos.',
            references: [],
            sufficient: false,
        );
    }

    /**
     * @return array{text: string, references: list<array{id: string, title: string, url: string}>, sufficient: bool}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'references' => array_map(
                fn (HelpAnswerReference $reference): array => $reference->toArray(),
                $this->references,
            ),
            'sufficient' => $this->sufficient,
        ];
    }
}
