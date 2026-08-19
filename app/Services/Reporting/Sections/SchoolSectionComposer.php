<?php

namespace App\Services\Reporting\Sections;

use App\Services\Reporting\ReportContext;

/**
 * What every school-wide section has in common (§24–§28).
 *
 * ONE DISCIPLINE, STATED ONCE. Every figure in an institutional report is a
 * count of decisions teachers made, never a re-derived result. Every count
 * states its denominator. Nothing is ordered by performance. Nothing crosses a
 * scale boundary. The composers that extend this are each free to be short,
 * because the rule they all follow lives here.
 *
 * AND THE ONE SENTENCE THAT MUST NOT BE LEFT OUT: how much of the school the
 * figures actually describe. A distribution over three of forty classes is not
 * a school's results, and a reader who is not told will assume it is (§27).
 */
abstract class SchoolSectionComposer implements SectionComposer
{
    protected function coverageClause(ReportContext $context): ?string
    {
        $total = (int) $context->fact('coverage.classes_total', 0);
        $with = (int) $context->fact('coverage.classes_with_classifications', 0);

        if ($total === 0 || $with === $total) {
            return null;
        }

        // Feminine noun, so written out: the masculine helper would produce
        // «os 26 turmas».
        return 'Os valores acima referem-se a '.$with.' de '.$total
            .' turmas com classificações registadas.';
    }
}
