<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Aulas» no relatório de turma (0.146.0): as seis contagens de
 * ClassLessonRecord e o detalhe cronológico. Uma contagem mecânica — nunca
 * reescrita pelo assistente (SectionCatalogue::NOT_REWRITABLE).
 */
class ClassLessonsComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::ClassLessons;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $lessons = $context->fact('lessons');

        if (! is_array($lessons) || ($lessons['available'] ?? false) !== true) {
            return ComposedSection::of(Absence::noData('aulas'), [ContentSource::Lessons]);
        }

        /** @var array<string, int> $totals */
        $totals = is_array($lessons['totals'] ?? null) ? $lessons['totals'] : [];
        $rows = is_array($lessons['rows'] ?? null) ? array_values($lessons['rows']) : [];

        if (($totals['planned'] ?? 0) === 0) {
            return ComposedSection::of(Absence::noLessons(), [ContentSource::Lessons]);
        }

        // Algarismos, e nenhum número por extenso: é uma contagem, lida lado a
        // lado com a tabela.
        $sentence = Phrase::sentence(
            'Resumo das aulas',
            $context->whenClause().':',
            'previstas, '.(int) $totals['planned'].';',
            'contabilizadas como lecionadas, '.(int) ($totals['counted_as_taught'] ?? 0).';',
            'com desenvolvimento efetivo da disciplina, '.(int) ($totals['subject_development'] ?? 0).';',
            'professor ausente, '.(int) ($totals['teacher_absent'] ?? 0).';',
            'turma em outras atividades letivas, '.(int) ($totals['class_external_activity'] ?? 0).';',
            'por registar, '.(int) ($totals['not_recorded'] ?? 0),
        );

        return ComposedSection::of(
            $sentence,
            [ContentSource::Lessons],
            ['totals' => $totals, 'rows' => $rows],
        );
    }
}
