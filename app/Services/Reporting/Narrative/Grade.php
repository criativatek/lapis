<?php

namespace App\Services\Reporting\Narrative;

/**
 * HOW A CLASSIFICATION IS NAMED IN A DOCUMENT (§6, §7, §8).
 *
 * On «Escala 1 a 5» the teacher assigns a 4. The scale also knows that a 4 is
 * called «Bom» — and a report whose classification column reads «Bom» has
 * silently answered a different question from the one it was asked. The teacher
 * wrote a number; the number is the classification; the word is a mention that
 * may travel beside it.
 *
 * THE TEST IS THE CODE, NOT THE KIND. A levelled scale whose levels are coded
 * 1…5 is numeric in every way that matters to a reader, even though `kind` says
 * `level`; one coded NA/A/S is genuinely qualitative and its words ARE the
 * classification. So this looks at what was actually written rather than at how
 * the scale was configured.
 *
 * Nothing here converts anything. It chooses which of two values the read model
 * already produced goes in front.
 */
class Grade
{
    /**
     * The value that leads: the code when it is a number, the words otherwise.
     *
     * @param  array<string, mixed>  $band  A row of `assigned_distribution`.
     */
    public static function lead(array $band): string
    {
        $code = self::codeOf($band);

        if ($code !== null && is_numeric($code)) {
            return $code;
        }

        $label = self::text($band['label'] ?? null);

        return $label ?? $code ?? '—';
    }

    /**
     * The mention, when there is one AND it is not already what leads.
     *
     * @param  array<string, mixed>  $band
     */
    public static function mention(array $band): ?string
    {
        $lead = self::lead($band);
        $label = self::text($band['label'] ?? null);

        return $label === null || $label === $lead ? null : $label;
    }

    /**
     * «4 · Bom», or just «Superou» on a scale whose words are the grades.
     * For a table cell, where the two have to share one line.
     *
     * @param  array<string, mixed>  $band
     */
    public static function cell(array $band): string
    {
        $lead = self::lead($band);
        $mention = self::mention($band);

        return $mention === null ? $lead : $lead.' · '.$mention;
    }

    /**
     * «nível 4» / «Superou» — the classification as it appears inside a
     * sentence.
     *
     * The word «nível» only precedes a number, because «nível Superou» is not
     * something anybody says.
     *
     * @param  array<string, mixed>  $band
     */
    public static function inProse(array $band): string
    {
        $lead = self::lead($band);

        return is_numeric($lead) ? 'nível '.$lead : $lead;
    }

    /**
     * Whether this set of bands is numbered, and can therefore be spoken about
     * as «igual ou superior a 3» rather than as «positiva» (§9).
     *
     * All of them, not most: a scale with one unnumbered band cannot be
     * described by a threshold.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    public static function areNumeric(array $bands): bool
    {
        $numeric = 0;

        foreach ($bands as $band) {
            $code = self::codeOf($band);

            if ($code === null || ! is_numeric($code)) {
                return false;
            }

            $numeric++;
        }

        return $numeric > 0;
    }

    /**
     * THE LOWEST CLASSIFICATION THE SCALE CALLS POSITIVE (§9).
     *
     * Read from `is_negative`, never assumed: a school whose 1–5 scale treats 2
     * as sufficient gets «igual ou superior a 2», and nothing here contains the
     * number 3.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    public static function threshold(array $bands): ?string
    {
        if (! self::areNumeric($bands)) {
            return null;
        }

        $lowest = null;

        foreach ($bands as $band) {
            if (($band['is_negative'] ?? null) !== false) {
                continue;
            }

            $code = self::codeOf($band);

            if ($code === null) {
                continue;
            }

            // areNumeric() above already refused anything non-numeric, so both
            // sides are numbers; the guard is what tells the analyser so.
            if (! is_numeric($code)) {
                continue;
            }

            if ($lowest === null || bccomp($code, $lowest, 4) < 0) {
                $lowest = $code;
            }
        }

        return $lowest;
    }

    /**
     * @param  array<string, mixed>  $band
     */
    protected static function codeOf(array $band): ?string
    {
        // `value` on a numeric scale's distribution, `code` on a levelled one.
        return self::text($band['value'] ?? null) ?? self::text($band['code'] ?? null);
    }

    protected static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
