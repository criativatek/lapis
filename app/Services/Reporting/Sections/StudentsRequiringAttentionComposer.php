<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Alunos que requerem acompanhamento particular» (§28, §57).
 *
 * THE ONE SECTION THAT CAN PUT A MINOR'S NAME ON A CLASS-WIDE DOCUMENT, and it
 * is governed accordingly. Two independent decisions have to have been made
 * before a name is printed: the teacher listed the students, and the teacher
 * separately allowed the report to identify them. Either one alone is not
 * enough. Without the second, the section still exists and still says how many
 * were flagged — a conselho de turma needs to know there are three, and does
 * not always need the document to say which three.
 *
 * NOBODY IS FLAGGED AUTOMATICALLY. There is no «alunos com média inferior a»
 * anywhere in this file. A list of the lowest results is not a list of students
 * who need following, and generating one would be a system deciding something
 * only a teacher can (§57, §59).
 *
 * The words are the teacher's throughout: no adjective is added to a name.
 */
class StudentsRequiringAttentionComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::StudentsRequiringAttention;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $flagged = $this->flagged($context);

        if ($flagged === []) {
            return ComposedSection::empty();
        }

        $count = count($flagged);

        if (! $context->namesStudents()) {
            return ComposedSection::of(
                Phrase::body([
                    Phrase::sentence(
                        $count === 1
                            ? 'Foi assinalado 1 aluno que requer acompanhamento particular'
                            : 'Foram assinalados '.$count.' alunos que requerem acompanhamento particular',
                    ),
                    'A identificação individual não foi incluída neste relatório.',
                ]),
                [ContentSource::TeacherInput],
                // The count travels; the names do not. Nothing downstream — an
                // export, a preview — can print what is not here.
                ['count' => $count, 'identified' => false],
            );
        }

        $lines = array_map(function (array $student): string {
            $note = is_string($student['note'] ?? null) ? trim($student['note']) : '';

            return $note === ''
                ? '— '.$student['name'].'.'
                : '— '.$student['name'].': '.Phrase::terminate($note);
        }, $flagged);

        return ComposedSection::of(
            Phrase::body([
                $count === 1
                    ? 'Requer acompanhamento particular o seguinte aluno:'
                    : 'Requerem acompanhamento particular os seguintes alunos:',
                implode("\n", $lines),
            ]),
            [ContentSource::TeacherInput],
            ['count' => $count, 'identified' => true, 'students' => $flagged],
        );
    }

    /**
     * The students the teacher listed, with their names resolved from the facts
     * already in hand.
     *
     * A name that cannot be resolved is dropped rather than printed as an id:
     * an enrolment that left the class between the listing and the generation
     * should not appear as «Aluno 42».
     *
     * @return list<array<string, mixed>>
     */
    protected function flagged(ReportContext $context): array
    {
        $chosen = $context->input('students_requiring_attention');

        if (! is_array($chosen) || $chosen === []) {
            return [];
        }

        $names = [];

        foreach ((array) $context->fact('students', []) as $student) {
            if (is_array($student) && isset($student['enrollment_id'])) {
                $names[(int) $student['enrollment_id']] = (string) ($student['name'] ?? '');
            }
        }

        $flagged = [];

        foreach ($chosen as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['enrollment_id'] ?? 0);
            $name = $names[$id] ?? null;

            if ($name === null || $name === '') {
                continue;
            }

            $flagged[] = [
                'enrollment_id' => $id,
                'name' => $name,
                'note' => $row['note'] ?? null,
            ];
        }

        return $flagged;
    }
}
