<?php

namespace App\Services\Reporting\Sections;

use App\Services\Reporting\Narrative\Phrase;

/**
 * The homework paragraph, written once for the two reports that print it.
 *
 * IT EXISTED TWICE AND DRIFTED ONCE. The Registos report and the class report's
 * Registos section carried the same three sentences, copied — which is how the
 * same wording problem shipped in both places. One of them is now enough.
 *
 * THE UNIT IS THE CHECK, NOT THE STUDENT. «Foram realizadas seis verificações»
 * counts events, and «em dois registos (33,3%)» is a share of those events. A
 * reader who takes the percentage for a share of STUDENTS has read something the
 * sentence does not say — which is why the module used to append a caveat.
 *
 * BUT THE CAVEAT WAS PRINTED BY ROUTINE, AND THAT IS THE BUG. Six checks over
 * six students leaves nothing to disambiguate: the two numbers are the same and
 * the reader cannot go wrong. Adding «estes valores contam verificações e não
 * alunos» there does not clarify — it introduces a distinction the data does not
 * have, and reads as a warning about a mistake nobody was about to make.
 *
 * So the rule is: EXPLAIN WHEN THE TWO NUMBERS DIFFER, AND ONLY THEN. Six checks
 * over three students is a real ambiguity and gets a sentence. Six over six gets
 * silence, which is the correct amount of explanation for something already
 * unambiguous.
 */
trait DescribesHomeworkChecks
{
    /**
     * @param  array<string, mixed>  $homework
     * @return list<string>
     */
    protected function homeworkSentences(array $homework): array
    {
        $checks = (int) ($homework['checks'] ?? 0);

        if ($checks === 0) {
            return [];
        }

        $students = (int) ($homework['students_involved'] ?? 0);
        $done = (int) ($homework['done'] ?? 0);
        $rate = Phrase::percentage($homework['done_rate'] ?? null);

        return array_values(array_filter([
            Phrase::sentence(
                // «Foi realizada uma verificação» — the verb agrees with the
                // count, and one check is not «foram realizadas».
                $checks === 1 ? 'Foi realizada' : 'Foram realizadas',
                Phrase::count($checks, 'verificação', 'verificações', feminine: true),
                'de trabalho de casa',
                // «envolvendo», not «sobre»: the checks were made about work,
                // and the students are who they involved. It is also the word
                // the summary sentence above this paragraph already uses.
                $students > 0 ? ', envolvendo '.Phrase::students($students) : null,
            ),
            Phrase::sentence(
                // NOT «dois alunos realizaram o trabalho». Two records may
                // belong to one student, and turning records into people is
                // exactly the misreading this paragraph is careful about.
                'O trabalho encontrava-se realizado em',
                Phrase::records($done),
                $rate === null ? null : '('.$rate.')',
            ),
            // Null when there is nothing to disambiguate, which is most of the
            // time — and dropped here rather than printed as a blank line.
            $this->recordsAmbiguityNote($checks, $students, $rate),
        ], fn (?string $sentence): bool => $sentence !== null && $sentence !== ''));
    }

    /**
     * The note that stops a percentage over records being read as a percentage
     * over students — printed only when the two could actually be confused.
     *
     * Three conditions, and all three are required:
     *
     *   a percentage is shown     without one there is no share to misread;
     *   somebody is involved      a class-wide count names no students at all;
     *   there are more checks
     *   than students             which is the only case where one person can
     *                             be behind more than one of the counted events.
     */
    protected function recordsAmbiguityNote(int $checks, int $students, ?string $rate): ?string
    {
        if ($rate === null || $students === 0 || $checks <= $students) {
            return null;
        }

        return 'A percentagem refere-se ao número de registos efetuados, podendo o mesmo aluno estar associado a mais do que um registo.';
    }
}
