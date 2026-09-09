<?php

// app/Services/Import/MatchRosterToEnrollments.php

namespace App\Services\Import;

use App\Domain\Import\RosterMatch;
use App\Domain\Import\RosterRow;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use Illuminate\Support\Str;

/**
 * Decides which student on a class's roll — if any — a roster row is about.
 *
 * This exists because a re-import is not a first import. The first time, every
 * row is a new student and there is nothing to recognise. The second time,
 * almost every row is somebody who is already here, carrying a history that
 * must survive the correction: results, records, interventions, evidence. Get
 * this wrong in one direction and the class ends up with each student twice;
 * get it wrong in the other and one student's photo lands on another's record.
 *
 * IDENTIFIERS FIRST, NAMES ONLY WHEN THEY ARE NOT A GUESS.
 *
 * 1. «N.º PROC.» — the school's own identifier for that student, the only
 *    stable thing in the file. A name gets corrected; a process number does
 *    not.
 * 2. The normalized name, and only when exactly one enrolment answers to it.
 *
 * Two enrolments answering the same evidence is not a tie to be broken here.
 * It is returned as ambiguous, with both candidates named, and the preview
 * asks the teacher — who knows which of the two Marias is which, and this
 * class never will (§3).
 *
 * The whole roll is read once, into two in-memory indexes. It has to be:
 * school_number and display_name are encrypted at rest (ADR-0004), so neither
 * can be compared in SQL. A class is thirty students, which is what makes
 * that affordable — and the enrolments are read through the class, so the
 * organization scope is already applied (ADR-0002).
 */
class MatchRosterToEnrollments
{
    /**
     * @return \Closure(RosterRow): RosterMatch
     */
    public function forClass(SchoolClass $class): \Closure
    {
        /** @var array<string, list<array{enrollment_id: int, name: string, class_number: ?int, has_photo: bool}>> $byProcessNumber */
        $byProcessNumber = [];

        /** @var array<string, list<array{enrollment_id: int, name: string, class_number: ?int, has_photo: bool}>> $byName */
        $byName = [];

        // EVERY enrolment, not just the active ones. A student who left and
        // reappears on the school's own corrected file is the same student —
        // matching only the active roll would enrol them a second time and
        // strand their record on the old row (§7).
        $enrollments = $class->enrollments()->with('student.identity')->get();

        foreach ($enrollments as $enrollment) {
            /** @var Enrollment $enrollment */
            $identity = $enrollment->student?->identity;

            $entry = [
                'enrollment_id' => (int) $enrollment->getKey(),
                'name' => $identity === null ? '' : $identity->display_name,
                'class_number' => $enrollment->class_number,
                'has_photo' => $identity?->photo_path !== null,
            ];

            $processNumber = $this->normalizeProcessNumber($identity?->school_number);

            if ($processNumber !== null) {
                $byProcessNumber[$processNumber][] = $entry;
            }

            $name = $this->normalizeName($entry['name']);

            // A student imported without an identity has an empty name, and
            // several of them would all "answer" to the same empty key. An
            // empty name identifies nobody, so it is not an index entry.
            if ($name !== '') {
                $byName[$name][] = $entry;
            }
        }

        return function (RosterRow $row) use ($byProcessNumber, $byName): RosterMatch {
            $processNumber = $this->normalizeProcessNumber($row->processNumber);

            if ($processNumber !== null && isset($byProcessNumber[$processNumber])) {
                return $this->resolve($byProcessNumber[$processNumber], RosterMatch::BY_PROCESS_NUMBER);
            }

            $name = $this->normalizeName($row->name);

            if ($name !== '' && isset($byName[$name])) {
                return $this->resolve($byName[$name], RosterMatch::BY_NAME);
            }

            return RosterMatch::none();
        };
    }

    /**
     * @param  list<array{enrollment_id: int, name: string, class_number: ?int, has_photo: bool}>  $entries
     */
    protected function resolve(array $entries, string $matchedBy): RosterMatch
    {
        if (count($entries) === 1) {
            $entry = $entries[0];

            return new RosterMatch(
                enrollmentId: $entry['enrollment_id'],
                matchedBy: $matchedBy,
                currentName: $entry['name'],
                hasPhoto: $entry['has_photo'],
            );
        }

        // NO ENROLMENT IS CHOSEN HERE. Naming one of them would be the
        // application deciding which student this is, which is the one thing
        // it must not do; the candidates travel to the preview instead, and
        // the teacher points at the right one (§3).
        return new RosterMatch(
            matchedBy: $matchedBy,
            ambiguous: true,
            candidates: array_map(
                fn (array $entry): array => [
                    'enrollment_id' => $entry['enrollment_id'],
                    'name' => $entry['name'],
                    'class_number' => $entry['class_number'],
                ],
                $entries,
            ),
        );
    }

    /**
     * Trimmed and upper-cased, and nothing else.
     *
     * A leading zero is part of somebody's identifier and not formatting to
     * tidy away — the same rule StudentEnrollmentService::setProcessNumber()
     * states when it stores the value. Both sides of the comparison come from
     * the school's own export anyway, so there is nothing to reconcile beyond
     * stray spaces and a letter's case.
     */
    protected function normalizeProcessNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::upper(trim($value));

        return $value === '' ? null : $value;
    }

    protected function normalizeName(string $value): string
    {
        return Str::of($value)->squish()->lower()->value();
    }
}
