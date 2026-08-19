<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Turmas, alunos e disciplinas» — the shape of the school, in counts.
 *
 * Nothing here is a result. It is the denominator every later section is read
 * against, which is why it exists as its own paragraph rather than as a clause
 * somewhere else.
 */
class SchoolOverviewComposer extends SchoolSectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::SchoolOverview;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $overview = $context->fact('overview');

        if (! is_array($overview)) {
            return ComposedSection::empty();
        }

        $levels = is_array($overview['grade_levels'] ?? null) ? array_values($overview['grade_levels']) : [];

        return ComposedSection::of(
            Phrase::body([
                Phrase::sentence(
                    'No ano letivo analisado existem',
                    Phrase::items([
                        Phrase::count((int) ($overview['classes'] ?? 0), 'turma', 'turmas'),
                        Phrase::students((int) ($overview['students'] ?? 0)),
                        Phrase::count((int) ($overview['subjects'] ?? 0), 'disciplina', 'disciplinas'),
                    ]),
                ),
                $levels === []
                    ? null
                    : Phrase::sentence('Anos de escolaridade abrangidos:', Phrase::items(array_map('strval', $levels))),
            ]),
            [ContentSource::Statistics],
            ['overview' => $overview],
        );
    }
}
