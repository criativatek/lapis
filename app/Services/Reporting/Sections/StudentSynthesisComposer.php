<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Assessment\Bc;
use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Síntese global» — how this student stands, and beside what.
 *
 * THE READING IS NAMED, exactly as in the class report and for the same reason:
 * «a média do aluno» stops meaning one thing the moment a year has two periods.
 *
 * THE COMPARISON WITH THE CLASS IS A FACT, NOT A RANKING. «72,1%, acima da
 * média da turma (66,4%)» states two figures and their relation. It does not
 * say the student is good, does not place them in an order, and does not name
 * anybody else — both numbers came out of one read, so they describe the same
 * moment (§25, §71).
 *
 * IT IS ALSO PRO, AND ONLY THE COMPARISON IS. The Matriz Mestre marks
 * «Comparação contextual com turma» for Pro and Institucional in two separate
 * tables (§3 and §4), and this sentence is the report's copy of the very
 * example §4 uses to explain the boundary. The section around it is Base and
 * stays Base — §6 gives every plan «Síntese factual» — so a Base report still
 * opens with the student's own result, its band, the supplementary reading and
 * the coverage warning. What it no longer carries is the class beside it.
 *
 * A STUDENT WITH NO RESULT HAS NO RESULT. Not a zero, not a low figure, not an
 * empty percentage sign: the section says there is none and why that is not the
 * same as a bad one (§41).
 */
class StudentSynthesisComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::StudentSynthesis;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $student = $context->fact('student');

        if (! is_array($student)) {
            return ComposedSection::of(
                Absence::noData('os resultados deste aluno'),
                [ContentSource::Results],
            );
        }

        $value = Phrase::percentage($student['primary_average'] ?? null);

        if ($value === null) {
            return ComposedSection::of(
                Phrase::body([
                    'Não existem resultados apurados para este aluno no período analisado.',
                    'A ausência de resultado não corresponde a um resultado negativo: significa que não existe informação suficiente para o apurar.',
                ]),
                [ContentSource::Results],
                ['student' => ['primary_average' => null]],
            );
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->headline($context, $student, $value),
                    $this->bandSentence($student),
                    $this->comparisonSentence($context, $student),
                ]),
                Phrase::paragraph([
                    $this->supplementarySentence($context, $student),
                    $this->coverageSentence($student),
                ]),
            ]),
            [ContentSource::Results, ContentSource::Statistics],
            [
                'primary_average' => $student['primary_average'] ?? null,
                'supplementary_average' => $student['supplementary_average'] ?? null,
                'band' => $student['band'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $student
     */
    protected function headline(ReportContext $context, array $student, string $value): string
    {
        $label = $context->fact('primary.label');

        return Phrase::sentence(
            'No período analisado, a',
            is_string($label) ? $label : 'Média Ponderada',
            'foi de',
            $value,
        );
    }

    /**
     * The qualitative mention the calculated figure lands on.
     *
     * NOT THE GRADE. It is stated as what it is — where the average falls on
     * the scale — because the grade is a separate section and a separate act
     * (§7). A report that let the two blur would be reporting a decision nobody
     * made.
     *
     * @param  array<string, mixed>  $student
     */
    protected function bandSentence(array $student): ?string
    {
        $band = $student['band'] ?? null;

        if (! is_array($band) || ($band['label'] ?? null) === null) {
            return null;
        }

        return Phrase::sentence(
            'Este resultado situa-se, na escala em uso, em',
            (string) $band['label'],
        );
    }

    /**
     * @param  array<string, mixed>  $student
     */
    protected function comparisonSentence(ReportContext $context, array $student): ?string
    {
        // Asked before the figures are even read: on Base this sentence is not
        // built, so the class average never reaches the composed section, the
        // finalized document or the PDF.
        if (! $context->capabilities->allowsAnalytics()) {
            return null;
        }

        $classAverage = $context->fact('summary.primary_average');
        $mine = $student['primary_average'] ?? null;

        if (! is_string($classAverage) || ! is_string($mine)) {
            return null;
        }

        $comparison = bccomp(Bc::of($mine), Bc::of($classAverage), 4);
        $classValue = Phrase::percentage($classAverage);

        return Phrase::sentence(
            match (true) {
                $comparison > 0 => 'Situa-se acima do resultado médio da turma',
                $comparison < 0 => 'Situa-se abaixo do resultado médio da turma',
                default => 'Coincide com o resultado médio da turma',
            },
            '('.$classValue.')',
        );
    }

    /**
     * @param  array<string, mixed>  $student
     */
    protected function supplementarySentence(ReportContext $context, array $student): ?string
    {
        if ($context->fact('primary.has_supplementary') !== true) {
            return null;
        }

        $value = Phrase::percentage($student['supplementary_average'] ?? null);

        return $value === null
            ? null
            : Phrase::sentence('Considerando apenas o trabalho realizado neste período, a Média Ponderada foi de', $value);
    }

    /**
     * @param  array<string, mixed>  $student
     */
    protected function coverageSentence(array $student): ?string
    {
        if (($student['coverage_warning'] ?? false) !== true) {
            return null;
        }

        return 'Este resultado assenta em parte dos instrumentos previstos, pelo que deve ser lido com essa reserva.';
    }
}
