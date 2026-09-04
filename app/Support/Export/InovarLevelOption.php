<?php

namespace App\Support\Export;

use App\Domain\Export\InovarTemplateColumn;
use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodStatus;
use Carbon\CarbonInterface;

/**
 * The two questions «Incluir nível/classificação atribuída» opens, answered
 * from configuration and never from a word.
 *
 *  1. IS IT ON BY DEFAULT? A level belongs on a grid that closes a moment and
 *     not on one taken along the way. Which of the two this is comes from the
 *     period's OWN dates and status — never from its name: a school that calls
 *     its units «semestre», «período» or «módulo» is describing the same thing,
 *     and matching on that text is how «semestre» ends up hardcoded somewhere
 *     (§6, the same rule CaptureEvaluationSheet applies to the reference date).
 *
 *     Read exactly this way: the moment is FINAL when the period has reached
 *     its own `ends_on`, or when somebody has closed or archived it. Anything
 *     else is a moment inside a period still running, which is interim.
 *
 *     A DEFAULT AND NOTHING MORE. The teacher inverts it on the screen, and the
 *     server does what the teacher said.
 *
 *  2. WHICH COLUMN? Only when the grid names one. Neither real grid does — see
 *     InovarTemplateColumn — so in practice nothing is pre-selected and the
 *     teacher picks. The match is on the header the file itself carries, so the
 *     day a grid names that column it is a name being read, not a position
 *     being guessed.
 */
class InovarLevelOption
{
    /**
     * A header that names a level or a classification. Deliberately narrow:
     * the cost of a wrong pre-selection is a mark written in the wrong column.
     */
    protected const NAMES_A_LEVEL = '/n[íi]vel|classifica/iu';

    public static function includedByDefault(AcademicPeriod $period, CarbonInterface $now): bool
    {
        if (in_array($period->status, [AcademicPeriodStatus::Closed, AcademicPeriodStatus::Archived], true)) {
            return true;
        }

        return ! $now->copy()->startOfDay()->lessThan($period->ends_on->copy()->startOfDay());
    }

    /**
     * The column to pre-select, or null when nothing in the file says so.
     *
     * @param  list<InovarTemplateColumn>  $candidates
     */
    public static function suggestedColumn(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate->header !== null && preg_match(self::NAMES_A_LEVEL, $candidate->header) === 1) {
                return $candidate->column;
            }
        }

        return null;
    }
}
