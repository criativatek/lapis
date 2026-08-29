<?php

namespace App\Services\Progress\Ai;

use App\Services\Ai\Structured\SectionedAnswer;

/**
 * Turns the engine's plain-text answer into a `FollowupSynthesis`.
 *
 * THE MECHANICS ARE `SectionedAnswer`'S; THE MEANING IS THIS CLASS'S — the same
 * split `ResultsAnalysisParser` draws, and for the same reason. Which labels
 * this reading must carry, which may be empty, and what makes an answer
 * unusable are questions only Acompanhamento can answer.
 *
 * THE GUARD IS DIFFERENT FROM THE ASSESSMENT ONE, AND THE DIFFERENCE IS THE
 * POINT. There, an answer survives with either observations or suggestions.
 * Here, `POSITIVOS` is REQUIRED alongside the summary: a synthesis of a child
 * that lists only what is wrong is exactly the failure the Evolução panel was
 * designed to prevent, and a parser that accepted one would let the model
 * quietly undo the design. A model that genuinely finds nothing positive is
 * asked, by the prompt, to say so in a sentence — and that sentence satisfies
 * this guard, because «ainda não há sinais consolidados» is an honest finding
 * and «here are four problems» with silence beside it is not.
 *
 * NULL RATHER THAN A HALF-SYNTHESIS. A reply this application half-understood
 * is not a reading it can put in front of a teacher under headings that promise
 * one — it is dropped, and the caller reports a failure the teacher can retry.
 */
class FollowupSynthesisParser
{
    /** Per list section. A synthesis with more than four points in a block is a list, not a synthesis. */
    public const MAX_POINTS = 4;

    /** The labels the prompt asks for, unaccented — `SectionedAnswer` tolerates the rest. */
    public const LABELS = ['SINTESE', 'POSITIVOS', 'ATENCAO', 'MUDOU', 'PROXIMO', 'CAUTELAS'];

    public static function parse(string $text): ?FollowupSynthesis
    {
        $answer = SectionedAnswer::parse($text, self::LABELS);

        $summary = $answer->paragraph('SINTESE');

        if ($summary === '') {
            return null;
        }

        $positive = $answer->points('POSITIVOS', self::MAX_POINTS);

        // See the class docblock: this is the one section whose absence is a
        // refusal rather than an empty block.
        if ($positive === []) {
            return null;
        }

        return new FollowupSynthesis(
            summary: $summary,
            positiveSignals: $positive,
            attentionSignals: $answer->points('ATENCAO', self::MAX_POINTS),
            whatChanged: $answer->points('MUDOU', self::MAX_POINTS),
            nextSteps: $answer->points('PROXIMO', self::MAX_POINTS),
            cautions: $answer->points('CAUTELAS', self::MAX_POINTS),
        );
    }
}
