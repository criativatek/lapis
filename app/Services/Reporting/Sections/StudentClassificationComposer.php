<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Classificação atribuída» — the decision, and only the decision.
 *
 * FOUR STATEMENTS ARE KEPT APART IN THIS APPLICATION and this section prints
 * exactly one of them: the Média Ponderada is what was calculated, the proposal
 * is what the system suggested, the self-assessment is what the student said,
 * and the classification is what the teacher DECIDED. Only the last is a grade
 * (§7).
 *
 * A PROPOSAL IS NOT A GRADE, so a student whose classification is still only
 * proposed has none here. The section says so — «ainda não foi atribuída» — and
 * never falls back to printing the suggestion, which would put a number in the
 * teacher's mouth that they have not signed.
 *
 * WHERE THE DECISION DIFFERS FROM THE CALCULATION, THAT IS NOT AN ERROR. It is
 * the teacher exercising the judgement the whole system exists to support
 * (§3.3), and the section states both figures without editorialising about the
 * gap.
 */
class StudentClassificationComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::StudentClassification;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $student = $context->fact('student');

        if (! is_array($student)) {
            return ComposedSection::empty();
        }

        $assigned = $student['assigned'] ?? null;
        $classification = $student['classification'] ?? null;
        $value = is_array($classification) ? ($classification['final_value'] ?? null) : null;

        if (! is_array($assigned) && $value === null) {
            return ComposedSection::of(Absence::noClassifications(), [ContentSource::Classification]);
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->decisionSentence($assigned, $value),
                    $this->sideSentence($assigned),
                ]),
                Phrase::paragraph([$this->divergenceSentence($student, $value)]),
            ]),
            [ContentSource::Classification],
            [
                'assigned' => $assigned,
                'value' => $value,
                'status' => is_array($classification) ? ($classification['status'] ?? null) : null,
            ],
        );
    }

    protected function decisionSentence(mixed $assigned, mixed $value): string
    {
        $label = is_array($assigned) ? ($assigned['label'] ?? null) : null;
        $code = is_array($assigned) ? ($assigned['code'] ?? null) : null;

        // The grade as the teacher wrote it, with the scale's own word beside
        // it when there is one — never in its place.
        $written = is_string($value) && $value !== '' ? $value : (is_string($code) ? $code : null);

        return Phrase::sentence(
            'A classificação atribuída pelo professor foi',
            $written,
            is_string($label) && $label !== $written ? '('.$label.')' : null,
        );
    }

    protected function sideSentence(mixed $assigned): ?string
    {
        if (! is_array($assigned) || ! array_key_exists('is_negative', $assigned)) {
            return null;
        }

        // Which side of the scale is the SCALE's statement, never a threshold
        // this sentence invented.
        return $assigned['is_negative'] === true
            ? 'Trata-se, na escala em uso, de uma classificação negativa.'
            : 'Trata-se, na escala em uso, de uma classificação positiva.';
    }

    /**
     * @param  array<string, mixed>  $student
     */
    protected function divergenceSentence(array $student, mixed $value): ?string
    {
        $band = $student['band'] ?? null;

        if (! is_array($band) || ($band['label'] ?? null) === null || $value === null) {
            return null;
        }

        $assigned = $student['assigned'] ?? null;
        $assignedLabel = is_array($assigned) ? ($assigned['label'] ?? null) : null;

        if ($assignedLabel === null || $assignedLabel === $band['label']) {
            return null;
        }

        // WHAT WAS DECIDED, NOT HOW THE APPLICATION WORKS (§7). «A decisão do
        // professor prevalece sobre a proposta do sistema» is a sentence about
        // Lapispro, printed in a document about a child; the reader has no
        // «sistema» in mind and does not need one. Naming the classification as
        // the teacher's says the same thing in the vocabulary a school uses,
        // and the fact — the two values differ, and this is the one that
        // counts — survives intact.
        return Phrase::sentence(
            'O resultado calculado situava-se em',
            (string) $band['label'],
            '. A classificação que consta deste relatório é a atribuída pelo professor',
        );
    }
}
