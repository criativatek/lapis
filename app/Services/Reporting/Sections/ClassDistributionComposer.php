<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Grade;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Distribuição das classificações atribuídas».
 *
 * THE GRADES THE TEACHER WROTE, counted — and named as they were written. On a
 * 1–5 scale a 4 is a 4; printing «Bom» in the classification column answers a
 * question nobody asked, because the mention is what the scale calls that
 * number and not what the teacher assigned (§6). Grade decides which of the two
 * leads, from what was recorded rather than from how the scale is configured.
 *
 * ON A NUMERIC SCALE THE VALUE IS THE ANSWER. A 0–20 has twenty-one possible
 * grades; grouping them into «Insuficiente / Suficiente / Bom» would invent
 * bands a school may not use. The read model already makes that distinction
 * (`mode`), and this follows it rather than deciding again.
 *
 * THE PROSE DOES NOT RECITE THE TABLE (§8, §16). Levels nobody was given are in
 * the table, where a zero is informative, and out of the sentence, where it is
 * noise. The paragraph says who was classified and how the classifications
 * fell; the table documents the rest.
 */
class ClassDistributionComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::ClassDistribution;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $distribution = $context->fact('assigned_distribution');

        if (! is_array($distribution)) {
            // A photograph taken before the module recorded assigned grades has
            // no such block and never grows one (§30).
            return ComposedSection::of(
                $context->readsSnapshot()
                    ? 'A distribuição das classificações atribuídas não foi registada nesta avaliação intercalar.'
                    : Absence::noClassifications(),
                [ContentSource::Classification],
            );
        }

        $classified = (int) ($distribution['classified'] ?? 0);

        if ($classified === 0) {
            return ComposedSection::of(Absence::noClassifications(), [ContentSource::Classification]);
        }

        $rows = $this->rows($distribution);

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->openingSentence($context, $classified),
                    $this->distributionSentence($rows),
                ]),
                // No «pending» sentence here: the opening already states the
                // denominator, and «Síntese da avaliação global» has already
                // explained why those students are outside the rate.
                $this->outsideScaleSentence($rows),
            ]),
            [ContentSource::Classification, ContentSource::Statistics],
            [
                'mode' => $distribution['mode'] ?? null,
                'classified' => $classified,
                'rows' => $rows,
                'without_classification' => $distribution['without_classification'] ?? 0,
                'unplaced' => $distribution['unplaced'] ?? 0,
            ],
        );
    }

    /**
     * The bands, whatever the scale calls them.
     *
     * A LEVEL NOBODY IS IN STILL GETS ITS ROW on a levelled scale: «2: 0» is an
     * answer and a missing row is not. A numeric scale lists only the values
     * actually assigned, because twenty-one mostly-empty rows are not a
     * distribution.
     *
     * @param  array<string, mixed>  $distribution
     * @return list<array<string, mixed>>
     */
    protected function rows(array $distribution): array
    {
        $rows = $distribution['bands'] ?? $distribution['values'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Who was classified, with the denominator inside the same sentence.
     *
     * «Foi atribuída classificação a cinco alunos» followed by «Um aluno não
     * tem ainda classificação atribuída» says one thing in two sentences, and
     * the second one repeats verbatim what the overall assessment already said
     * two paragraphs above (§20).
     */
    protected function openingSentence(ReportContext $context, int $classified): string
    {
        $total = (int) $context->fact('summary.students_total', 0);

        if ($total === 0 || $classified === $total) {
            return Phrase::sentence(
                'Foi atribuída classificação',
                $total === 1 ? 'ao único aluno da turma' : 'aos '.Phrase::spelled($total ?: $classified).' alunos da turma',
            );
        }

        return Phrase::sentence(
            'Foi atribuída classificação a',
            Phrase::ratio($classified, $total),
            'alunos da turma',
        );
    }

    /**
     * «Um aluno obteve nível 2, um nível 3 e quatro nível 4.»
     *
     * Only the levels somebody was given. Reciting «nenhum aluno obteve nível
     * 1» before that is how a paragraph turns into a table read aloud (§8).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function distributionSentence(array $rows): ?string
    {
        $parts = [];
        $first = true;

        foreach ($rows as $row) {
            $count = (int) ($row['count'] ?? 0);

            if ($count === 0) {
                continue;
            }

            $grade = Grade::inProse($row);

            // The verb appears once and is elided afterwards, as a person
            // writing this sentence would elide it.
            $parts[] = $first
                ? Phrase::studentsDid($count, 'obteve', 'obtiveram').' '.$grade
                : Phrase::spelled($count).' '.$grade;

            $first = false;
        }

        return $parts === [] ? null : Phrase::sentence(Phrase::items($parts));
    }

    /**
     * A grade given on a band the scale no longer has.
     *
     * Their history is true even if the scale has moved on, so the row exists
     * and the sentence explains it rather than letting a reader think the
     * report is broken.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function outsideScaleSentence(array $rows): ?string
    {
        $outside = array_filter($rows, fn (array $row) => ($row['outside_scale'] ?? false) === true);

        if ($outside === []) {
            return null;
        }

        return 'Algumas classificações foram atribuídas em níveis que já não constam da escala em uso; mantêm-se como foram registadas.';
    }
}
