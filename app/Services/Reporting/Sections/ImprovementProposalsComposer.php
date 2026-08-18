<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Propostas de superação das dificuldades» (§14, §15).
 *
 * A STRATEGY BELONGS TO A DIFFICULTY, AND STATES WHAT IT IS FOR. That triple —
 * dificuldade → estratégia → objetivo — is the whole design. A flat list of
 * measures is what makes generated reports read like form letters:
 * «diferenciação pedagógica; reforço positivo; trabalho de pares» appears under
 * every difficulty ever recorded and means nothing under any of them. Here each
 * proposal names the difficulty it answers and the objective it serves, or it
 * does not appear.
 *
 * NOTHING IS PROPOSED FOR A DIFFICULTY NOBODY VALIDATED. The section reads the
 * difficulties the teacher confirmed and the strategies they chose against
 * each — from the library or typed themselves. It never suggests a measure on
 * its own, and it never carries one over from a difficulty that was removed.
 *
 * THE WORDS ARE COPIES, NOT LOOKUPS. What was chosen was copied into
 * `teacher_input` at the moment of choosing, so rewording a library entry next
 * September leaves this February's report exactly as it was written (§33, §38).
 */
class ImprovementProposalsComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::ImprovementProposals;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $difficulties = $context->input('difficulties');

        if (! is_array($difficulties)) {
            return ComposedSection::empty();
        }

        $proposals = [];
        $paragraphs = [];

        foreach ($difficulties as $difficulty) {
            if (! is_array($difficulty)) {
                continue;
            }

            $label = is_string($difficulty['label'] ?? null) ? trim($difficulty['label']) : '';
            $strategies = is_array($difficulty['strategies'] ?? null) ? $difficulty['strategies'] : [];

            if ($label === '' || $strategies === []) {
                continue;
            }

            $sentence = $this->proposalSentence($label, $strategies);

            if ($sentence === null) {
                continue;
            }

            $paragraphs[] = $sentence;
            $proposals[] = ['difficulty' => $label, 'strategies' => $strategies];
        }

        if ($paragraphs === []) {
            return ComposedSection::empty();
        }

        return ComposedSection::of(
            Phrase::body([
                'Para as dificuldades identificadas, propõem-se as seguintes medidas.',
                ...$paragraphs,
            ]),
            [ContentSource::TeacherInput, ContentSource::Library],
            ['proposals' => $proposals],
        );
    }

    /**
     * «Planificação da escrita — propõem-se guiões de planificação prévia e
     * revisão orientada, com o objetivo de melhorar a organização, a coerência
     * e a clareza textual.»
     *
     * THE DIFFICULTY LEADS, UNBENT. «Para a planificação da escrita» would need
     * the correct article for every noun a teacher might type — and a report
     * that writes «Para o planificação» has lost the reader before the measure
     * is even read. The label as written, then a dash, is right for all of them.
     *
     * @param  array<int|string, mixed>  $strategies
     */
    protected function proposalSentence(string $difficulty, array $strategies): ?string
    {
        $labels = [];
        $objectives = [];

        foreach ($strategies as $strategy) {
            if (! is_array($strategy)) {
                continue;
            }

            $label = is_string($strategy['label'] ?? null) ? trim($strategy['label']) : '';

            if ($label === '') {
                continue;
            }

            $labels[] = mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);

            $objective = is_string($strategy['objective'] ?? null) ? trim($strategy['objective']) : '';

            if ($objective !== '') {
                $objectives[] = $objective;
            }
        }

        if ($labels === []) {
            return null;
        }

        $measures = (count($labels) === 1 ? 'propõe-se ' : 'propõem-se ').Phrase::items($labels);

        // The objective is stated only when there is one. A strategy whose
        // purpose nobody wrote down is still a real measure; inventing a
        // purpose for it would not be (§15).
        if ($objectives !== []) {
            $measures .= ', com o objetivo de '.Phrase::items($objectives);
        }

        return Phrase::sentence($difficulty, '—', $measures);
    }
}
