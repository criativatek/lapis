<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Classificações atribuídas e taxa de sucesso», ONE PARAGRAPH PER SCALE (§26).
 *
 * «1 a 5» and «0 a 20» are not the same axis. A single school-wide rate over
 * both would be a number with no meaning that nonetheless looks authoritative
 * — and would be quoted. So each scale gets its own paragraph, named whenever
 * there is more than one, and there is no total anywhere on this page.
 *
 * WHICH SIDE A CLASSIFICATION FALLS ON IS THE SCALE'S STATEMENT, as everywhere
 * else in this application. Nothing here knows what a «3» is.
 */
class SchoolResultsComposer extends SchoolSectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::SchoolResults;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $facts = $context->fact('groups');
        $groups = is_array($facts) ? array_values(array_filter($facts, 'is_array')) : [];

        if ($groups === []) {
            return ComposedSection::of(Absence::noClassifications(), [ContentSource::Classification]);
        }

        $named = count($groups) > 1;
        $paragraphs = array_map(fn (array $group) => $this->groupParagraph($group, $named), $groups);

        return ComposedSection::of(
            Phrase::body([...$paragraphs, $this->coverageClause($context)]),
            [ContentSource::Classification],
            ['groups' => $groups],
        );
    }

    /**
     * @param  array<string, mixed>  $group
     */
    protected function groupParagraph(array $group, bool $named): string
    {
        $placed = (int) ($group['placed'] ?? 0);
        $rate = Phrase::percentage($group['success_rate'] ?? null);

        $bands = [];

        foreach ((array) ($group['bands'] ?? []) as $band) {
            if (! is_array($band) || (int) ($band['count'] ?? 0) === 0) {
                continue;
            }

            $percentage = Phrase::percentage($band['percentage'] ?? null);

            $bands[] = (string) $band['label'].' — '.$band['count']
                .($percentage === null ? '' : ' ('.$percentage.')');
        }

        return Phrase::paragraph([
            Phrase::sentence(
                // Named whenever there is more than one, because then the
                // figures belong to the scale and not to «the school».
                $named ? 'Na escala '.(string) data_get($group, 'scale.name').',' : null,
                'foram atribuídas',
                Phrase::count((int) ($group['classified'] ?? 0), 'classificação', 'classificações'),
                'em',
                Phrase::count((int) ($group['classes'] ?? 0), 'turma', 'turmas'),
            ),
            $placed === 0 || $rate === null
                ? null
                : Phrase::sentence(
                    'Destas,',
                    Phrase::howMany((int) ($group['succeeded'] ?? 0), $placed, 'foi positiva', 'foram positivas'),
                    '— uma taxa de sucesso de '.$rate,
                ),
            $bands === [] ? null : Phrase::sentence('Distribuição:', Phrase::items($bands)),
        ]);
    }
}
