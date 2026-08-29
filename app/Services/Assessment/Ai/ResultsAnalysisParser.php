<?php

namespace App\Services\Assessment\Ai;

use App\Services\Ai\Structured\SectionedAnswer;

/**
 * Turns the engine's plain-text answer into a `ResultsAnalysis`.
 *
 * THE MECHANICS ARE `SectionedAnswer`'S; THE MEANING IS THIS CLASS'S. Splitting
 * on labels, tolerating accents, stripping markdown and reading bullets are the
 * same in every experience and live once. Which labels this reading must carry,
 * which of them may legitimately be empty, and what makes an answer unusable
 * are questions only Resultados can answer, and they are here.
 *
 * NULL RATHER THAN A HALF-ANALYSIS. A reply with no SINTESE, or with no
 * observation of any kind in it, is not a reading this application can put in
 * front of a teacher under a heading that promises one — it is dropped, and the
 * caller reports a failure the teacher can retry. Showing four empty blocks and
 * one paragraph would look like a working feature saying nothing.
 *
 * WHICH SECTIONS MAY BE EMPTY, AND WHY EACH:
 *
 *   SINTESE      never. It is the answer.
 *   PADROES      not on its own — see the guard below.
 *   FORTES       yes. A period where nothing is yet consolidated is a real
 *                period, and demanding a strength would be asking for one to
 *                be invented.
 *   ATENCAO      yes. «Nada a assinalar» is a real finding about results that
 *                hold together.
 *   SUGESTOES    not on its own — see the guard below.
 *   CAUTELAS     yes. A period with complete coverage has nothing to say here.
 *
 * THE GUARD IS «PADROES OR SUGESTOES», NOT BOTH. A reading that describes the
 * figures without proposing anything is useful; so is one that proposes without
 * restating what is already on the screen. A reading with neither is a
 * paragraph, and the panel's six blocks would have five empty ones.
 */
class ResultsAnalysisParser
{
    /** Per list section. A reading with more than five points in a block is a list, not a reading. */
    public const MAX_POINTS = 5;

    /** The labels the prompt asks for, unaccented — `SectionedAnswer` tolerates the rest. */
    public const LABELS = ['SINTESE', 'PADROES', 'FORTES', 'ATENCAO', 'SUGESTOES', 'CAUTELAS'];

    public static function parse(string $text): ?ResultsAnalysis
    {
        $answer = SectionedAnswer::parse($text, self::LABELS);

        $summary = $answer->paragraph('SINTESE');

        if ($summary === '') {
            return null;
        }

        $patterns = $answer->points('PADROES', self::MAX_POINTS);
        $suggestions = $answer->points('SUGESTOES', self::MAX_POINTS);

        if ($patterns === [] && $suggestions === []) {
            return null;
        }

        return new ResultsAnalysis(
            summary: $summary,
            patterns: $patterns,
            strengths: $answer->points('FORTES', self::MAX_POINTS),
            attentionPoints: $answer->points('ATENCAO', self::MAX_POINTS),
            suggestions: $suggestions,
            cautions: $answer->points('CAUTELAS', self::MAX_POINTS),
        );
    }
}
