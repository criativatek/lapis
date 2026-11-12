<?php

namespace App\Services\Export;

use App\Models\Scale;
use App\Models\ScaleLevel;

/**
 * What INOVAR calls a band of a scale — the code the school itself defined there.
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
     * The codes a NEW level is offered by default — F, I, S, B, MB, the five
     * of the system «Escala 1 a 5». Only suggestions: every school defines its
     * own number of levels and its own codes in INOVAR, so a code the teacher
     * typed is never rejected for not being one of these.
     *
     * @var list<string>
     */
    public const DEFAULT_SUGGESTIONS = ['F', 'I', 'S', 'B', 'MB'];

    /**
     * The longest code a band can carry — the width of `scale_levels.inovar_code`.
     */
    public const MAX_LENGTH = 8;

    /**
     * Whether a stored code can be written to the file: a non-empty text once
     * trimmed. Nothing else is checked — the code belongs to the school.
     */
    public static function isWritable(?string $code): bool
    {
        return $code !== null && trim($code) !== '';
    }

    /**
     * The code for one band, or null when that band has none.
     */
    public function forLevel(?ScaleLevel $level): ?string
    {
        $code = $level?->inovar_code;

        if ($code === null || trim($code) === '') {
            return null;
        }

        return trim($code);
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
