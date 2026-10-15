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

        // No methodological note (§3): that a self-assessment does not enter
        // the calculation is a fact about Lapispro, not about the student.
        return ComposedSection::of(
            Phrase::paragraph([
                ...$this->judgementSentences($self),
                $this->comparisonSentence($student, $self),
            ]),
            [ContentSource::SelfAssessment, ContentSource::Classification],
            ['self_assessment' => $self, 'assigned' => $student['assigned'] ?? null],
        );
    }

    /**
     * What the student said, and, when it was not said under the criteria the
     * class uses now, where it comes from. A 70% on a percentage scale must
     * never read as a 70 on the current one.
     *
     * @param  array<string, mixed>  $self
     * @return list<string>
     */
    protected function judgementSentences(array $self): array
    {
        $label = (string) $self['label'];
        $criteria = ($self['criteria'] ?? null) === null ? null : (string) $self['criteria'];

        if (($self['earlier'] ?? false) === true) {
            return [
                'A nova autoavaliação não inclui uma proposta global.',
                Phrase::sentence(
                    $criteria === null ? 'Na autoavaliação anterior,' : "Na autoavaliação anterior (critérios: {$criteria}),",
                    'o aluno apreciou o desempenho global como',
                    $label,
                ),
            ];
        }

        if (($self['previous_criteria'] ?? false) === true) {
            return [Phrase::sentence(
                $criteria === null
                    ? 'Na sua autoavaliação, respondida com critérios anteriores,'
                    : "Na sua autoavaliação, respondida com critérios anteriores ({$criteria}),",
                'o aluno apreciou o desempenho global como',
                $label,
            )];
        }

        return [Phrase::sentence('Na sua autoavaliação, o aluno apreciou o desempenho global como', $label)];
    }

    /**
     * @param  array<string, mixed>  $student
     * @param  array<string, mixed>  $self
     */
    protected function comparisonSentence(array $student, array $self): ?string
    {
        $assigned = $student['assigned'] ?? null;

        // A reference to an earlier self-assessment is not compared.
        if (($self['earlier'] ?? false) === true) {
            return null;
        }

        // Both readings have to be on the same levelled scale for «acima» and
        // «abaixo» to carry any content at all.
        if (! is_array($assigned) || ($assigned['sequence'] ?? null) === null || ($self['sequence'] ?? null) === null) {
            return null;
        }

        // …and the SAME scale: a self-assessment kept from earlier criteria may
        // have been given on another one, and a 4 there is not a 4 here.
        if (isset($self['scale_id'], $assigned['scale_id']) && (int) $self['scale_id'] !== (int) $assigned['scale_id']) {
            return null;
        }

        $grade = (string) ($assigned['code'] ?? $assigned['label'] ?? '');

        return Phrase::sentence(
            match ((int) $self['sequence'] <=> (int) $assigned['sequence']) {
                1 => 'Trata-se de uma apreciação mais elevada do que a classificação atribuída',
                -1 => 'Trata-se de uma apreciação mais baixa do que a classificação atribuída',
                default => 'A apreciação coincide com a classificação atribuída',
            },
            $grade === '' ? null : 'de '.$grade,
        );
    }
}
