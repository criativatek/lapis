<?php

namespace App\Services\Reporting;

use App\Domain\Reporting\ContentSource;

/**
 * What a composer produces: the text of one section, whatever structured
 * figures belong beside it, and where both came from.
 *
 * `body` MAY BE NULL, AND THAT IS AN ANSWER. A section with nothing to say
 * produces nothing and is dropped from the document rather than printed as a
 * heading over a blank — or, worse, filled with a reassuring sentence about
 * data that does not exist (§41).
 *
 * `sources` IS NOT DECORATION (§40). It is how a finished report can be asked
 * where any claim in it came from, and it is what a reviewer reads to check
 * that a paragraph sourced only from `TeacherInput` is not being presented as
 * a finding.
 */
readonly class ComposedSection
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<ContentSource>  $sources
     */
    public function __construct(
        public ?string $body,
        public array $data = [],
        public array $sources = [],
    ) {}

    /**
     * A section that has nothing to say. Explicit, so a composer returning it
     * reads as a decision rather than as a forgotten branch.
     */
    public static function empty(): self
    {
        return new self(null);
    }

    /**
     * @param  list<ContentSource>  $sources
     * @param  array<string, mixed>  $data
     */
    public static function of(string $body, array $sources, array $data = []): self
    {
        $body = trim($body);

        return new self(
            $body === '' ? null : $body,
            $data,
            // Every generated section is, by definition, also generated text.
            array_values(array_unique([...$sources, ContentSource::GeneratedText], SORT_REGULAR)),
        );
    }

    public function hasContent(): bool
    {
        return $this->body !== null || $this->data !== [];
    }

    /**
     * @return list<string>
     */
    public function sourceValues(): array
    {
        return ContentSource::values($this->sources);
    }
}
