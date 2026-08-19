<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Registos e intervenções» for one student.
 *
 * ONLY WHAT WAS WRITTEN ABOUT THEM. Class-wide observations are excluded from
 * their counts by the source, because attributing to one person what was
 * recorded about twenty-six is the most quietly unfair thing an individual
 * report could do. Interventions dirigidas à turma are mentioned separately and
 * named as such.
 *
 * COUNTS, NOT CONCLUSIONS (§12). «Três registos de dificuldade» is a fact about
 * the logbook. What those three mean about the student is a judgement, and the
 * sections where a teacher makes judgements are elsewhere and behind a plan.
 *
 * AN EMPTY LOGBOOK SAYS SO PLAINLY. Nobody wrote anything down — which is not
 * the same as nothing having happened, and not the same as everything being
 * fine (§41).
 */
class StudentRecordsComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::StudentRecords;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $records = $context->fact('records');
        $interventions = $context->fact('interventions');

        $recordTotal = is_array($records) ? (int) ($records['total'] ?? 0) : 0;
        $interventionTotal = is_array($interventions) ? (int) ($interventions['total'] ?? 0) : 0;
        $classWide = is_array($interventions) ? (int) ($interventions['class_wide'] ?? 0) : 0;

        if ($recordTotal === 0 && $interventionTotal === 0 && $classWide === 0) {
            return ComposedSection::of(
                Phrase::body([Absence::noRecords(), Absence::noInterventions()]),
                [ContentSource::Records, ContentSource::Interventions],
            );
        }

        $kinds = is_array($records['kinds'] ?? null) ? array_values($records['kinds']) : [];

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $recordTotal === 0
                        ? Absence::noRecords()
                        : Phrase::sentence('No período analisado foram registados', Phrase::records($recordTotal)),
                    $this->kindsSentence($kinds),
                    $this->homeworkSentence($records),
                ]),
                Phrase::paragraph([
                    $this->interventionSentence($interventionTotal, $classWide),
                    $this->highlightedLines($interventions),
                ]),
            ]),
            [ContentSource::Records, ContentSource::Interventions],
            [
                'records' => ['total' => $recordTotal, 'kinds' => $kinds],
                'interventions' => ['total' => $interventionTotal, 'class_wide' => $classWide],
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $kinds
     */
    protected function kindsSentence(array $kinds): ?string
    {
        if ($kinds === []) {
            return null;
        }

        return Phrase::sentence('Distribuem-se por', Phrase::items(array_map(
            fn (array $kind) => mb_strtolower((string) $kind['label']).' ('.Phrase::records((int) $kind['records']).')',
            $kinds,
        )));
    }

    protected function homeworkSentence(mixed $records): ?string
    {
        $homework = is_array($records) ? ($records['homework'] ?? null) : null;

        if (! is_array($homework) || (int) ($homework['checks'] ?? 0) === 0) {
            return null;
        }

        $checks = (int) $homework['checks'];
        $done = (int) ($homework['done'] ?? 0);
        $rate = Phrase::percentage($homework['done_rate'] ?? null);

        return Phrase::sentence(
            'Em',
            Phrase::count($checks, 'verificação', 'verificações'),
            'de trabalho de casa, o trabalho encontrava-se realizado em',
            $done.' '.($done === 1 ? 'registo' : 'registos'),
            $rate === null ? null : '('.$rate.')',
        );
    }

    protected function interventionSentence(int $total, int $classWide): ?string
    {
        if ($total === 0 && $classWide === 0) {
            return Absence::noInterventions();
        }

        $parts = [];

        if ($total > 0) {
            $parts[] = Phrase::count($total, 'intervenção dirigida ao aluno', 'intervenções dirigidas ao aluno');
        }

        if ($classWide > 0) {
            // Named apart on purpose: being in a class that received a measure
            // is not the same as having received one.
            $parts[] = Phrase::count($classWide, 'intervenção dirigida à turma', 'intervenções dirigidas à turma');
        }

        return Phrase::sentence('Foram registadas', Phrase::items($parts));
    }

    protected function highlightedLines(mixed $interventions): ?string
    {
        $highlighted = is_array($interventions) ? ($interventions['highlighted'] ?? null) : null;

        if (! is_array($highlighted) || $highlighted === []) {
            return null;
        }

        $lines = array_map(function ($intervention): string {
            if (! is_array($intervention)) {
                return '';
            }

            $parts = array_filter([
                (string) ($intervention['title'] ?? ''),
                $intervention['domain'] === null ? null : 'domínio de '.$intervention['domain'],
                $intervention['status'] === null ? null : mb_strtolower((string) $intervention['status']),
            ]);

            return '— '.implode(', ', $parts).'.';
        }, $highlighted);

        return Phrase::paragraph(['Destacam-se as seguintes medidas:', implode("\n", array_filter($lines))]);
    }
}
