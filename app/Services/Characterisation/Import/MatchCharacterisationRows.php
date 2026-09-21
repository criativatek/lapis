<?php

namespace App\Services\Characterisation\Import;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Import\Concerns\NormalisesStudentIdentifiers;

/**
 * Decides which student on a class's roll a characterisation row is about.
 *
 * The order is the roster importer's, for the same reasons, and this class
 * shares its normalisation so the two can never disagree about who a name
 * belongs to:
 *
 * 1. The school's own process number — the only stable identifier in the file.
 * 2. The normalised name, and only when exactly one enrolment answers.
 * 3. A word-subsequence comparison, and only when exactly one enrolment
 *    answers. «Afonso Mordomo» finds «Afonso Pito Mordomo», which is the shape
 *    real files differ in. This never resolves on its own: it produces
 *    Possible, which the preview shows unticked.
 *
 * WHERE IT DIFFERS FROM THE ROSTER IMPORTER, AND WHY. That one may enrol a
 * student it does not recognise; this one may not. A characterisation is a
 * sentence about somebody who is already in the class, so a row matching nobody
 * is NotFound and stays NotFound — there is no branch here that creates a
 * student, because deciding that a child exists is not a decision that belongs
 * in a paste box.
 *
 * The whole roll is read ONCE into in-memory indexes. It has to be:
 * school_number and display_name are encrypted at rest (ADR-0004), so neither
 * can be compared in SQL. That single read is also what keeps a thirty-row
 * import from becoming thirty queries.
 *
 * CLASS NUMBER IS DELIBERATELY NOT A MATCHING KEY, EVEN THOUGH
 * ColumnRole::ClassNumber->isIdentifying() IS TRUE (so FindHeaderRow will
 * happily accept a "N.º" column as evidence of a header row). A class
 * number is unique WITHIN this class's own roll, which is exactly what makes
 * it dangerous to match ON: it is trivially collidible with any OTHER number
 * that happens to sit in the same column of an imported sheet — a repeated
 * retention year ("2R"), a school year, a process number typed in the wrong
 * column. Matching a row to "student #7" because a stray "7" appears
 * somewhere in it is the kind of silent wrong-child assignment this whole
 * importer exists to prevent (nada é gravado sem decisão humana; a
 * aplicação nunca adivinha de que criança é uma linha). Building that
 * safely — restricted to a column the teacher has explicitly confirmed IS
 * the class-number column, never a bare "any numeric cell" heuristic — is a
 * real feature this hotfix does not attempt: the real table this hotfix was
 * written against has no class-number column at all, so there is nothing to
 * reproduce a failure against, and adding unexercised matching logic to an
 * already-delicate matcher is a cost with no fixture to prove it right.
 * process_number and name (exact, then subsequence) remain the only
 * matching keys.
 */
class MatchCharacterisationRows
{
    use NormalisesStudentIdentifiers;

    /**
     * @return \Closure(string $name, ?string $processNumber): RowMatch
     */
    public function forClass(SchoolClass $class): \Closure
    {
        /** @var array<string, list<array{ulid: string, name: string, class_number: int|null}>> $byProcessNumber */
        $byProcessNumber = [];

        /** @var array<string, list<array{ulid: string, name: string, class_number: int|null}>> $byName */
        $byName = [];

        /** @var list<array{entry: array{ulid: string, name: string, class_number: int|null}, words: list<string>}> $roll */
        $roll = [];

        // Every enrolment, not only the active ones: a student who left mid-year
        // still has a characterisation written about the time they were here,
        // and refusing to match them would silently drop their row.
        $enrollments = $class->enrollments()->with('student.identity')->get();

        foreach ($enrollments as $enrollment) {
            /** @var Enrollment $enrollment */
            $identity = $enrollment->student?->identity;

            $entry = [
                'ulid' => (string) $enrollment->ulid,
                'name' => $identity === null ? '' : (string) $identity->display_name,
                'class_number' => $enrollment->class_number,
            ];

            $processNumber = $this->normalizeProcessNumber($identity?->school_number);

            if ($processNumber !== null) {
                $byProcessNumber[$processNumber][] = $entry;
            }

            $name = $this->normalizeName($entry['name']);

            // An enrolment with no identity has an empty name, and several of
            // them would all answer to the same empty key. An empty name
            // identifies nobody, so it is not an index entry.
            if ($name === '') {
                continue;
            }

            $byName[$name][] = $entry;
            $roll[] = ['entry' => $entry, 'words' => $this->words($name)];
        }

        return function (string $rowName, ?string $rowProcessNumber) use ($byProcessNumber, $byName, $roll): RowMatch {
            $processNumber = $this->normalizeProcessNumber($rowProcessNumber);

            if ($processNumber !== null && isset($byProcessNumber[$processNumber])) {
                return $this->resolve($byProcessNumber[$processNumber], 'process_number');
            }

            $name = $this->normalizeName($rowName);

            if ($name === '') {
                return RowMatch::notFound();
            }

            if (isset($byName[$name])) {
                return $this->resolve($byName[$name], 'name');
            }

            return $this->bySubsequence($this->words($name), $roll);
        };
    }

    /**
     * @param  list<array{ulid: string, name: string, class_number: int|null}>  $entries
     */
    private function resolve(array $entries, string $matchedBy): RowMatch
    {
        if (count($entries) === 1) {
            return RowMatch::confident($entries[0]['ulid'], $entries[0]['name'], $matchedBy);
        }

        // NO ENROLMENT IS CHOSEN HERE. Naming one of them would be the
        // application deciding which student this is, which is the one thing it
        // must not do. The candidates travel to the preview and the teacher
        // points at the right one.
        return RowMatch::ambiguous($entries, $matchedBy);
    }

    /**
     * A name matches by subsequence when every word of the shorter name appears,
     * in order, in the longer one. Both directions are tried, because the file
     * may carry either the fuller or the shorter version of the same name.
     *
     * @param  list<string>  $rowWords
     * @param  list<array{entry: array{ulid: string, name: string, class_number: int|null}, words: list<string>}>  $roll
     */
    private function bySubsequence(array $rowWords, array $roll): RowMatch
    {
        if ($rowWords === []) {
            return RowMatch::notFound();
        }

        $hits = [];

        foreach ($roll as $candidate) {
            if ($this->isSubsequence($rowWords, $candidate['words']) || $this->isSubsequence($candidate['words'], $rowWords)) {
                $hits[] = $candidate['entry'];
            }
        }

        if ($hits === []) {
            return RowMatch::notFound();
        }

        if (count($hits) > 1) {
            return RowMatch::ambiguous($hits, 'name_subsequence');
        }

        // A single loose hit is a suggestion, not a conclusion. It reaches the
        // preview unticked and goes nowhere until somebody agrees with it.
        return RowMatch::possible($hits[0]['ulid'], $hits[0]['name'], $hits);
    }

    /**
     * A single-word name is not enough to suggest anything: «Maria» appearing
     * inside «Maria Silva» and «Ana Maria Costa» is a coincidence of Portuguese
     * naming, not evidence.
     *
     * @param  list<string>  $needle
     * @param  list<string>  $haystack
     */
    private function isSubsequence(array $needle, array $haystack): bool
    {
        if (count($needle) < 2 || count($needle) > count($haystack)) {
            return false;
        }

        $position = 0;

        foreach ($haystack as $word) {
            if ($position < count($needle) && $word === $needle[$position]) {
                $position++;
            }
        }

        return $position === count($needle);
    }

    /**
     * @return list<string>
     */
    private function words(string $normalisedName): array
    {
        return array_values(array_filter(explode(' ', $normalisedName), fn (string $word) => $word !== ''));
    }
}
