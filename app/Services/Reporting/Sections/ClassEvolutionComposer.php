<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\Narrative\Scope;
use App\Services\Reporting\ReportContext;

/**
 * «Evolução da turma».
 *
 * TWO MOVEMENTS, TWO SENTENCES, NEVER ONE (§7). The assessment core already
 * makes this distinction and the report must not blur it:
 *
 *  - `evolution` compares this period's own work against the previous period's
 *    own work. It answers «este período correu melhor que o anterior?».
 *  - `continuous_evolution` compares the RESULT OF THE MOMENT at each end — the
 *    figure that actually answered «como está a turma» there. It answers «e ao
 *    longo do ano?».
 *
 * A student who scored 70 in the first period and 50 in the second fell twenty
 * points as a period and five as a year. Both sentences are true; substituting
 * one for the other is not a rounding difference, it is a different claim.
 *
 * «SEM COMPARAÇÃO» IS ITS OWN ANSWER and is never folded into «manteve-se». A
 * student with no previous period did not stand still — they have nothing to
 * stand against (§41).
 */
class ClassEvolutionComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::ClassEvolution;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $evolution = $context->fact('evolution');
        $previous = $context->fact('previous_period_label');

        if (! is_array($evolution) || ! is_string($previous)) {
            return ComposedSection::of(Absence::noComparison(), [ContentSource::Statistics]);
        }

        $comparable = (int) ($evolution['comparable'] ?? 0);

        if ($comparable === 0) {
            return ComposedSection::of(Absence::noComparison(), [ContentSource::Statistics]);
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->movementSentence($evolution, $previous),
                    $this->averageChangeSentence($evolution),
                    $this->noComparisonSentence($evolution),
                ]),
                Phrase::paragraph([
                    $this->continuousSentence($context),
                ]),
                Phrase::paragraph([
                    $this->crossingSentence($evolution),
                ]),
            ]),
            [ContentSource::Statistics, ContentSource::Results],
            [
                'previous_period' => $previous,
                'evolution' => $evolution,
                'continuous_evolution' => $context->fact('continuous_evolution'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $evolution
     */
    protected function movementSentence(array $evolution, string $previous): string
    {
        $progressed = (int) ($evolution['progressed'] ?? 0);
        $stable = (int) ($evolution['stable'] ?? 0);
        $regressed = (int) ($evolution['regressed'] ?? 0);

        // studentsDid, not a hand-rolled ternary: with zero, «nenhum aluno
        // mantiveram» is what a count-based ternary produces (§18). And the
        // period comes through Scope rather than as a label with a preposition
        // glued to it — the article is a rule, stated once (§7).
        return Phrase::sentence(
            'Comparativamente',
            Scope::previousPeriodClause($previous),
            ', considerando o trabalho realizado em cada período isoladamente,',
            Phrase::items([
                Phrase::studentsDid($progressed, 'progrediu', 'progrediram'),
                Phrase::studentsDid($stable, 'manteve o seu resultado', 'mantiveram o seu resultado'),
                Phrase::studentsDid($regressed, 'regrediu', 'regrediram'),
            ]),
        );
    }

    /**
     * The mean movement, in PERCENTAGE POINTS and over the students who had two
     * periods to compare — never over the whole class, which would dilute the
     * movement with students who did not move because they could not.
     *
     * @param  array<string, mixed>  $evolution
     */
    protected function averageChangeSentence(array $evolution): ?string
    {
        $change = $evolution['average_change'] ?? null;
        $value = Phrase::number($change);

        if ($value === null) {
            return null;
        }

        $numeric = (float) str_replace(',', '.', $value);

        if (abs($numeric) < 0.05) {
            return 'Em média, os resultados mantiveram-se estáveis entre os dois períodos.';
        }

        return Phrase::sentence(
            'Em média, os resultados',
            $numeric > 0 ? 'subiram' : 'desceram',
            Phrase::number((string) abs($numeric)),
            'pontos percentuais entre os dois períodos, considerando apenas os alunos com resultado nos dois momentos',
        );
    }

    /**
     * @param  array<string, mixed>  $evolution
     */
    protected function noComparisonSentence(array $evolution): ?string
    {
        $none = (int) ($evolution['no_comparison'] ?? 0);

        if ($none === 0) {
            return null;
        }

        return Phrase::sentence(
            Phrase::studentsDid($none, 'não dispõe', 'não dispõem'),
            'de resultado nos dois momentos, pelo que não é possível apurar a sua evolução',
        );
    }

    /**
     * The continuous reading, when the facts carry one.
     *
     * Absent on a photograph, which never recorded it — and a sentence about a
     * movement nobody measured would be an invention (§30).
     */
    protected function continuousSentence(ReportContext $context): ?string
    {
        $continuous = $context->fact('continuous_evolution');

        if (! is_array($continuous)) {
            return null;
        }

        $comparable = (int) ($continuous['comparable'] ?? 0);

        if ($comparable === 0) {
            return null;
        }

        $progressed = (int) ($continuous['progressed'] ?? 0);
        $stable = (int) ($continuous['stable'] ?? 0);
        $regressed = (int) ($continuous['regressed'] ?? 0);

        // NO PARENTHETICAL DASHES (§1). The previous shape explained the method
        // inside the sentence it was making — «Na leitura contínua — comparando
        // o resultado que respondia por cada aluno em cada momento — …» — which
        // is a footnote wearing a sentence's clothes. The reading is named once
        // and the sentence gets on with what it has to say.
        return Phrase::sentence(
            'Na avaliação contínua, que considera o percurso acumulado de cada aluno até ao momento,',
            Phrase::items([
                Phrase::studentsDid($progressed, 'progrediu', 'progrediram'),
                Phrase::studentsDid($stable, 'manteve-se', 'mantiveram-se'),
                Phrase::studentsDid($regressed, 'regrediu', 'regrediram'),
            ]),
        );
    }

    /**
     * Students who changed SIDE of the scale.
     *
     * The movement that matters most to a conselho de turma, and the one a
     * percentage hides: a student who went from 48% to 52% moved four points and
     * crossed, and one who went from 70% to 60% fell ten and did not.
     *
     * @param  array<string, mixed>  $evolution
     */
    protected function crossingSentence(array $evolution): ?string
    {
        $transitions = $evolution['transitions'] ?? null;

        if (! is_array($transitions)) {
            return null;
        }

        // The counts of ASSIGNED classifications that changed side, and their
        // denominator is students the teacher graded at both ends — not the
        // class, and not students with two averages.
        $recovered = (int) ($transitions['failure_to_success'] ?? 0);
        $fell = (int) ($transitions['success_to_failure'] ?? 0);

        if ($recovered === 0 && $fell === 0) {
            return null;
        }

        $parts = [];

        if ($recovered > 0) {
            $parts[] = Phrase::studentsDid($recovered, 'recuperou', 'recuperaram')
                .' uma classificação positiva';
        }

        if ($fell > 0) {
            $parts[] = Phrase::studentsDid($fell, 'passou', 'passaram')
                .' a ter classificação negativa';
        }

        return Phrase::sentence(
            'Entre os alunos com classificação atribuída nos dois momentos,',
            Phrase::items($parts),
        );
    }
}
