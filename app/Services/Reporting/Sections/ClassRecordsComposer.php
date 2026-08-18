<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Registos do período» — the logbook, counted.
 *
 * THE UNIT IS THE RECORD, AND THE OTHER UNIT IS SAID SEPARATELY (§70, §71).
 * «18 ocorrências disciplinares envolvendo 7 alunos» is two facts about two
 * different things, and the whole point of stating both is that neither can be
 * quietly rewritten as the other. «82% dos registos de TPC estavam realizados»
 * is a statement about 126 verifications; «18% dos alunos não trabalham» is a
 * statement about people and is not in the data.
 *
 * NOTHING HERE CONCLUDES (§12). Eight records mentioning attention is a count.
 * Whether the class has a problem with attention is a judgement, and it belongs
 * to the teacher — the characterisation step exists precisely so that this
 * section does not have to guess.
 *
 * AN EMPTY LOGBOOK IS NOT GOOD NEWS (§41). No records means nobody wrote
 * anything down. It does not mean nothing happened, and the sentence says so.
 */
class ClassRecordsComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::ClassRecords;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $records = $context->fact('records');

        if (! is_array($records)) {
            return ComposedSection::empty();
        }

        $total = (int) ($records['total'] ?? 0);

        if ($total === 0) {
            return ComposedSection::of(Absence::noRecords(), [ContentSource::Records]);
        }

        $kinds = is_array($records['kinds'] ?? null) ? array_values($records['kinds']) : [];

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->openingSentence($records, $total),
                    $this->kindsSentence($kinds),
                ]),
                Phrase::paragraph([$this->homeworkParagraph($records)]),
            ]),
            [ContentSource::Records],
            [
                'total' => $total,
                'students_involved' => $records['students_involved'] ?? 0,
                'kinds' => $kinds,
                'homework' => $records['homework'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $records
     */
    protected function openingSentence(array $records, int $total): string
    {
        $students = (int) ($records['students_involved'] ?? 0);

        return Phrase::sentence(
            'No período analisado foram registados',
            Phrase::records($total),
            $students === 0
                // Records with no student attached are about the class as a
                // whole; saying «envolvendo nenhum aluno» would be nonsense.
                ? 'relativos à turma'
                : ', envolvendo '.Phrase::students($students),
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

        $parts = array_map(function (array $kind): string {
            $count = (int) ($kind['records'] ?? 0);
            $students = (int) ($kind['students_involved'] ?? 0);

            $text = (string) $kind['label'].' — '.Phrase::records($count);

            return $students > 0
                ? $text.' ('.Phrase::students($students).')'
                : $text;
        }, $kinds);

        return Phrase::sentence('Por tipo:', Phrase::items($parts));
    }

    /**
     * Homework, which has its own unit and its own trap.
     *
     * @param  array<string, mixed>  $records
     */
    protected function homeworkParagraph(array $records): ?string
    {
        $homework = $records['homework'] ?? null;

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
                'de trabalho de casa',
                '. Em '.$done.' '.($done === 1 ? 'registo' : 'registos'),
                $rate === null ? null : '('.$rate.')',
                ', o trabalho encontrava-se realizado',
            ),
            // The denominator, stated so nobody converts records into people.
            Phrase::sentence(
                'Os valores acima referem-se a registos de verificação e não a alunos:',
                'as verificações incidiram sobre',
                Phrase::students((int) ($homework['students_involved'] ?? 0)),
            ),
        ]);
    }
}
