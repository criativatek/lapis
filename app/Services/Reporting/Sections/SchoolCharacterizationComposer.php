<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Comportamento e atitude — síntese das caracterizações» (§27).
 *
 * NOT INFERRED FROM OCCURRENCES, AND THE SECTION SAYS SO. Counting disciplinary
 * records across a school tells you how much was written down, not how the
 * school behaved — and the temptation to present the first as the second is
 * exactly why this section exists in the shape it does. What it aggregates is
 * what teachers VALIDATED in their own class reports.
 *
 * FROM FINALIZED REPORTS ONLY. A draft is somebody still thinking, and an
 * institutional figure built on drafts would move as colleagues edited theirs.
 *
 * COVERAGE TRAVELS WITH THE PERCENTAGE. «62%» over four characterised classes
 * out of forty is a different claim from «62%» over thirty-eight, and the
 * sentence carries both numbers so the reader never has to ask.
 */
class SchoolCharacterizationComposer extends SchoolSectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::SchoolCharacterization;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $characterisation = $context->fact('characterisation');

        if (! is_array($characterisation)) {
            return ComposedSection::empty();
        }

        $withAttitude = (int) ($characterisation['with_attitude'] ?? 0);
        $total = (int) ($characterisation['classes_total'] ?? 0);

        if ($withAttitude === 0) {
            return ComposedSection::of(
                Phrase::body([
                    'Não existem caracterizações validadas em relatórios de turma finalizados no âmbito analisado.',
                    'O comportamento e a atitude não são inferidos a partir dos registos disciplinares: são caracterizações que cada docente valida no relatório da sua turma.',
                ]),
                [ContentSource::TeacherInput],
                ['characterisation' => $characterisation],
            );
        }

        $rate = Phrase::percentage($characterisation['favourable_rate'] ?? null);

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    Phrase::sentence(
                        // Feminine, so spelled out rather than run through the
                        // masculine helper.
                        $withAttitude === $total
                            ? ($total === 1 ? 'Na única turma' : 'Nas '.$total.' turmas')
                            : 'Em '.$withAttitude.' de '.$total.' turmas',
                        ', o relatório de turma finalizado inclui uma caracterização da atitude face às aprendizagens',
                    ),
                    $rate === null
                        ? null
                        : Phrase::sentence(
                            'Dessas,',
                            $rate,
                            'foram caracterizadas como positivas ou muito positivas',
                        ),
                ]),
                'Estes valores resumem o que os docentes registaram e não resultam de qualquer inferência a partir de ocorrências disciplinares.',
            ]),
            [ContentSource::TeacherInput],
            ['characterisation' => $characterisation],
        );
    }
}
