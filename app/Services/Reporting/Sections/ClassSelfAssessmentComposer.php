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
 * or below what was decided, that gap is worth a teacher's attention.
 *
 * THE COMPARISON IS DESCRIBED, NOT DIAGNOSED. «Três alunos autoavaliaram-se
 * abaixo da classificação atribuída» counts two recorded values. Whether that
 * says anything about confidence, motivation or self-esteem is a claim about
 * children's inner lives, and this module does not make those (§17, §78).
 *
 * NO METHODOLOGICAL NOTE IN THE BODY (§3). That a self-assessment does not
 * enter the calculation is true, obvious to the reader of a school report, and
 * a sentence about how LÁPIS works rather than about the class. It belongs in a
 * manual, not in the document a parent reads.
 */
class ClassSelfAssessmentComposer implements SectionComposer
{
    /** Below this many comparable readings, a «tendency» is noise (§17). */
    protected const TENDENCY_MINIMUM = 3;

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
                    $this->participationSentence($answered, $total),
                    $this->comparisonSentence($above, $aligned, $below),
                ]),
                $this->tendencySentence($comparable, $above, $below),
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

    /**
     * «Os seis alunos que constituem a turma realizaram a sua autoavaliação.»
     *
     * Two shapes rather than one counter, because «Registaram autoavaliação os
     * 6 alunos» is a sentence assembled around a number instead of around a
     * class (§2).
     */
    protected function participationSentence(int $answered, int $total): string
    {
        if ($answered === $total) {
            return $total === 1
                ? 'O único aluno da turma realizou a sua autoavaliação.'
                : Phrase::sentence(
                    'Os',
                    Phrase::spelled($total),
                    'alunos que constituem a turma realizaram a sua autoavaliação',
                );
        }

        return Phrase::sentence(
            'Dos',
            Phrase::students($total),
            'da turma,',
            Phrase::spelled($answered),
            $answered === 1 ? 'realizou a sua autoavaliação' : 'realizaram a sua autoavaliação',
        );
    }

    /**
     * «Um aluno autoavaliou-se acima da classificação atribuída, três abaixo e
     * dois coincidiram com a decisão do professor.»
     *
     * The first group carries the verb and the object; the ones after it are
     * elided, as a person writing would elide them.
     */
    protected function comparisonSentence(int $above, int $aligned, int $below): ?string
    {
        if ($above + $aligned + $below === 0) {
            return null;
        }

        $parts = [];

        if ($above > 0) {
            $parts[] = Phrase::studentsDid($above, 'autoavaliou-se', 'autoavaliaram-se')
                .' acima da classificação atribuída';
        }

        if ($below > 0) {
            $parts[] = $parts === []
                ? Phrase::studentsDid($below, 'autoavaliou-se', 'autoavaliaram-se').' abaixo da classificação atribuída'
                : Phrase::spelled($below).' abaixo';
        }

        if ($aligned > 0) {
            $parts[] = ($parts === [] ? Phrase::students($aligned).' ' : Phrase::spelled($aligned).' ')
                .($aligned === 1 ? 'coincidiu' : 'coincidiram')
                .' com a decisão do professor';
        }

        return Phrase::sentence(Phrase::items($parts));
    }

    /**
     * A tendency, and only where the numbers carry one.
     *
     * TWO CONDITIONS, BOTH NECESSARY (§17). One side has to outnumber the
     * other — «dois acima, dois abaixo» is a spread, not a lean — and it has to
     * account for at least half of the comparable readings, so that a plurality
     * of three out of nine does not get called a tendency. Never below three
     * readings at all: two students out of three is a coincidence with a
     * percentage attached.
     *
     * And the sentence describes the positions, never what they might mean
     * about the students (§78).
     */
    protected function tendencySentence(int $comparable, int $above, int $below): ?string
    {
        if ($comparable < self::TENDENCY_MINIMUM) {
            return null;
        }

        if ($below > $above && $below * 2 >= $comparable) {
            return Phrase::sentence(
                'Observa-se uma tendência para uma autoavaliação mais baixa do que a classificação atribuída, uma vez que',
                Phrase::spelled($below),
                'dos',
                Phrase::spelled($comparable),
                'alunos se posicionaram abaixo',
            );
        }

        if ($above > $below && $above * 2 >= $comparable) {
            return Phrase::sentence(
                'Observa-se uma tendência para uma autoavaliação mais elevada do que a classificação atribuída, uma vez que',
                Phrase::spelled($above),
                'dos',
                Phrase::spelled($comparable),
                'alunos se posicionaram acima',
            );
        }

        return null;
    }
}
