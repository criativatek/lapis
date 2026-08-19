<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Distribuição por categoria» (§23, síntese).
 *
 * THREE CUTS OF THE SAME RECORDS, each keeping its own denominator: by kind, by
 * the internal grouping the logbook already uses, and by direction where a
 * direction exists at all.
 *
 * THE FOURTH VALENCE IS REPORTED, NOT SWALLOWED. Entries whose kind carries no
 * direction — a contacto, uma observação, um apoio — are counted as «sem
 * sentido definido» and named as such. Folding them into «neutro» would let a
 * reader conclude that most of the logbook was fine, which is not what «nobody
 * recorded a direction» means (§41).
 */
class RecordsDistributionComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::RecordsDistribution;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $facts = $context->fact('kinds');
        $kinds = is_array($facts) ? array_values(array_filter($facts, 'is_array')) : [];

        if ($kinds === []) {
            return ComposedSection::of(Absence::noRecords(), [ContentSource::Records]);
        }

        return ComposedSection::of(
            Phrase::body([
                $this->kindsSentence($kinds),
                $this->groupsSentence($context),
                $this->valenceSentence($context),
            ]),
            [ContentSource::Records],
            [
                'kinds' => $kinds,
                'groups' => $context->fact('groups', []),
                'valences' => $context->fact('valences', []),
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $kinds
     */
    protected function kindsSentence(array $kinds): string
    {
        $parts = array_map(function (array $kind): string {
            $text = (string) $kind['label'].' — '.Phrase::records((int) $kind['records']);
            $students = (int) ($kind['students_involved'] ?? 0);

            // Both units, on every row.
            return $students > 0 ? $text.' ('.Phrase::students($students).')' : $text;
        }, $kinds);

        // Phrase::items already drops anything blank.
        return Phrase::sentence('Por tipo de registo:', Phrase::items($parts));
    }

    protected function groupsSentence(ReportContext $context): ?string
    {
        $groups = $context->fact('groups');

        if (! is_array($groups) || count($groups) < 2) {
            return null;
        }

        $parts = array_map(
            fn ($group) => is_array($group) ? (string) $group['label'].' — '.Phrase::records((int) $group['records']) : '',
            $groups,
        );

        return Phrase::sentence('Por categoria:', Phrase::items(array_values(array_filter($parts))));
    }

    protected function valenceSentence(ReportContext $context): ?string
    {
        $valences = $context->fact('valences');

        if (! is_array($valences) || $valences === []) {
            return null;
        }

        $parts = array_map(
            fn ($valence) => is_array($valence) ? (string) $valence['label'].' — '.Phrase::records((int) $valence['records']) : '',
            $valences,
        );

        return Phrase::paragraph([
            Phrase::sentence('Quanto ao sentido do registo:', Phrase::items(array_values(array_filter($parts)))),
            // The caveat is part of the finding, not a footnote to it.
            'O sentido só é atribuído aos registos cujo tipo o comporta; os restantes são contabilizados sem sentido definido.',
        ]);
    }
}
