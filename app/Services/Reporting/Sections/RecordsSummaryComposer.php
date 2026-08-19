<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Síntese» — the headline counts of a Registos report (§22).
 *
 * THE TWO UNITS ARE NEVER MERGED. «Foram registadas 18 ocorrências
 * disciplinares, envolvendo 7 alunos» states both and converts neither. The
 * homework paragraph is the same discipline in the case where it matters most:
 * «126 verificações, realizadas em 82% dos registos» is about verifications,
 * and the sentence that follows says so explicitly, because the tempting
 * misreading — «18% dos alunos não trabalham» — is a claim about children that
 * the data does not contain (§70, §71).
 *
 * NO CAUSE IS OFFERED. A logbook records what somebody wrote down. More entries
 * in March than in January may mean March was worse, or that January was busy.
 * This section reports the counts and stops.
 */
class RecordsSummaryComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::RecordsSummary;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $total = (int) $context->fact('total', 0);

        if ($total === 0) {
            return ComposedSection::of(Absence::noRecords(), [ContentSource::Records]);
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->openingSentence($context, $total),
                    $this->spanSentence($context),
                ]),
                Phrase::paragraph([$this->homeworkParagraph($context)]),
                Phrase::paragraph([$this->monthsSentence($context)]),
            ]),
            [ContentSource::Records],
            [
                'total' => $total,
                'students_involved' => $context->fact('students_involved', 0),
                'months' => $context->fact('months', []),
                'homework' => $context->fact('homework'),
            ],
        );
    }

    protected function openingSentence(ReportContext $context, int $total): string
    {
        $students = (int) $context->fact('students_involved', 0);
        $classWide = (int) $context->fact('class_wide', 0);

        $parts = [];

        if ($students > 0) {
            $parts[] = 'envolvendo '.Phrase::students($students);
        }

        if ($classWide > 0) {
            $parts[] = $classWide === 1
                ? '1 relativo à turma no seu conjunto'
                : $classWide.' relativos à turma no seu conjunto';
        }

        return Phrase::sentence(
            'No âmbito analisado foram registados',
            Phrase::records($total),
            $parts === [] ? null : ', '.Phrase::items($parts),
        );
    }

    protected function spanSentence(ReportContext $context): ?string
    {
        $first = $context->fact('first_on');
        $last = $context->fact('last_on');

        if (! is_string($first) || ! is_string($last)) {
            return null;
        }

        return $first === $last
            ? Phrase::sentence('Todos os registos têm a data de', $this->readable($first))
            : Phrase::sentence('O primeiro tem data de', $this->readable($first), 'e o último de', $this->readable($last));
    }

    protected function homeworkParagraph(ReportContext $context): ?string
    {
        $homework = $context->fact('homework');

        if (! is_array($homework)) {
            return null;
        }

        $checks = (int) ($homework['checks'] ?? 0);

        if ($checks === 0) {
            return null;
        }

        $done = (int) ($homework['done'] ?? 0);
        $rate = Phrase::percentage($homework['done_rate'] ?? null);

        return Phrase::paragraph([
            Phrase::sentence(
                'Foram realizadas',
                Phrase::count($checks, 'verificação', 'verificações'),
                'de trabalho de casa. Em',
                $done.' '.($done === 1 ? 'registo' : 'registos'),
                $rate === null ? null : '('.$rate.')',
                ', o trabalho encontrava-se realizado',
            ),
            // THE SENTENCE THAT STOPS THE MISREADING.
            Phrase::sentence(
                'Estes valores referem-se a registos de verificação e não a alunos: as verificações incidiram sobre',
                Phrase::students((int) ($homework['students_involved'] ?? 0)),
            ),
        ]);
    }

    protected function monthsSentence(ReportContext $context): ?string
    {
        $months = $context->fact('months');

        if (! is_array($months) || count($months) < 2) {
            return null;
        }

        $parts = array_map(
            fn ($month) => is_array($month) ? (string) $month['label'].' — '.Phrase::records((int) $month['records']) : '',
            $months,
        );

        return Phrase::sentence(
            'Distribuição ao longo do tempo:',
            Phrase::items(array_values(array_filter($parts))),
        );
    }

    /** «2027-01-15» reads as «15/01/2027» in a Portuguese document. */
    protected function readable(string $date): string
    {
        $parts = explode('-', $date);

        return count($parts) === 3 ? $parts[2].'/'.$parts[1].'/'.$parts[0] : $date;
    }
}
