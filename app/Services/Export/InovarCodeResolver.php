<?php

namespace App\Services\Export;

use App\Models\Scale;
use App\Models\ScaleLevel;

/**
 * What INOVAR calls a band of a scale — F, I, S, B, MB.
 *
 * READ FROM THE BAND'S OWN STATED CORRESPONDENCE, never inferred. The two
 * things a band already carries both look tempting and are both wrong for this:
 * `code` is the scale's short name for the band, «1»…«5» on the scale in use
 * here and something else on the next one, so reading it as an INOVAR code
 * would be deciding in silence that «4» means «B»; `label` is authored text
 * that a translation or a rephrasing would quietly break.
 *
 * A scale that has not been given the correspondence cannot be exported, and
 * saying so is the answer. Inventing a code would put a classification in front
 * of a school that no approved rule produced — the same reason a scale without
 * bands proposes nothing (§10.4).
 */
class InovarCodeResolver
{
    /**
     * The codes this integration writes. Anything else configured on a band is
     * a configuration error, not a value to pass through: INOVAR would refuse
     * the file and the teacher would learn about it there rather than here.
     *
     * @var list<string>
     */
    public const ACCEPTED = ['F', 'I', 'S', 'B', 'MB'];

    /**
     * The code for one band, or null when that band has none.
     */
    public function forLevel(?ScaleLevel $level): ?string
    {
        $code = $level?->inovar_code;

        if ($code === null || trim($code) === '') {
            return null;
        }

        return in_array(trim($code), self::ACCEPTED, true) ? trim($code) : null;
    }

    /**
     * Whether every band of this scale can be written to INOVAR.
     *
     * All of them, not merely the ones a given class happens to have reached:
     * a scale is exportable or it is not, and finding out halfway through a
     * class that one band cannot be written is finding out too late.
     */
    public function covers(?Scale $scale): bool
    {
        if ($scale === null || $scale->levels->isEmpty()) {
            return false;
        }

        foreach ($scale->levels as $level) {
            if ($this->forLevel($level) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The bands this scale cannot write, named as the teacher sees them — for
     * the message that explains why the export is refused.
     *
     * @return list<string>
     */
    public function missingFrom(?Scale $scale): array
    {
        if ($scale === null) {
            return [];
        }

        $missing = [];

        foreach ($scale->levels as $level) {
            if ($this->forLevel($level) === null) {
                $missing[] = (string) $level->label;
            }
        }

        return $missing;
    }
}
