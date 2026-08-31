<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Models\ReportType;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Síntese final».
 *
 * A RECAP IS NOT A CONCLUSION. On the Base plan this section restates, in one
 * paragraph, the three things the reader most needs to leave with — the scope,
 * the headline figure and the success rate — and adds nothing that was not
 * already established above. It does not decide whether the period «correu
 * bem»; that is a judgement, and the plan that allows judgements is a different
 * one (§4, §6).
 *
 * THE PERSPECTIVE FOR THE PERIOD AHEAD IS THE TEACHER'S SENTENCE, not the
 * system's. Where they wrote one it is carried through verbatim and attributed
 * to `teacher_input`; where they did not, the paragraph simply ends.
 *
 * Shared by every report type: what it recaps differs, the rule does not.
 */
class FinalSynthesisComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::FinalSynthesis;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $note = $this->clean($context->input('final_note'));
        $recap = $this->recap($context);

        if ($recap === null && $note === null) {
            return ComposedSection::empty();
        }

        $sources = [ContentSource::Statistics];

        if ($note !== null) {
            $sources[] = ContentSource::TeacherInput;
        }

        return ComposedSection::of(
            Phrase::body([$recap, $note === null ? null : Phrase::terminate($note)]),
            $sources,
        );
    }

    protected function recap(ReportContext $context): ?string
    {
        return $context->report->type === ReportType::Student
            ? $this->studentRecap($context)
            : $this->classRecap($context);
    }

    /**
     * The individual recap: their figure and their grade, in that order — the
     * two things a reader has to leave with, and the two the sections above
     * were careful to keep apart.
     */
    protected function studentRecap(ReportContext $context): ?string
    {
        $student = $context->fact('student');

        if (! is_array($student)) {
            return null;
        }

        $average = Phrase::percentage($student['primary_average'] ?? null);
        $assigned = $student['assigned'] ?? null;
        $grade = is_array($assigned) ? ($assigned['label'] ?? $assigned['code'] ?? null) : null;

        if ($average === null && $grade === null) {
            return null;
        }

        return Phrase::sentence(
            'Em síntese,',
            $context->whenClause(),
            'o aluno alcançou',
            $average === null ? null : 'um resultado de '.$average,
            $average !== null && $grade !== null ? 'e' : null,
            $grade === null ? null : 'a classificação de '.$grade,
        );
    }

    protected function classRecap(ReportContext $context): ?string
    {
        $summary = $context->fact('summary');

        if (! is_array($summary)) {
            return null;
        }

        $average = Phrase::percentage($summary['primary_average'] ?? null);
        $success = is_array($summary['success'] ?? null) ? $summary['success'] : [];
        $placed = (int) ($success['placed'] ?? 0);
        $rate = Phrase::percentage($success['rate'] ?? null);

        // Nothing to recap is not a paragraph saying there is nothing to
        // recap — the sections above already said so, once (§41).
        if ($average === null && $placed === 0) {
            return null;
        }

        $headline = Phrase::sentence(
            'Em síntese,',
            $context->whenClause(),
            $average === null
                ? null
                : 'a turma alcançou um resultado médio de '.$average,
            $average !== null && $placed > 0 ? 'e' : null,
            $placed === 0 || $rate === null
                ? null
                : 'uma taxa de sucesso de '.$rate,
        );

        // THREE SENTENCES AT MOST, AND EACH ONE OMISSIBLE ON ITS OWN. Nothing
        // here is computed: every figure was established by a section above,
        // and what this adds is that they finally stand together — which is
        // what a reader who turns to the last page is looking for. A class with
        // one domain, none, or no previous period gets a shorter synthesis, not
        // a broken sentence (§9).
        return Phrase::paragraph([
            $headline,
            $this->domainsSentence($context),
            $this->progressionSentence($context),
        ]);
    }

    /**
     * The domain that came out highest and the one that came out lowest.
     *
     * ONLY WHERE THERE ARE TWO OF THEM AND THEY DIFFER. Naming a single domain
     * as both extremes is true and absurd; naming two domains that tied is a
     * distinction the numbers do not make. Domains with no figure are not
     * extremes of anything and are left out (§41).
     *
     * NO READING OF WHAT THE GAP MEANS. Which domain went best is a fact; why,
     * and what to do about it, are not in this application's gift (§16).
     */
    protected function domainsSentence(ReportContext $context): ?string
    {
        $facts = $context->fact('domains');
        $accumulated = $context->fact('primary.kind') === 'accumulated';

        $rows = [];

        foreach (is_array($facts) ? $facts : [] as $domain) {
            if (! is_array($domain)) {
                continue;
            }

            $value = $accumulated
                ? ($domain['accumulated_average'] ?? $domain['period_average'] ?? null)
                : ($domain['period_average'] ?? null);

            $label = trim((string) ($domain['label'] ?? ''));

            if ($label === '' || $value === null || ! is_numeric((string) $value)) {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => (float) $value];
        }

        if (count($rows) < 2) {
            return null;
        }

        usort($rows, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        $highest = $rows[0];
        $lowest = $rows[count($rows) - 1];

        // A tie is not an extreme.
        if ($highest['value'] === $lowest['value']) {
            return null;
        }

        return Phrase::sentence(
            $highest['label'],
            'é o domínio com melhor resultado médio',
            '('.Phrase::percentage((string) $highest['value']).')',
            ', enquanto',
            $lowest['label'],
            'apresenta o valor mais baixo',
            '('.Phrase::percentage((string) $lowest['value']).')',
        );
    }

    /**
     * How many of the students who could be compared moved forward.
     *
     * THE DENOMINATOR IS THE COMPARABLE ONES, never the class. A student with
     * no previous result did not fail to progress — they have nothing to
     * progress from, and folding them in would understate the movement (§41).
     * Where nobody is comparable there is no sentence at all: silence is the
     * honest answer, not «nenhum aluno progrediu».
     */
    protected function progressionSentence(ReportContext $context): ?string
    {
        $evolution = $context->fact('evolution');

        if (! is_array($evolution)) {
            return null;
        }

        $comparable = (int) ($evolution['comparable'] ?? 0);

        if ($comparable === 0) {
            return null;
        }

        $progressed = (int) ($evolution['progressed'] ?? 0);

        // Spelled, because a ratio inside a sentence is prose and not a figure
        // (§19). And «todos» where it is all of them: «seis dos seis» is a
        // hedge, and a class where everybody moved forward should say so.
        $who = match (true) {
            $progressed === 0 => 'nenhum dos '.Phrase::spelled($comparable).' alunos',
            $progressed === $comparable => 'os '.Phrase::spelled($comparable).' alunos',
            default => Phrase::spelled($progressed).' dos '.Phrase::spelled($comparable).' alunos',
        };

        return Phrase::sentence(
            'Face ao momento anterior,',
            $who,
            'com resultados comparáveis',
            $progressed === 1 || $progressed === 0 ? 'progrediu' : 'progrediram',
        );
    }

    protected function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
