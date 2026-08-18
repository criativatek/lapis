<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Autoavaliação» — what the student said about themselves.
 *
 * IT IS THEIR VOICE, REPORTED AS THEIRS. Never added to a result, never
 * averaged with one, never used to move a classification (§7). What it is good
 * for is the gap: a student who consistently rates themselves below what was
 * decided is telling the teacher something no figure in this report says.
 *
 * THE GAP IS DESCRIBED, NOT DIAGNOSED. «Situou-se abaixo da classificação
 * atribuída» is a comparison of two recorded values. «Revela falta de
 * confiança» is a claim about a child's inner life, and this module does not
 * make those (§78).
 */
class StudentSelfAssessmentComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::StudentSelfAssessment;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $student = $context->fact('student');

        if (! is_array($student)) {
            return ComposedSection::empty();
        }

        $self = $student['self_assessment'] ?? null;

        if (! is_array($self) || ($self['label'] ?? null) === null) {
            return ComposedSection::of(Absence::noSelfAssessment(), [ContentSource::SelfAssessment]);
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    Phrase::sentence('Na autoavaliação, o aluno situou o seu desempenho global em', (string) $self['label']),
                    $this->comparisonSentence($student, $self),
                ]),
                'A autoavaliação é o registo da apreciação do próprio aluno e não entra no cálculo dos resultados nem na classificação atribuída.',
            ]),
            [ContentSource::SelfAssessment, ContentSource::Classification],
            ['self_assessment' => $self, 'assigned' => $student['assigned'] ?? null],
        );
    }

    /**
     * @param  array<string, mixed>  $student
     * @param  array<string, mixed>  $self
     */
    protected function comparisonSentence(array $student, array $self): ?string
    {
        $assigned = $student['assigned'] ?? null;

        // Both readings have to be on the same levelled scale for «acima» and
        // «abaixo» to carry any content at all.
        if (! is_array($assigned) || ($assigned['sequence'] ?? null) === null || ($self['sequence'] ?? null) === null) {
            return null;
        }

        return Phrase::sentence(
            match ((int) $self['sequence'] <=> (int) $assigned['sequence']) {
                1 => 'Esta apreciação situa-se acima da classificação atribuída',
                -1 => 'Esta apreciação situa-se abaixo da classificação atribuída',
                default => 'Esta apreciação coincide com a classificação atribuída',
            },
            '('.(string) ($assigned['label'] ?? '—').')',
        );
    }
}
