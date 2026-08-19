<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Cobertura dos dados» — and it comes BEFORE the results, deliberately (§27).
 *
 * A school-wide distribution built on a third of the classes is not the
 * school's results. Placing the coverage after the figures would make it a
 * caveat somebody skips; placing it first makes it the frame everything else
 * is read inside. Where classes are missing, the report says plainly that they
 * are in none of the numbers.
 */
class SchoolCoverageComposer extends SchoolSectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::SchoolCoverage;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $coverage = $context->fact('coverage');

        if (! is_array($coverage)) {
            return ComposedSection::empty();
        }

        $total = (int) ($coverage['classes_total'] ?? 0);
        $with = (int) ($coverage['classes_with_classifications'] ?? 0);

        if ($total === 0) {
            return ComposedSection::of(Absence::noData('as turmas'), [ContentSource::Classification]);
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    // Two shapes, because «Das 1 turma existentes» is what one
                    // shape gives you when a school has a single class.
                    $total === 1
                        ? Phrase::sentence(
                            'Existe 1 turma, que',
                            $with === 1 ? 'tem' : 'não tem',
                            'classificações atribuídas no âmbito analisado',
                        )
                        : Phrase::sentence(
                            'Das',
                            $total.' turmas existentes,',
                            $with === 1 ? '1 tem' : $with.' têm',
                            'classificações atribuídas no âmbito analisado',
                        ),
                    Phrase::sentence(
                        'Foram consideradas',
                        Phrase::count((int) ($coverage['classifications'] ?? 0), 'classificação', 'classificações'),
                        ', relativas a',
                        Phrase::students((int) ($coverage['students_classified'] ?? 0)),
                    ),
                ]),
                $with < $total
                    ? 'As turmas sem classificações atribuídas não entram em nenhum dos valores apresentados neste relatório.'
                    : null,
            ]),
            [ContentSource::Classification],
            ['coverage' => $coverage],
        );
    }
}
