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
 * words the teacher did not choose.
 *
 * PURPOSE BEFORE PAPERWORK (§13). What a conselho de turma wants to know is
 * what was done and for whom. «Nova / em curso / concluída» is administrative
 * state: it is real, it is useful in a table, and it is not the first thing the
 * paragraph should say.
 *
 * A RECORD WITH NO TYPE IS NOT GIVEN ONE (§11). Its title is the teacher's
 * words about a single intervention, not a category, and printing it as one is
 * what put «Legado sem dominio» into a report. Untyped records are counted and
 * described as untyped, or left out of the breakdown entirely — never renamed
 * into something that reads like a pedagogical action.
 *
 * «NÃO FORAM REGISTADAS» IS NOT «NÃO FORAM NECESSÁRIAS» (§41). The system knows
 * what was written down. It does not know what was needed.
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

        $types = is_array($interventions['types'] ?? null)
            ? array_values(array_filter($interventions['types'], 'is_array'))
            : [];
        $highlighted = is_array($interventions['highlighted'] ?? null)
            ? array_values(array_filter($interventions['highlighted'], 'is_array'))
            : [];

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->openingSentence($context, $interventions, $total),
                    $this->purposeSentence($types),
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
                'concluded' => $interventions['concluded'] ?? 0,
            ],
        );
    }

    /**
     * «No 2.º Semestre foram registadas cinco intervenções pedagógicas. Duas
     * dirigiram-se à turma e três envolveram alunos individualmente.»
     *
     * TWO SENTENCES, NOT ONE WITH A DASH IN IT. The previous shape produced «5
     * intervenções — as restantes dirigidas a 3 alunos» whenever nothing was
     * class-wide: a fragment that named «the remaining ones» without ever
     * naming the first ones (§12).
     *
     * @param  array<string, mixed>  $interventions
     */
    protected function openingSentence(ReportContext $context, array $interventions, int $total): string
    {
        $students = (int) ($interventions['students_involved'] ?? 0);
        $classWide = (int) ($interventions['class_wide'] ?? 0);
        $individual = max(0, $total - $classWide);

        // THE VERB AGREES WITH WHAT IS BEING COUNTED (§12). «Foram registadas
        // uma intervenção pedagógica» is the same failure as «nenhum aluno
        // mantiveram»: a plural verb welded to a phrase that turned out to be
        // singular. One is counted here rather than in Phrase, because the verb
        // belongs to this sentence and not to the noun.
        $registered = $total === 1 ? 'foi registada' : 'foram registadas';

        $opening = Phrase::sentence(
            Phrase::capitalise($context->whenClause()),
            $registered,
            Phrase::count($total, 'intervenção pedagógica', 'intervenções pedagógicas', feminine: true),
        );

        // Only the two shapes that are true. Where every intervention is of one
        // kind, the breakdown says nothing and is left out.
        if ($classWide > 0 && $individual > 0) {
            return Phrase::paragraph([$opening, Phrase::sentence(
                Phrase::spelled($classWide, feminine: true),
                $classWide === 1 ? 'dirigiu-se à turma e' : 'dirigiram-se à turma e',
                Phrase::spelled($individual, feminine: true),
                $individual === 1 ? 'envolveu' : 'envolveram',
                $students > 0 ? Phrase::students($students) : 'alunos individualmente',
            )]);
        }

        if ($classWide === $total) {
            // «Todas» for one thing is the same slip in the other direction,
            // and with a single intervention the breakdown says nothing the
            // opening did not: the sentence is dropped rather than bent.
            return $total === 1
                ? $opening
                : Phrase::paragraph([$opening, 'Todas se dirigiram à turma no seu conjunto.']);
        }

        return $students > 0
            ? Phrase::sentence(
                Phrase::capitalise($context->whenClause()),
                $registered,
                Phrase::count($total, 'intervenção pedagógica', 'intervenções pedagógicas', feminine: true),
                ', que',
                $total === 1 ? 'envolveu' : 'envolveram',
                Phrase::students($students),
            )
            : $opening;
    }

    /**
     * What the interventions were FOR (§13).
     *
     * The catalogue's own words for what the teacher did, never reworded into
     * something more report-sounding: the label is the record. Untyped records
     * are excluded from the list and accounted for in a sentence of their own,
     * because a category nobody chose is not a finding.
     *
     * @param  list<array<string, mixed>>  $types
     */
    protected function purposeSentence(array $types): ?string
    {
        $named = [];
        $untyped = 0;

        foreach ($types as $type) {
            $label = is_string($type['label'] ?? null) ? trim($type['label']) : '';
            $count = (int) ($type['count'] ?? 0);

            if ($label === '') {
                $untyped += $count;

                continue;
            }

            $named[] = mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);
        }

        $sentences = [];

        if ($named !== []) {
            $sentences[] = Phrase::sentence(
                count($named) === 1
                    ? 'Foi desenvolvida uma intervenção de'
                    : 'Foram desenvolvidas intervenções de',
                Phrase::items($named),
            );
        }

        if ($untyped > 0) {
            $sentences[] = Phrase::sentence(
                Phrase::count($untyped, 'intervenção', 'intervenções', feminine: true),
                $untyped === 1 ? 'não tem tipo registado' : 'não têm tipo registado',
            );
        }

        return $sentences === [] ? null : Phrase::paragraph($sentences);
    }

    /**
     * The ones the teacher explicitly flagged for the document itself, listed
     * rather than counted — and here the title IS the right thing to print,
     * because they chose to show this intervention.
     *
     * @param  list<array<string, mixed>>  $highlighted
     */
    protected function highlightedParagraph(array $highlighted): ?string
    {
        if ($highlighted === []) {
            return null;
        }

        $items = array_map(function (array $intervention): string {
            $parts = array_filter([
                (string) ($intervention['title'] ?? ''),
                // NO DOMAIN IS NO DOMAIN. The association is simply omitted;
                // an absence never becomes a category of its own (§2).
                $intervention['domain'] === null ? null : 'no domínio de '.$intervention['domain'],
            ]);

            return implode(', ', $parts);
        }, $highlighted);

        // A REAL LIST, not a paragraph with dashes typed into it (§10). What
        // this writes is still plain text — the teacher edits this body — but
        // the shape is declared rather than drawn, so each of the three
        // renderings can produce its own kind of list.
        $list = Phrase::list('Destacam-se as seguintes medidas:', $items);

        return $list === '' ? null : $list;
    }
}
