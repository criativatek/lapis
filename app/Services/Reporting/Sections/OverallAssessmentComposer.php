<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Síntese da avaliação global» — the headline paragraph.
 *
 * THREE THINGS ARE AT STAKE HERE AND EACH ONE HAS BEEN GOT WRONG BEFORE.
 *
 * 1. WHICH AVERAGE IS THE ANSWER (§7). «A média da turma» is ambiguous from the
 *    second period onwards. The figure that answers «como está a turma» at this
 *    moment is the primary reading — PrimaryResultScope decided it, the facts
 *    carry it, and the sentence NAMES it: «Média Ponderada Acumulada» or «Média
 *    Ponderada», never a bare «média». The other reading is stated beside it
 *    when there is one, also named.
 *
 * 2. WHAT COUNTS AS SUCCESS. The classification the teacher ASSIGNED, never the
 *    mention an average happens to land on, never a proposal, never the
 *    student's own view (§7). And which side a decision is on is the scale's
 *    statement through `is_negative` — nothing here knows what a «3» is, so a
 *    school with «Não atingiu / Atingiu / Superou» gets exactly those.
 *
 * 3. WHO IS IN THE DENOMINATOR. Students with no classification are not
 *    failures; they are outside the rate entirely, counted and named. A rate
 *    that quietly divided by the whole class would report an insucesso nobody
 *    decided (§41).
 */
class OverallAssessmentComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::OverallAssessment;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $summary = $context->fact('summary');

        if (! is_array($summary)) {
            return ComposedSection::of(
                Absence::noData('os resultados da turma'),
                [ContentSource::Statistics],
            );
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->averageSentence($context, $summary),
                    $this->supplementarySentence($context, $summary),
                    Absence::studentsWithoutResult((int) ($summary['students_without_result'] ?? 0)),
                    $this->partialCoverage($summary),
                ]),
                Phrase::paragraph([
                    $this->successSentence($summary),
                    $this->unclassifiedSentence($summary),
                ]),
            ]),
            [ContentSource::Statistics, ContentSource::Results, ContentSource::Classification],
            [
                'summary' => $summary,
                'primary' => $context->fact('primary'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function averageSentence(ReportContext $context, array $summary): ?string
    {
        $value = Phrase::percentage($summary['primary_average'] ?? null);

        if ($value === null) {
            return Absence::noData('a média da turma');
        }

        // The reading's own name, from the read model. A snapshot never recorded
        // which one was primary, so there the figure is named for what it
        // literally is instead of being labelled with a guess (§30).
        $label = $context->fact('primary.label');

        if (! is_string($label)) {
            $label = Phrase::percentage($summary['accumulated_average'] ?? null) === $value
                ? 'Média Ponderada Acumulada'
                : 'Média Ponderada';
        }

        return Phrase::sentence(
            'No período analisado, a',
            $label,
            'da turma foi de',
            $value,
        );
    }

    /**
     * The second reading, when there is one.
     *
     * Only from the moment the two figures genuinely differ in meaning. At the
     * first contributing period they are the same number, and printing both
     * would invent a distinction the data does not have (§7).
     *
     * @param  array<string, mixed>  $summary
     */
    protected function supplementarySentence(ReportContext $context, array $summary): ?string
    {
        if ($context->fact('primary.has_supplementary') !== true) {
            return null;
        }

        $value = Phrase::percentage($summary['supplementary_average'] ?? null);

        if ($value === null) {
            return null;
        }

        return Phrase::sentence(
            'Considerando apenas o trabalho realizado neste período, a Média Ponderada foi de',
            $value,
        );
    }

    /**
     * A result that exists and rests on less than all the expected evidence.
     *
     * Distinct from having no result at all, and said as such: «parcial»
     * describes a value, and a student with nothing to be partial ABOUT belongs
     * in the previous sentence, not this one.
     *
     * @param  array<string, mixed>  $summary
     */
    protected function partialCoverage(array $summary): ?string
    {
        $partial = (int) ($summary['partial_coverage_count'] ?? 0);

        if ($partial === 0) {
            return null;
        }

        return $partial === 1
            ? 'O resultado de 1 aluno assenta em parte dos instrumentos previstos.'
            : 'Os resultados de '.$partial.' alunos assentam em parte dos instrumentos previstos.';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function successSentence(array $summary): ?string
    {
        $success = $summary['success'] ?? null;

        if (! is_array($success)) {
            return null;
        }

        $placed = (int) ($success['placed'] ?? 0);

        if ($placed === 0) {
            return Absence::noClassifications();
        }

        $succeeded = (int) ($success['succeeded'] ?? 0);
        $rate = Phrase::percentage($success['rate'] ?? null);

        // «positiva» is the scale's own word for the side, not a threshold this
        // sentence invented: `is_negative` decided it upstream.
        return Phrase::sentence(
            'Das classificações atribuídas,',
            Phrase::outOfTotal($succeeded, $placed, 'foi positiva', 'foram positivas'),
            $rate === null ? null : '— uma taxa de sucesso de '.$rate,
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function unclassifiedSentence(array $summary): ?string
    {
        $success = $summary['success'] ?? null;

        if (! is_array($success)) {
            return null;
        }

        $without = (int) ($success['without_classification'] ?? 0);
        $unplaced = (int) ($success['unplaced'] ?? 0);

        $parts = [];

        if ($without > 0) {
            // NEVER counted as failures, and the sentence says why they are not
            // in the rate rather than leaving the reader to assume (§41).
            $parts[] = $without === 1
                ? '1 aluno não tem ainda classificação atribuída, pelo que não é considerado no cálculo da taxa de sucesso'
                : $without.' alunos não têm ainda classificação atribuída, pelo que não são considerados no cálculo da taxa de sucesso';
        }

        if ($unplaced > 0) {
            $parts[] = $unplaced === 1
                ? '1 classificação atribuída não é posicionável na escala em uso'
                : $unplaced.' classificações atribuídas não são posicionáveis na escala em uso';
        }

        return $parts === [] ? null : Phrase::sentence(Phrase::items($parts));
    }
}
