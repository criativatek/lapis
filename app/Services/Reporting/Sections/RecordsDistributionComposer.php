<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\RecordValence;
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
     * The kind that accounts for most of the logbook.
     *
     * THE TABLE CARRIES THE BREAKDOWN (§16). Reciting ten kinds with two counts
     * each, above a table holding exactly that, is a table read aloud.
     *
     * @param  list<array<string, mixed>>  $kinds
     */
    protected function kindsSentence(array $kinds): string
    {
        $total = 0;

        foreach ($kinds as $kind) {
            $total += (int) ($kind['records'] ?? 0);
        }

        // Already ordered by count by the source.
        $leading = $kinds[0];
        $count = (int) ($leading['records'] ?? 0);
        $students = (int) ($leading['students_involved'] ?? 0);

        if (count($kinds) === 1) {
            return Phrase::sentence(
                'Todos os registos dizem respeito a',
                mb_strtolower((string) $leading['label']),
                $students > 0 ? '('.Phrase::students($students).')' : null,
            );
        }

        $detail = Phrase::records($count).($students > 0 ? ', '.Phrase::students($students) : '');

        return Phrase::sentence(
            $count * 2 > $total ? 'A maioria diz respeito a' : 'O tipo mais frequente é',
            mb_strtolower((string) $leading['label']),
            '('.$detail.')',
        );
    }

    protected function groupsSentence(ReportContext $context): ?string
    {
        $groups = $context->fact('groups');

        if (! is_array($groups) || count($groups) < 2) {
            return null;
        }

        $parts = array_map(
            fn ($group) => is_array($group) ? (string) $group['label'].' ('.Phrase::records((int) $group['records']).')' : '',
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

        $parts = [];

        foreach ($valences as $valence) {
            if (! is_array($valence)) {
                continue;
            }

            $count = (int) ($valence['records'] ?? 0);
            $case = is_string($valence['valence'] ?? null) ? RecordValence::tryFrom($valence['valence']) : null;

            if ($count === 0 || $case === null) {
                continue;
            }

            $parts[] = Phrase::spelled($count).' '.$case->clause($count);
        }

        if ($parts === []) {
            return null;
        }

        return Phrase::paragraph([
            Phrase::sentence('Destes registos,', Phrase::items($parts)),
            // The caveat is part of the finding, not a footnote to it: a
            // direction is only attributed where the kind carries one.
            'O sentido só é atribuído aos registos cujo tipo o comporta.',
        ]);
    }
}
