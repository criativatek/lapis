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
    use DescribesHomeworkChecks;

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

        $kinds = is_array($records['kinds'] ?? null)
            ? array_values(array_filter($records['kinds'], 'is_array'))
            : [];

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->openingSentence($context, $records, $total),
                    $this->kindsSentence($kinds, $total),
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
    protected function openingSentence(ReportContext $context, array $records, int $total): string
    {
        $students = (int) ($records['students_involved'] ?? 0);

        // «efetuados», not «registados … registos»: the echo is small and it is
        // the kind of thing a person would not write.
        return Phrase::sentence(
            Phrase::capitalise($context->whenClause()),
            'foram efetuados',
            Phrase::records($total),
            $students === 0
                // Records with no student attached are about the class as a
                // whole; saying «envolvendo nenhum aluno» would be nonsense.
                ? 'relativos à turma'
                : ', envolvendo '.Phrase::students($students),
        );
    }

    /**
     * The kind that accounts for most of the logbook, and nothing more.
     *
     * THE TABLE CARRIES THE BREAKDOWN (§16). A sentence that recites every kind
     * with its two counts, directly above a table holding exactly that, is a
     * table read aloud — and with ten kinds it is unreadable.
     *
     * @param  list<array<string, mixed>>  $kinds
     */
    protected function kindsSentence(array $kinds, int $total): ?string
    {
        if (count($kinds) < 2) {
            return null;
        }

        // Already ordered by count by the source.
        $leading = $kinds[0];
        $count = (int) ($leading['records'] ?? 0);

        // Nothing dominates: naming a leader would invent an emphasis.
        if ($count * 2 <= $total) {
            return null;
        }

        $students = (int) ($leading['students_involved'] ?? 0);
        $detail = Phrase::records($count).($students > 0 ? ', '.Phrase::students($students) : '');

        return Phrase::sentence(
            'A maioria diz respeito a',
            mb_strtolower((string) $leading['label']),
            '('.$detail.')',
        );
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

        $sentences = $this->homeworkSentences($homework);

        return $sentences === [] ? null : Phrase::paragraph($sentences);
    }
}
