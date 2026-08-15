<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CorrectionGridSource;

/**
 * Which sources LÁPIS can actually read, right now.
 *
 * The enum lists the formats that exist in the world; this lists the ones that
 * have a parser. Keeping the two apart is what lets the interface offer exactly
 * what works instead of a menu with dead entries, and what makes adding Intuitivo
 * later a matter of registering a parser rather than of finding every place that
 * said `if ($source === 'plickers')`.
 *
 * Nothing outside this class should branch on a source. If something needs to
 * know what a source can do, it asks the parser.
 */
class CorrectionGridParserRegistry
{
    /** @var array<string, CorrectionGridParser> */
    protected array $parsers = [];

    /**
     * @param  iterable<CorrectionGridParser>  $parsers
     */
    public function __construct(iterable $parsers = [])
    {
        foreach ($parsers as $parser) {
            $this->register($parser);
        }
    }

    public function register(CorrectionGridParser $parser): self
    {
        $this->parsers[$parser->source()->value] = $parser;

        return $this;
    }

    public function has(CorrectionGridSource $source): bool
    {
        return isset($this->parsers[$source->value]);
    }

    public function for(CorrectionGridSource $source): ?CorrectionGridParser
    {
        return $this->parsers[$source->value] ?? null;
    }

    /**
     * @return list<CorrectionGridSource>
     */
    public function supportedSources(): array
    {
        return array_values(array_filter(
            CorrectionGridSource::cases(),
            fn (CorrectionGridSource $source): bool => $this->has($source),
        ));
    }

    /**
     * What the interface needs to offer a choice of source: the key, the name,
     * and what the file has to look like. Built from the registered parsers, so
     * a source with no parser simply is not offered.
     *
     * @return list<array{key: string, label: string, hint: string, extensions: list<string>, accept: string}>
     */
    public function descriptors(): array
    {
        return array_map(function (CorrectionGridSource $source): array {
            /** @var CorrectionGridParser $parser */
            $parser = $this->for($source);
            $extensions = $parser->extensions();

            return [
                'key' => $source->value,
                'label' => $source->label(),
                'hint' => $source->hint(),
                'extensions' => $extensions,
                // For the file input's accept attribute: convenience for the
                // teacher, never the validation — that runs on the server.
                'accept' => implode(',', array_map(fn (string $extension): string => '.'.$extension, $extensions)),
            ];
        }, $this->supportedSources());
    }

    /**
     * Every extension any registered parser accepts — the upload rule's
     * allowlist, so it widens exactly when a parser is added and never before.
     *
     * @return list<string>
     */
    public function acceptedExtensions(): array
    {
        $extensions = [];

        foreach ($this->parsers as $parser) {
            foreach ($parser->extensions() as $extension) {
                $extensions[strtolower($extension)] = true;
            }
        }

        return array_keys($extensions);
    }

    /**
     * @return list<string>
     */
    public function acceptedMimeTypes(): array
    {
        $mimeTypes = [];

        foreach ($this->parsers as $parser) {
            foreach ($parser->mimeTypes() as $mimeType) {
                $mimeTypes[strtolower($mimeType)] = true;
            }
        }

        return array_keys($mimeTypes);
    }
}
