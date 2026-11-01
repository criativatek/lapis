<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalStudent;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Assessment\ClassCohort;

/**
 * Suggests which enrolment each row of the file belongs to — and refuses to
 * decide when it cannot be certain.
 *
 * Getting this wrong writes one student's marks onto another, which is the
 * worst thing this feature could do and the reason none of it is clever. The
 * ladder is short and each rung is exact: a class number that matches one
 * student, a name that matches one student, a normalised name that matches one
 * student. Anything matching two students matches nobody and goes to the
 * teacher. There is no fuzzy matching, on purpose — a near-miss that looks
 * confident is worse than an honest blank.
 *
 * Names are encrypted at rest, so nothing here goes near a LIKE. The class's own
 * enrolments are loaded — those the teacher is already authorised to see — and
 * compared in memory. No name is ever logged, and none reaches an exception.
 */
class MatchSourceStudents
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_IGNORED = 'ignored';

    /** Words that join Portuguese surnames and are never a surname themselves. */
    protected const PARTICLES = ['de', 'da', 'do', 'das', 'dos', 'e', 'd'];

    /**
     * @param  array<string, int|null>  $alreadyDecided  decisions the teacher already made, which always win
     * @param  string|null  $referenceDate  the date the grid/application itself refers to (M6) — an
     *                                      instrument's `applied_on`, when known. Resolving the cohort at TODAY instead
     *                                      would make a grid imported months after it was applied unmatchable against a
     *                                      student who has since stopped attending: his scores at the time the grid was
     *                                      applied are real regardless of what changed afterwards.
     * @return list<array<string, mixed>>
     */
    public function for(CanonicalCorrectionGrid $grid, SchoolClass $class, array $alreadyDecided = [], ?string $referenceDate = null): array
    {
        $enrollments = $this->enrollments($class, $referenceDate);

        $byNumber = [];
        $byName = [];
        $byNormalised = [];
        $byFirstAndLast = [];

        foreach ($enrollments as $enrollment) {
            if ($enrollment['class_number'] !== null) {
                $byNumber[$enrollment['class_number']][] = $enrollment;
            }

            if ($enrollment['name'] !== null) {
                $byName[$enrollment['name']][] = $enrollment;
                $byNormalised[$this->normalise($enrollment['name'])][] = $enrollment;

                $firstAndLast = $this->firstAndLast($enrollment['name']);

                if ($firstAndLast !== null) {
                    $byFirstAndLast[$firstAndLast][] = $enrollment;
                }
            }
        }

        $taken = array_values(array_filter($alreadyDecided, fn (?int $id): bool => $id !== null));

        // The whole class travels with every row. Without it a teacher facing an
        // unmatched student has an empty dropdown and nothing to choose — the
        // conservative matcher would be correct and useless at the same time.
        $everyone = array_map(fn (array $row): array => ['id' => $row['id'], 'label' => $row['label']], $enrollments);

        // Claims accumulate as the file is walked, and not only from decisions
        // already taken.
        //
        // Without this, two rows whose names normalise to the same student were
        // BOTH shown as matched, and the collision surfaced two screens later:
        // saving keeps whichever row claimed them first and returns the other to
        // undecided, so a teacher who had just been told everything was matched
        // pressed confirm and was refused. The «already_taken» answer existed
        // and was simply never reachable except by an explicit decision.
        //
        // Same rule as the save, and in the same order — first row in the file
        // keeps the student — so the preview and the door now agree, which is
        // the one thing this class exists to guarantee.
        $rows = [];

        foreach ($grid->students as $student) {
            $row = $this->row($student, $alreadyDecided, $byNumber, $byName, $byNormalised, $byFirstAndLast, $taken, $everyone);

            if ($row['enrollment_id'] !== null) {
                $taken[] = $row['enrollment_id'];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, int|null>  $alreadyDecided
     * @param  array<int|string, list<array<string, mixed>>>  $byNumber
     * @param  array<string, list<array<string, mixed>>>  $byName
     * @param  array<string, list<array<string, mixed>>>  $byNormalised
     * @param  array<string, list<array<string, mixed>>>  $byFirstAndLast
     * @param  list<int>  $taken
     * @param  list<array<string, mixed>>  $everyone  the whole class, so a manual choice is always possible
     * @return array<string, mixed>
     */
    protected function row(CanonicalStudent $student, array $alreadyDecided, array $byNumber, array $byName, array $byNormalised, array $byFirstAndLast, array $taken, array $everyone = []): array
    {
        $base = [
            'source_key' => $student->sourceKey,
            'card_number' => $student->cardNumber,
            'display_name' => $student->displayName,
            'source_score' => $student->sourceScore,
            // Correct and answered travel together: «11 de 20 certas, 20
            // respondidas» is what a teacher recognises from the platform, and
            // it is also what distinguishes a poor result from an absent one.
            'source_correct' => $student->sourceCorrect,
            'source_answered' => $student->sourceAnswered,
            // A source fact, and only that: the file says this student answered
            // nothing. What that MEANS pedagogically is the teacher's to decide
            // (§18) — the word «faltou» never appears here.
            'participated' => ! $student->answeredNothing(),
            'enrollment_id' => null,
            'status' => self::STATUS_UNMATCHED,
            'reason' => null,
            // Every row can be resolved by hand, whatever the automatic answer.
            'candidates' => $everyone,
            'suggestions' => [],
        ];

        // A decision already taken always wins, including the decision to leave
        // a row out — which is why null is checked with array_key_exists.
        $ladder = $this->ladder($student, $byNumber, $byName, $byNormalised, $byFirstAndLast);

        if (array_key_exists($student->sourceKey, $alreadyDecided)) {
            $chosen = $alreadyDecided[$student->sourceKey];

            // Saving the step keeps what was pre-filled, and what was pre-filled
            // was the automatic answer. The review still says WHY the pair was
            // made — «primeiro nome e último apelido» is worth a second look,
            // «confirmado por si» is a choice the teacher actually made — so an
            // automatic pair keeps its reason unless the teacher picked someone
            // else.
            return [
                ...$base,
                'enrollment_id' => $chosen,
                'status' => $chosen === null ? self::STATUS_IGNORED : self::STATUS_MATCHED,
                'reason' => $chosen === null
                    ? 'ignored_by_teacher'
                    : ($this->automaticReasonFor($chosen, $ladder) ?? 'confirmed_by_teacher'),
            ];
        }

        foreach ($ladder as $reason => $candidates) {
            if ($candidates === []) {
                continue;
            }

            // Two students answering to the same rung is not a match. It is a
            // question, and it goes to the teacher rather than to a coin toss.
            if (count($candidates) > 1) {
                return [
                    ...$base,
                    'status' => self::STATUS_AMBIGUOUS,
                    'reason' => $reason,
                    // The plausible ones, named — but the full class stays
                    // selectable, because the right answer may be neither.
                    'suggestions' => array_map(fn (array $row): array => ['id' => $row['id'], 'label' => $row['label']], $candidates),
                ];
            }

            $candidate = $candidates[0];

            // Already claimed by an earlier row: two file rows pointing at one
            // student is exactly the kind of collision that must be seen.
            if (in_array($candidate['id'], $taken, true)) {
                return [
                    ...$base,
                    'status' => self::STATUS_AMBIGUOUS,
                    'reason' => 'already_taken',
                    'suggestions' => [['id' => $candidate['id'], 'label' => $candidate['label']]],
                ];
            }

            return [
                ...$base,
                'enrollment_id' => $candidate['id'],
                'status' => self::STATUS_MATCHED,
                'reason' => $reason,
            ];
        }

        return $base;
    }

    /**
     * The rungs, most certain first. Plickers has no class number, so in
     * practice it starts at the name — but the rung exists because Intuitivo and
     * a teacher's own spreadsheet routinely do carry one.
     *
     * The last rung is the first name together with the last surname. A
     * platform rarely holds the whole name the school holds — Intuitivo writes
     * «João Manuel Cunha» where Lapispro has «João Pedro Cunha» — and asking the
     * teacher to pair twenty rows by hand for the sake of middle names is the
     * afternoon of clicking this class exists to spare. The rung is still exact:
     * it matches one student or nobody, a second «João Cunha» in the class turns
     * it into a question, and a name with a single word never reaches it.
     *
     * @param  array<int|string, list<array<string, mixed>>>  $byNumber
     * @param  array<string, list<array<string, mixed>>>  $byName
     * @param  array<string, list<array<string, mixed>>>  $byNormalised
     * @param  array<string, list<array<string, mixed>>>  $byFirstAndLast
     * @return array<string, list<array<string, mixed>>>
     */
    protected function ladder(CanonicalStudent $student, array $byNumber, array $byName, array $byNormalised, array $byFirstAndLast = []): array
    {
        $name = $student->displayName;
        $firstAndLast = $name === null ? null : $this->firstAndLast($name);

        return [
            'class_number' => $student->classNumber === null ? [] : ($byNumber[$student->classNumber] ?? []),
            'exact_name' => $name === null ? [] : ($byName[$name] ?? []),
            'normalised_name' => $name === null ? [] : ($byNormalised[$this->normalise($name)] ?? []),
            'first_and_last_name' => $firstAndLast === null ? [] : ($byFirstAndLast[$firstAndLast] ?? []),
        ];
    }

    /**
     * The rung that would have paired this row with the student the teacher
     * kept — or null when the automatic answer was somebody else, nobody, or a
     * question.
     *
     * @param  array<string, list<array<string, mixed>>>  $ladder
     */
    protected function automaticReasonFor(int $chosen, array $ladder): ?string
    {
        foreach ($ladder as $reason => $candidates) {
            if ($candidates === []) {
                continue;
            }

            return count($candidates) === 1 && $candidates[0]['id'] === $chosen ? $reason : null;
        }

        return null;
    }

    /**
     * «joao cunha» for «João Manuel de Sousa Cunha»: the first word and the last
     * word of the normalised name, with the particles that join Portuguese
     * surnames («de», «da», «dos», «e»…) never counting as a word. Null for a
     * name with fewer than two words, which has no surname to go by.
     */
    protected function firstAndLast(string $name): ?string
    {
        $words = array_values(array_filter(
            explode(' ', $this->normalise($name)),
            fn (string $word): bool => $word !== '' && ! in_array($word, self::PARTICLES, true),
        ));

        if (count($words) < 2) {
            return null;
        }

        return $words[0].' '.$words[count($words) - 1];
    }

    /**
     * Loads only this class's enrolments — the students the teacher is already
     * authorised to see — and decrypts their names in memory. This is the whole
     * reason matching is a service and not a query: the comparison cannot happen
     * in the database (§17).
     *
     * @return list<array<string, mixed>>
     */
    protected function enrollments(SchoolClass $class, ?string $referenceDate = null): array
    {
        // ClassCohort::for() — o resolver único de «que alunos entram na
        // análise desta disciplina» (ver o seu docblock). AttendingOnly: uma
        // grelha de correção desta disciplina não pode ser atribuída a um
        // aluno que não a frequenta — MAS «não frequenta» tem de ser lido À
        // DATA A QUE A GRELHA SE REFERE (M6), nunca implicitamente hoje. Uma
        // grelha aplicada em março e importada em junho continua a poder ser
        // atribuída a um aluno que deixou de frequentar entretanto — em março
        // ele frequentava, e é essa data que decide, não a de hoje. Sem uma
        // data conhecida (nenhum instrumento escolhido ainda), usa-se `all()`
        // — a turma toda — em vez do universo de hoje, para nunca perder uma
        // linha só por uma mudança de frequência posterior à importação.
        $cohort = ClassCohort::for($class, $referenceDate);

        return ($referenceDate === null ? $cohort->all() : $cohort->attending())
            ->map(function (Enrollment $enrollment): array {
                $name = $enrollment->student->identity?->display_name;

                return [
                    'id' => (int) $enrollment->getKey(),
                    'class_number' => $enrollment->class_number,
                    'name' => $name,
                    // What the teacher sees in a dropdown. Their own class, on
                    // their own screen — the same names the roster already shows.
                    'label' => trim(($enrollment->class_number === null ? '' : $enrollment->class_number.'. ').($name ?? __('(sem identidade)'))),
                ];
            })
            // array_values rather than Collection::values(): the generic
            // Collection cannot prove it produces a list, and this is a hot
            // enough path in matching that the guarantee is worth stating.
            ->pipe(fn ($enrollments): array => array_values($enrollments->all()));
    }

    /**
     * Case, accents and runs of whitespace removed. Enough to survive «ANA
     * SILVA» against «Ana Silva», and deliberately not enough to survive a
     * different name that merely looks similar.
     */
    protected function normalise(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        if ($transliterated !== false) {
            $name = $transliterated;
        }

        return (string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', '', $name));
    }
}
