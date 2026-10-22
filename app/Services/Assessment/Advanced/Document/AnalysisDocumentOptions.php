<?php

namespace App\Services\Assessment\Advanced\Document;

/**
 * What the Pro analysis document is asked to hold: which sections, and whether it names students.
 *
 * PRESENTATION, NEVER A QUERY. The options arrive in the query string and are read against a whitelist;
 * they choose what is DRAWN, and can never widen what a teacher may see. A section name that is not one
 * of the three is ignored, and asking for none gives all (the screen never sends none).
 *
 * NAMES ARE OPT-IN. The document is one person's copy of a list about identifiable minors; the counts,
 * the domains and the trends are always there, the nominal lists only when asked.
 */
final class AnalysisDocumentOptions
{
    public const COMPARISON = 'comparacao';

    public const STRENGTHS = 'pontos-fortes';

    public const TRENDS = 'tendencias';

    /** In the order they are laid out. */
    public const SECTIONS = [self::COMPARISON, self::STRENGTHS, self::TRENDS];

    /**
     * @param  list<string>  $sections
     */
    public function __construct(
        public readonly array $sections = self::SECTIONS,
        public readonly bool $students = false,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $asked = is_string($query['secoes'] ?? null) ? explode(',', $query['secoes']) : [];
        $sections = array_values(array_filter(self::SECTIONS, fn (string $section): bool => in_array($section, $asked, true)));

        return new self(
            $sections === [] ? self::SECTIONS : $sections,
            in_array((string) ($query['alunos'] ?? '0'), ['1', 'true'], true),
        );
    }

    public function has(string $section): bool
    {
        return in_array($section, $this->sections, true);
    }

    /**
     * @return array{secoes: string, alunos: string}
     */
    public function toQuery(): array
    {
        return ['secoes' => implode(',', $this->sections), 'alunos' => $this->students ? '1' : '0'];
    }
}
