<?php

namespace App\Services\Ai\Structured;

/**
 * Reading a labelled, plain-text answer back into sections.
 *
 * WHY NOT JSON, WHEN «STRUCTURED OUTPUT» IS WHAT THE BRIEF ASKS FOR. Because
 * the structure a teacher needs is not the structure a wire format gives. What
 * §16 actually asks for is that an analytical answer arrives as named parts
 * with a parser and a guard in front of them, that a malformed answer produces
 * a controlled error rather than a broken panel, and that raw model output is
 * never put in front of a person. All four hold here. What JSON would add is a
 * second failure mode — a model that emits a trailing comma loses an answer
 * that was otherwise perfectly good — and a temptation to hand the decoded
 * array to a screen, which is exactly what «nunca apresentar JSON cru» forbids.
 * `RÓTULO:` sections are what `ClassAnalysisParser` and
 * `InterventionSuggestionParser` already read, they survive a model's small
 * infidelities, and they are legible in a prompt.
 *
 * EXTRACTED FROM `ClassAnalysisParser` RATHER THAN COPIED FROM IT. That parser
 * had four sections, an accent-tolerant label matcher, a bullet splitter and a
 * markdown stripper, all of which the assessment and followup readings need
 * verbatim. A second and third copy would have drifted — three parsers, three
 * ideas of what «— » at the start of a line means — so the mechanics live here
 * and each feature keeps its own SEMANTICS: which labels it expects, which of
 * them may be empty, and what makes an answer unusable. That split is the same
 * one the gateway draws with the features: infrastructure here, meaning there.
 *
 * ACCENT- AND CASE-TOLERANT ON THE LABEL. The prompt asks for `SINTESE`; a
 * model that helpfully writes `Síntese` has done nothing wrong enough to lose
 * its answer over, and refusing would turn a cosmetic difference into a failed
 * request the teacher pays for.
 *
 * MARKDOWN IS STRIPPED RATHER THAN RENDERED. Every prompt forbids it; models
 * produce it anyway. The panels render plain text into plain elements with no
 * `v-html` anywhere, so leaving the asterisks in would simply show them to the
 * teacher. Removing them here — at the boundary, once — is what stops every
 * component that displays a line from having to do it.
 */
final class SectionedAnswer
{
    /**
     * @param  array<string, string>  $sections  canonical label => its raw block
     */
    private function __construct(private readonly array $sections) {}

    /**
     * Split an answer by its labels.
     *
     * THE FULL SET OF LABELS IS NEEDED UP FRONT, not one at a time, because a
     * section ends where the NEXT one begins. A parser that looked for
     * `PADROES:` alone would swallow everything after it, including the
     * suggestions, and would look like it was working.
     *
     * @param  list<string>  $labels  the unaccented labels this answer should carry
     */
    public static function parse(string $text, array $labels): self
    {
        $trimmed = trim($text);

        if ($trimmed === '' || $labels === []) {
            return new self([]);
        }

        $alternation = implode('|', array_map(self::pattern(...), $labels));

        $sections = [];

        foreach ($labels as $label) {
            $pattern = '/^[^\S\n]*(?:'.self::pattern($label).')[^\S\n]*:(.*?)(?=^[^\S\n]*(?:'.$alternation.')[^\S\n]*:|\z)/msui';

            if (preg_match($pattern, $trimmed, $matches) !== 1) {
                continue;
            }

            $sections[$label] = trim($matches[1]);
        }

        return new self($sections);
    }

    /** One section as a single cleaned paragraph, or '' when it is absent. */
    public function paragraph(string $label): string
    {
        return self::clean($this->sections[$label] ?? '');
    }

    /**
     * One section as its bulleted points.
     *
     * @return list<string>
     */
    public function points(string $label, int $maximum): array
    {
        $block = $this->sections[$label] ?? '';

        if (trim($block) === '') {
            return [];
        }

        $points = [];

        foreach (preg_split('/\R/u', $block) ?: [] as $line) {
            // The bullet a model reached for, whichever one it was. A line
            // without any is still a point — losing an observation over a
            // missing hyphen would be pedantry.
            $point = self::clean((string) preg_replace('/^[\s]*[-–—•*]+\s*/u', '', $line));

            if ($point === '') {
                continue;
            }

            $points[] = $point;

            if (count($points) >= $maximum) {
                break;
            }
        }

        return $points;
    }

    /**
     * An accent- and case-tolerant pattern for one unaccented label.
     *
     * BUILT MECHANICALLY, not from a lookup table. A `match` listing every
     * label this application has ever used would need an entry every time a
     * prompt gained a section, and the entry it lacked would fail silently as
     * «section not found» — which reads exactly like a model that omitted it.
     */
    private static function pattern(string $label): string
    {
        static $groups = [
            'A' => 'AÁÀÂÃ',
            'E' => 'EÉÈÊ',
            'I' => 'IÍÌÎ',
            'O' => 'OÓÒÔÕ',
            'U' => 'UÚÙÛ',
            'C' => 'CÇ',
        ];

        $pattern = '';

        foreach (preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $upper = mb_strtoupper($character);

            $pattern .= isset($groups[$upper])
                ? '['.$groups[$upper].']'
                : preg_quote($character, '/');
        }

        return $pattern;
    }

    /** Plain text: no markdown emphasis, no doubled whitespace, no stray fences. */
    public static function clean(string $value): string
    {
        $value = (string) preg_replace('/^```[a-z]*\s*|\s*```$/mu', '', $value);
        $value = (string) preg_replace('/\*{1,3}|_{2,3}|`/u', '', $value);
        $value = (string) preg_replace('/[^\S\n]+/u', ' ', $value);
        $value = (string) preg_replace('/\s*\n\s*/u', "\n", $value);

        return trim($value);
    }
}
