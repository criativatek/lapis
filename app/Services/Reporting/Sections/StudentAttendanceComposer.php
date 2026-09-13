<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Assiduidade» para um relatório individual (§ do briefing de assiduidade).
 *
 * A MESMA DISTINÇÃO DE TRÊS ESTADOS que a página Evolução do Aluno já mostra:
 * presente, falta e «assiduidade não registada» — nunca dois estados, porque
 * fundir «não registada» em qualquer um dos outros inventaria uma observação
 * que ninguém fez (§ regra central de assiduidade).
 *
 * NÃO REESCREVÍVEL PELO ASSISTENTE DE ESCRITA (SectionCatalogue::NOT_REWRITABLE):
 * é uma contagem, não uma opinião.
 */
class StudentAttendanceComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::StudentAttendance;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $attendance = $context->fact('attendance');

        if (! is_array($attendance) || ($attendance['available'] ?? false) !== true) {
            return ComposedSection::of(Absence::noData('assiduidade'), [ContentSource::Attendance]);
        }

        $totals = is_array($attendance['totals'] ?? null) ? $attendance['totals'] : [];
        $recorded = (int) ($totals['recorded'] ?? 0);
        $present = (int) ($totals['present'] ?? 0);
        $absent = (int) ($totals['absent'] ?? 0);
        $notRecorded = (int) ($totals['not_recorded'] ?? 0);

        if ($recorded === 0 && $notRecorded === 0) {
            return ComposedSection::of(Absence::noAttendance(), [ContentSource::Attendance]);
        }

        $rows = is_array($attendance['rows'] ?? null) ? array_values($attendance['rows']) : [];

        $mainSentence = $recorded === 0
            ? Phrase::sentence('Não existe assiduidade consolidada', $context->whenClause())
            : Phrase::sentence(
                'Registaram-se',
                Phrase::count($present, 'presença', 'presenças'),
                'e',
                Phrase::count($absent, 'falta', 'faltas'),
                'em',
                (string) $recorded,
                $recorded === 1 ? 'aula com assiduidade consolidada' : 'aulas com assiduidade consolidada',
                $context->whenClause(),
            );

        $pendingSentence = $notRecorded === 0
            ? null
            : Phrase::sentence(
                (string) $notRecorded,
                $notRecorded === 1 ? 'aula lecionada não tem' : 'aulas lecionadas não têm',
                'assiduidade registada.',
            );

        return ComposedSection::of(
            Phrase::paragraph([$mainSentence, $pendingSentence]),
            [ContentSource::Attendance],
            ['rows' => $rows, 'totals' => $totals],
        );
    }
}
