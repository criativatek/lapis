<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Autoavaliação dos alunos».
 *
 * AN OPINION, PLACED BESIDE A DECISION — NEVER ADDED TO IT (§7). A
 * self-assessment is what the student said about themselves. It has no weight,
 * it never enters an average, and it never moves a classification. What it is
 * good for is the comparison: where a class systematically rates itself above
 * or below what was decided, that gap is worth a teacher's attention, and
 * stating it is description.
 *
 * THE COMPARISON IS ONLY MADE WHERE IT MEANS SOMETHING. Both readings have to
 * be on the same levelled scale for «acima» and «abaixo» to have any content;
 * where they are not, the section reports coverage and stops rather than
 * comparing incomparables.
 *
 * OFF BY DEFAULT in a class report. It is the students' voice, and whether it
 * belongs in a document about the class is the teacher's decision (§45).
 */
class ClassSelfAssessmentComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::ClassSelfAssessment;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $students = $context->fact('students');

        if (! is_array($students) || $students === []) {
            return ComposedSection::of(Absence::noSelfAssessment(), [ContentSource::SelfAssessment]);
        }

        $total = count($students);
        $answered = 0;
        $above = 0;
        $aligned = 0;
        $below = 0;
        $comparable = 0;

        foreach ($students as $student) {
            $self = $student['self_assessment'] ?? null;

            if (! is_array($self) || ($self['sequence'] ?? null) === null) {
                continue;
            }

            $answered++;

            $assigned = $student['assigned'] ?? null;

            if (! is_array($assigned) || ($assigned['sequence'] ?? null) === null) {
                continue;
            }

            $comparable++;

            match ((int) $self['sequence'] <=> (int) $assigned['sequence']) {
                1 => $above++,
                -1 => $below++,
                default => $aligned++,
            };
        }

        if ($answered === 0) {
            return ComposedSection::of(Absence::noSelfAssessment(), [ContentSource::SelfAssessment]);
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    Phrase::sentence(
                        'Registaram autoavaliação',
                        Phrase::outOfTotal($answered, $total),
                    ),
                    $this->comparisonSentence($comparable, $above, $aligned, $below),
                ]),
                // The reminder is not padding: a reader who sees two readings
                // side by side will otherwise assume one influenced the other.
                $comparable > 0
                    ? 'A autoavaliação é o registo da apreciação do próprio aluno e não entra no cálculo dos resultados nem na classificação atribuída.'
                    : null,
            ]),
            [ContentSource::SelfAssessment, ContentSource::Classification],
            [
                'students_total' => $total,
                'answered' => $answered,
                'comparable' => $comparable,
                'above' => $above,
                'aligned' => $aligned,
                'below' => $below,
            ],
        );
    }

    protected function comparisonSentence(int $comparable, int $above, int $aligned, int $below): ?string
    {
        if ($comparable === 0) {
            return null;
        }

        return Phrase::sentence(
            'Comparando com a classificação atribuída, e entre os',
            (string) $comparable,
            'alunos com ambos os registos,',
            Phrase::items(array_values(array_filter([
                $aligned > 0 ? Phrase::students($aligned).' '.($aligned === 1 ? 'coincidiu' : 'coincidiram').' com a decisão do professor' : null,
                $above > 0 ? Phrase::students($above).' '.($above === 1 ? 'situou-se' : 'situaram-se').' acima' : null,
                $below > 0 ? Phrase::students($below).' '.($below === 1 ? 'situou-se' : 'situaram-se').' abaixo' : null,
            ]))),
        );
    }
}
