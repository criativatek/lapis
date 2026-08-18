<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Identificação e caracterização da turma» — the first paragraph, and the one
 * that fixes what everything after it is about.
 *
 * IT STATES THE SCOPE EXPLICITLY (§29). A report whose first paragraph does not
 * say which stretch of time it covers produces sentences that are true of
 * something and the reader cannot tell of what.
 *
 * IT COUNTS THE CLASS AS IT IS, AND SAYS WHAT IT LOST (§58). «26 alunos» means
 * the 26 currently enrolled, not 28 including two who transferred out — but the
 * two who left are mentioned, because a class that shrank mid-year is context
 * for every figure below. Where `left_on` was never filled the sentence does not
 * claim to know when they left.
 */
class ClassIdentificationComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::ClassIdentification;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $class = $context->fact('class');
        $roster = $context->fact('roster');

        if (! is_array($class) || ! is_array($roster)) {
            return ComposedSection::empty();
        }

        $active = (int) ($roster['active'] ?? 0);

        $identification = Phrase::sentence(
            'A turma',
            (string) $class['label'],
            ', da disciplina de '.$class['subject'].',',
            'é constituída por',
            Phrase::students($active),
        );

        $scope = Phrase::sentence(
            'O presente relatório reporta-se a',
            $context->scopeLabel(),
            $context->readsSnapshot()
                ? 'e tem por base a avaliação intercalar registada nessa data'
                : null,
        );

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([$identification, $scope]),
                Phrase::paragraph([
                    $this->lateEntries($roster),
                    $this->departures($roster),
                ]),
            ]),
            [ContentSource::Results, ContentSource::Identity],
            [
                'class' => $class,
                'roster' => $roster,
                'scope' => [
                    'label' => $context->scopeLabel(),
                    'kind' => $context->report->scope_kind->value,
                ],
            ],
        );
    }

    /**
     * Late entry is a fact about the class, never a deficiency of the student:
     * an instrument applied before they arrived is not something they failed
     * (§11.4).
     *
     * @param  array<string, mixed>  $roster
     */
    protected function lateEntries(array $roster): ?string
    {
        $late = (int) ($roster['late_entries'] ?? 0);

        if ($late === 0) {
            return null;
        }

        return $late === 1
            ? 'Um aluno integrou a turma após o início do ano letivo, pelo que não realizou os instrumentos anteriores à sua entrada.'
            : $late.' alunos integraram a turma após o início do ano letivo, pelo que não realizaram os instrumentos anteriores à sua entrada.';
    }

    /**
     * @param  array<string, mixed>  $roster
     */
    protected function departures(array $roster): ?string
    {
        $departed = (int) ($roster['departed'] ?? 0);

        if ($departed === 0) {
            return null;
        }

        $known = (int) ($roster['departed_with_known_date'] ?? 0);

        $sentence = $departed === 1
            ? 'Um aluno deixou de integrar a turma no decurso do ano letivo'
            : $departed.' alunos deixaram de integrar a turma no decurso do ano letivo';

        // The results they produced before leaving remain part of the history
        // of the class, and the report says so rather than letting the reader
        // wonder whether they were removed from the figures.
        $sentence .= '. Os resultados que obtiveram enquanto integraram a turma mantêm-se no historial';

        return $known < $departed
            ? Phrase::terminate($sentence.', não estando registada a data de saída')
            : Phrase::terminate($sentence);
    }
}
