<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Estratégias e medidas implementadas» — what was actually DONE, as recorded.
 *
 * LISTING REAL RECORDS IS DESCRIPTION; PROPOSING NEW ONES IS NOT (§19). This
 * section reads the Intervenções module and reports what is in it. It never
 * adds a measure that was not registered, never infers that a measure was
 * applied because a difficulty exists, and never describes an intervention in
 * words the teacher did not choose. Proposing belongs to «Propostas de
 * superação», which is a different section behind a different capability.
 *
 * «NÃO FORAM REGISTADAS» IS NOT «NÃO FORAM NECESSÁRIAS» (§41). The system knows
 * what was written down. It does not know what was needed, and the sentence is
 * careful about which of the two it is claiming.
 *
 * ONLY WHAT THE TEACHER RELEASED. An intervention carries
 * `available_for_reports`; one that does not is invisible here even in counts,
 * because whether a pedagogical action belongs in a document that leaves the
 * classroom is their call.
 */
class InterventionsSummaryComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::InterventionsSummary;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $interventions = $context->fact('interventions');

        if (! is_array($interventions)) {
            return ComposedSection::empty();
        }

        $total = (int) ($interventions['total'] ?? 0);

        if ($total === 0) {
            return ComposedSection::of(Absence::noInterventions(), [ContentSource::Interventions]);
        }

        $types = is_array($interventions['types'] ?? null) ? array_values($interventions['types']) : [];
        $highlighted = is_array($interventions['highlighted'] ?? null) ? array_values($interventions['highlighted']) : [];

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->openingSentence($interventions, $total),
                    $this->typesSentence($types),
                    $this->concludedSentence($interventions, $total),
                ]),
                $this->highlightedParagraph($highlighted),
            ]),
            [ContentSource::Interventions],
            [
                'total' => $total,
                'types' => $types,
                'highlighted' => $highlighted,
                'class_wide' => $interventions['class_wide'] ?? 0,
                'students_involved' => $interventions['students_involved'] ?? 0,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $interventions
     */
    protected function openingSentence(array $interventions, int $total): string
    {
        $students = (int) ($interventions['students_involved'] ?? 0);
        $classWide = (int) ($interventions['class_wide'] ?? 0);

        $parts = [];

        if ($classWide > 0) {
            $parts[] = $classWide === 1
                ? '1 dirigida à turma no seu conjunto'
                : $classWide.' dirigidas à turma no seu conjunto';
        }

        if ($students > 0) {
            $parts[] = 'as restantes dirigidas a '.Phrase::students($students);
        }

        return Phrase::sentence(
            'No período analisado foram registadas',
            Phrase::count($total, 'intervenção', 'intervenções'),
            $parts === [] ? null : '— '.Phrase::items($parts),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $types
     */
    protected function typesSentence(array $types): ?string
    {
        if ($types === []) {
            return null;
        }

        // The catalogue's own words for what the teacher did. Never reworded
        // into something more report-sounding: the label is the record.
        $parts = array_map(
            fn (array $type) => (string) $type['label'].' ('.(int) $type['count'].')',
            $types,
        );

        return Phrase::sentence('Por tipo:', Phrase::items($parts));
    }

    /**
     * @param  array<string, mixed>  $interventions
     */
    protected function concludedSentence(array $interventions, int $total): ?string
    {
        $concluded = (int) ($interventions['concluded'] ?? 0);

        if ($concluded === 0) {
            return null;
        }

        return Phrase::sentence(
            'Destas,',
            Phrase::outOfTotal($concluded, $total, 'encontra-se concluída', 'encontram-se concluídas'),
        );
    }

    /**
     * The ones the teacher explicitly flagged for the document itself, listed
     * rather than counted.
     *
     * @param  list<array<string, mixed>>  $highlighted
     */
    protected function highlightedParagraph(array $highlighted): ?string
    {
        if ($highlighted === []) {
            return null;
        }

        $lines = array_map(function (array $intervention): string {
            $parts = array_filter([
                (string) $intervention['title'],
                $intervention['domain'] === null ? null : 'domínio de '.$intervention['domain'],
                $intervention['status'] === null ? null : mb_strtolower((string) $intervention['status']),
            ]);

            return '— '.implode(', ', $parts).'.';
        }, $highlighted);

        return Phrase::paragraph([
            'Destacam-se as seguintes medidas:',
            implode("\n", $lines),
        ]);
    }
}
