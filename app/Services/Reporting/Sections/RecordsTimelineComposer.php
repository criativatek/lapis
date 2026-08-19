<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Cronologia detalhada» (§23, modo detalhado).
 *
 * THE MOST IDENTIFYING THING THIS MODULE PRODUCES, and governed accordingly:
 * off by default, present only when the teacher asked for the detailed mode,
 * and carrying names only when they separately allowed identification. Without
 * that, the source never put the names in the facts at all — so there is
 * nothing here to leak, rather than something here that is merely not printed
 * (§28).
 *
 * IT IS A TABLE, NOT A NARRATIVE. Each row is a date, a kind and what the
 * teacher wrote. Nothing is summarised, nothing is characterised, and the
 * descriptions are reproduced as typed — they are the teacher's own words about
 * a child and are not the module's to rephrase.
 */
class RecordsTimelineComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::RecordsTimeline;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $timeline = $context->fact('timeline');

        if (! is_array($timeline) || $timeline === []) {
            return ComposedSection::empty();
        }

        $lead = Phrase::sentence(
            'Listagem cronológica dos',
            Phrase::records(count($timeline)),
            $context->fact('filters.names_students') === true
                ? 'com identificação dos alunos'
                : 'sem identificação dos alunos',
        );

        // A cap that is reached is a cap that is stated: silently showing the
        // first five hundred of six hundred would read as completeness.
        $truncated = $context->fact('truncated') === true
            ? 'A listagem foi limitada aos primeiros '.count($timeline).' registos. Restrinja o intervalo ou o tipo para ver os restantes.'
            : null;

        return ComposedSection::of(
            Phrase::body([$lead, $truncated]),
            [ContentSource::Records],
            ['rows' => array_values($timeline), 'truncated' => $context->fact('truncated', false)],
        );
    }
}
