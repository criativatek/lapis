<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Assiduidade» para o relatório de turma: uma linha por aluno com
 * presenças/faltas/sem registo, nunca uma média ou uma percentagem (§ do
 * briefing de assiduidade não pede nenhuma).
 *
 * OFF POR OMISSÃO (SectionCatalogue::classSections): nomeia cada aluno numa
 * tabela, a mesma decisão que já desliga `RecordsTimeline` e
 * `StudentsRequiringAttention` por defeito (§28, §57).
 */
class ClassAttendanceComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::ClassAttendance;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $attendance = $context->fact('attendance');

        if (! is_array($attendance) || ($attendance['available'] ?? false) !== true) {
            return ComposedSection::of(Absence::noData('assiduidade'), [ContentSource::Attendance]);
        }

        $rows = is_array($attendance['rows'] ?? null) ? array_values($attendance['rows']) : [];

        if ($rows === []) {
            return ComposedSection::of(Absence::noAttendance(), [ContentSource::Attendance]);
        }

        $totalPresent = array_sum(array_column($rows, 'present'));
        $totalAbsent = array_sum(array_column($rows, 'absent'));
        $totalNotRecorded = array_sum(array_column($rows, 'not_recorded'));

        if ($totalPresent === 0 && $totalAbsent === 0 && $totalNotRecorded === 0) {
            return ComposedSection::of(Absence::noAttendance(), [ContentSource::Attendance]);
        }

        $sentence = Phrase::sentence(
            'A tabela seguinte apresenta, por aluno,',
            Phrase::count($totalPresent, 'presença', 'presenças'),
            Phrase::count($totalAbsent, 'falta', 'faltas'),
            'e',
            $totalNotRecorded === 0 ? 'nenhuma aula por registar' : Phrase::count($totalNotRecorded, 'aula por registar', 'aulas por registar'),
            $context->whenClause(),
        );

        return ComposedSection::of(
            $sentence,
            [ContentSource::Attendance],
            ['rows' => $rows],
        );
    }
}
