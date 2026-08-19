<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Identificação e âmbito» of a school-wide report.
 *
 * It names the school, the year and how many classes are in view — and states,
 * before anything else, where the figures come from: classifications teachers
 * assigned and characterisations they validated. No teacher is named anywhere
 * in this report, and nothing in it is ordered by performance (§25).
 */
class SchoolScopeComposer extends SchoolSectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::SchoolScope;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        if (! $context->hasFacts()) {
            return ComposedSection::of(Absence::noData('a escola'), [ContentSource::Statistics]);
        }

        $overview = $context->fact('overview');

        return ComposedSection::of(
            Phrase::body([
                // The school is on the letterhead. Repeating it here reads
                // oddly on a personal account, where the organization's name
                // is the teacher's own — and a school-wide report naming a
                // teacher is precisely what it must not do (§25).
                Phrase::sentence(
                    'O presente relatório reporta-se a',
                    $context->scopeLabel(),
                    'e abrange',
                    Phrase::count((int) ($overview['classes'] ?? 0), 'turma', 'turmas'),
                ),
                'Os dados apresentados resultam das classificações atribuídas pelos docentes e das caracterizações que validaram nos respetivos relatórios de turma.',
            ]),
            [ContentSource::Classification, ContentSource::Identity],
            ['overview' => $overview],
        );
    }
}
