<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Identificação» — who this report is about, and over what stretch of time.
 *
 * AN INDIVIDUAL REPORT NAMES ITS SUBJECT. That is not a privacy decision to be
 * weighed: the document exists to be about one student, is read by people
 * already entitled to know who, and would be useless anonymised (§28). What is
 * governed is everything else — a class report naming individuals is the case
 * that needs permission, and it is handled elsewhere.
 *
 * LATE ENTRY AND DEPARTURE ARE STATED AS CIRCUMSTANCE, NOT AS DEFICIT (§11.4,
 * §58). A student who arrived in November did not fail the instruments applied
 * in October, and a report that let the reader assume otherwise would be
 * misreporting the very thing the assessment core is careful about.
 */
class StudentIdentificationComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::StudentIdentification;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $enrollment = $context->fact('enrollment');
        $class = $context->fact('class');

        if (! is_array($enrollment) || ($enrollment['available'] ?? false) !== true || ! is_array($class)) {
            return ComposedSection::empty();
        }

        $number = $enrollment['class_number'];

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    Phrase::sentence(
                        (string) $enrollment['name'],
                        $number === null ? null : ', n.º '.$number,
                        ', da turma',
                        (string) $class['label'],
                        ', na disciplina de',
                        (string) $class['subject'],
                    ),
                    Phrase::sentence('O presente relatório reporta-se a', $context->scopeLabel()),
                ]),
                Phrase::paragraph([
                    $this->lateEntrySentence($enrollment),
                    $this->departureSentence($enrollment),
                ]),
            ]),
            [ContentSource::Results, ContentSource::Identity],
            ['enrollment' => $enrollment, 'class' => $class],
        );
    }

    /**
     * @param  array<string, mixed>  $enrollment
     */
    protected function lateEntrySentence(array $enrollment): ?string
    {
        if (($enrollment['is_late_entry'] ?? false) !== true) {
            return null;
        }

        return 'Integrou a turma após o início do ano letivo, pelo que não realizou os instrumentos anteriores à sua entrada — ausência que não é considerada como resultado.';
    }

    /**
     * @param  array<string, mixed>  $enrollment
     */
    protected function departureSentence(array $enrollment): ?string
    {
        if (($enrollment['is_current'] ?? true) === true) {
            return null;
        }

        $reason = is_string($enrollment['status_reason'] ?? null) ? $enrollment['status_reason'] : null;
        $left = is_string($enrollment['left_on'] ?? null) ? $enrollment['left_on'] : null;

        return Phrase::sentence(
            'À data deste relatório já não integra a turma',
            $reason === null ? null : '('.mb_strtolower($reason).')',
            // Without a recorded date the sentence does not invent one (§58).
            $left === null ? null : ', desde '.$left,
            '. Os resultados obtidos enquanto integrou a turma mantêm-se no historial',
        );
    }
}
