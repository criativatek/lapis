<?php

namespace App\Services\Assessment\Ai;

/**
 * Turns the engine's plain-text answer into a `ClassAnalysis`.
 *
 * NULL RATHER THAN A HALF-ANALYSIS. A reply with no SINTESE, or with no
 * observation of any kind in it, is not a reading this application can put in
 * front of a teacher under a heading that promises one — it is dropped and
 * the caller reports a failure the teacher can retry. That is the same rule
 * `InterventionSuggestionParser` applies to a block missing its objective,
 * and for the same reason: a suggestion this application half-understood is
 * not a suggestion it can offer.
 *
 * ATENCAO IS ALLOWED TO BE EMPTY, and is the one section that is. «Nada a
 * assinalar» is a real finding about a class whose figures hold together, and
 * a parser that insisted on a warning would be asking the model to invent one.
 *
 * MARKDOWN IS STRIPPED RATHER THAN RENDERED. The prompt forbids it; models
 * produce it anyway, and the panel renders plain text into plain elements
 * with no `v-html` anywhere. Leaving the asterisks in would show them to the
 * teacher, so the small amount a model reaches for is removed here — at the
 * boundary, once, instead of in every component that displays a line.
 */
class ClassAnalysisParser
{
    /** Per list section. A reading with more than four points in a block is a list, not a reading. */
    public const MAX_POINTS = 4;

    public static function parse(string $text): ?ClassAnalysis
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return null;
        }

        $summary = self::clean((string) self::section($trimmed, 'SINTESE'));
        $patterns = self::points(self::section($trimmed, 'PADROES'));
        $cautions = self::points(self::section($trimmed, 'ATENCAO'));
        $suggestions = self::points(self::section($trimmed, 'SUGESTOES'));

        if ($summary === '') {
            return null;
        }

        // A summary with nothing under it at all is a paragraph, not an
        // analysis — the screen's four blocks would have three empty ones.
        if ($patterns === [] && $suggestions === []) {
            return null;
        }

        return new ClassAnalysis(
            summary: $summary,
            patterns: $patterns,
            cautions: $cautions,
            suggestions: $suggestions,
        );
    }

    /**
     * The text under a `RÓTULO:` label, up to the next label or the end.
     *
     * Accent-insensitive on the label itself: the prompt asks for `SINTESE`
     * and `ATENCAO` unaccented, and a model that helpfully writes `SÍNTESE`
     * or `ATENÇÃO` has done nothing wrong enough to lose its answer over.
     */
    protected static function section(string $text, string $label): ?string
    {
        $labels = 'S[IÍ]NTESE|PADR[OÕ]ES|ATEN[CÇ][AÃ]O|SUGEST[OÕ]ES';

        $pattern = '/^[^\S\n]*(?:'.self::accentPattern($label).')[^\S\n]*:(.*?)(?=^[^\S\n]*(?:'.$labels.')[^\S\n]*:|\z)/msui';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    protected static function accentPattern(string $label): string
    {
        return match ($label) {
            'SINTESE' => 'S[IÍ]NTESE',
            'PADROES' => 'PADR[OÕ]ES',
            'ATENCAO' => 'ATEN[CÇ][AÃ]O',
            'SUGESTOES' => 'SUGEST[OÕ]ES',
            default => preg_quote($label, '/'),
        };
    }

    /**
     * The hyphen-led lines of a section, as sentences.
     *
     * @return list<string>
     */
    protected static function points(?string $block): array
    {
        if ($block === null || trim($block) === '') {
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

            if (count($points) >= self::MAX_POINTS) {
                break;
            }
        }

        return $points;
    }

    /** Plain text: no markdown emphasis, no doubled whitespace, no stray fences. */
    protected static function clean(string $value): string
    {
        $value = (string) preg_replace('/^```[a-z]*\s*|\s*```$/mu', '', $value);
        $value = (string) preg_replace('/\*{1,3}|_{2,3}|`/u', '', $value);
        $value = (string) preg_replace('/[^\S\n]+/u', ' ', $value);
        $value = (string) preg_replace('/\s*\n\s*/u', "\n", $value);

        return trim($value);
    }
}
