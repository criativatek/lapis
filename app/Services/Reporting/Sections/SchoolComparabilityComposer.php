<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Notas de comparabilidade» — never optional (§26).
 *
 * WHATEVER ELSE A SCHOOL-WIDE REPORT SAYS, IT SAYS WHAT COULD NOT BE COMPARED.
 * The section is not a caveat appended to the results: it is a finding, and on
 * a school where every class shares one scale it is a short and useful one.
 *
 * Classes with no assessment profile are named too. Their classifications are
 * in none of the figures above, and a reader counting turmas would otherwise
 * find the arithmetic does not close.
 */
class SchoolComparabilityComposer extends SchoolSectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::SchoolComparability;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $comparability = $context->fact('comparability');

        if (! is_array($comparability)) {
            return ComposedSection::empty();
        }

        $scales = is_array($comparability['scales'] ?? null)
            ? array_values(array_filter($comparability['scales'], 'is_array'))
            : [];
        $withoutProfile = (int) ($comparability['classes_without_profile'] ?? 0);

        if (($comparability['comparable'] ?? false) === true) {
            return ComposedSection::of(
                'Todas as turmas consideradas utilizam a mesma escala de classificação, pelo que os valores apresentados são diretamente comparáveis entre si.',
                [ContentSource::Classification],
                ['comparability' => $comparability],
            );
        }

        $parts = array_map(
            fn (array $scale): string => (string) $scale['name'].' — '.Phrase::count((int) $scale['classes'], 'turma', 'turmas'),
            $scales,
        );

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $parts === [] ? null : Phrase::sentence(
                        'As turmas consideradas utilizam',
                        Phrase::count(count($scales), 'escala de classificação diferente', 'escalas de classificação diferentes'),
                        ':',
                        Phrase::items($parts),
                    ),
                    'Os resultados obtidos em escalas diferentes não são diretamente comparáveis e são por isso apresentados em separado, nunca agregados num valor único.',
                ]),
                $withoutProfile === 0
                    ? null
                    : Phrase::sentence(
                        $withoutProfile === 1 ? '1 turma não tem' : $withoutProfile.' turmas não têm',
                        'perfil de avaliação associado, pelo que as suas classificações não entram nos valores apresentados',
                    ),
            ]),
            [ContentSource::Classification],
            ['comparability' => $comparability],
        );
    }
}
