<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Distribuição das classificações atribuídas».
 *
 * THE GRADES THE TEACHER WROTE, counted. Not where the averages landed — that
 * is a different reading, and one the report does not print here precisely
 * because the two would then be confusable. If the teacher assigned a 2, this
 * section says 2.
 *
 * ON A NUMERIC SCALE THE VALUE IS THE ANSWER. A 0–20 has twenty-one possible
 * grades; grouping them into «Insuficiente / Suficiente / Bom» would answer with
 * the mention rather than with the grade, and would invent bands a school may
 * not use. The read model already makes that distinction (`mode`), and this
 * follows it rather than deciding again.
 *
 * THE DENOMINATOR IS THE GRADED STUDENTS. Dividing by the whole class would let
 * students nobody has classified yet shrink every band without appearing
 * anywhere — so they are counted, separately, in a sentence of their own.
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
                    Phrase::sentence(
                        'Foram atribuídas classificações a',
                        Phrase::students($classified),
                        ', com a seguinte distribuição',
                    ),
                    $this->inlineDistribution($rows),
                ]),
                Phrase::paragraph([
                    $this->pendingSentence($distribution),
                    $this->outsideScaleSentence($rows),
                ]),
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
     * A LEVEL NOBODY IS IN STILL GETS ITS ROW on a levelled scale: «Nível 2: 0»
     * is an answer and a missing row is not. A numeric scale lists only the
     * values actually assigned, because twenty-one mostly-empty rows are not a
     * distribution.
     *
     * @param  array<string, mixed>  $distribution
     * @return list<array<string, mixed>>
     */
    protected function rows(array $distribution): array
    {
        $rows = $distribution['bands'] ?? $distribution['values'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function inlineDistribution(array $rows): ?string
    {
        $parts = [];

        foreach ($rows as $row) {
            $count = (int) ($row['count'] ?? 0);

            // Zero-count bands belong in the table, not in the prose: a
            // sentence that recites every empty level is unreadable.
            if ($count === 0) {
                continue;
            }

            $label = (string) ($row['label'] ?? $row['value'] ?? $row['code'] ?? '');
            $percentage = Phrase::percentage($row['percentage'] ?? null);

            $parts[] = $label.' — '.Phrase::students($count)
                .($percentage === null ? '' : ' ('.$percentage.')');
        }

        return $parts === [] ? null : Phrase::sentence(Phrase::items($parts, 'e'));
    }

    /**
     * @param  array<string, mixed>  $distribution
     */
    protected function pendingSentence(array $distribution): ?string
    {
        $without = (int) ($distribution['without_classification'] ?? 0);

        if ($without === 0) {
            return null;
        }

        return $without === 1
            ? '1 aluno não tem ainda classificação atribuída no período analisado.'
            : $without.' alunos não têm ainda classificação atribuída no período analisado.';
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
