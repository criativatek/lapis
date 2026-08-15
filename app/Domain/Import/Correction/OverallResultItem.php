<?php

namespace App\Domain\Import\Correction;

use App\Domain\Assessment\Bc;

/**
 * How «the platform already worked out a result» is written down in LÁPIS.
 *
 * It is not a new representation. `docs/domain-model.md` §4.2 already decided
 * that the item is the system's ONE scoring unit, and that something assessed
 * directly against a domain — the example given is an oral observation — is an
 * instrument with a SINGLE item allocated 100% to that domain. A Plickers score
 * of 85% is the same shape: one judgement about one student, already made
 * somewhere else.
 *
 * So this class holds no architecture, only the constants and the one
 * conversion that would otherwise be repeated in three places:
 *
 *  - an instrument with one item worth 100;
 *  - one allocation, 100%, to the domain the teacher chose;
 *  - `points_earned` = the platform's percentage.
 *
 * The engine then reads it exactly as it reads everything else. Percent-of-max
 * is already its canonical unit, so 85 out of 100 arrives as 85% with no
 * conversion and no invented threshold; placing that on the subject's scale is
 * ScaleProposalResolver's job and stays there (§5).
 */
final class OverallResultItem
{
    /**
     * Short, because it becomes a column header in the correction grid. Unique
     * within its group by construction — there is only ever one of these.
     */
    public const CODE = 'RG';

    public const LABEL = 'Resultado global';

    /**
     * A percentage is a result out of a hundred, so the item is worth a hundred
     * and the teacher never has to say so.
     */
    public const POINTS_POSSIBLE = '100';

    /**
     * The mark a percentage becomes on an item of a given worth.
     *
     * Worth 100 (a new instrument), 85% is 85. Worth 20 (an existing instrument
     * the teacher chose), 85% is 17 — the destination's cotação decides, which
     * is the rule the associate path already follows everywhere else (§24).
     *
     * Four decimals because that is what the column holds; decimal strings
     * throughout because a grade never touches a float (§24.4).
     */
    public static function earned(string $percent, string $pointsPossible): string
    {
        return Bc::round(
            Bc::mul(Bc::div(Bc::of($percent), '100'), Bc::of($pointsPossible)),
            4,
            'half_up',
        );
    }
}
