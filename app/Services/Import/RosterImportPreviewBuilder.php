<?php

namespace App\Services\Import;

use App\Domain\Import\PhotoMatch;
use App\Domain\Import\RosterRow;
use Illuminate\Support\Str;

/**
 * Merges parsed roster rows with parsed photo matches into plain arrays ready
 * to hand to the Inertia preview page. Never touches Eloquent or the
 * database directly — $isAlreadyEnrolled is injected so this stays a fast,
 * pure unit.
 */
class RosterImportPreviewBuilder
{
    protected const RECOGNIZED_SITUATIONS = ['X', 'TR'];

    /** A student the class does not have yet. */
    public const ACTION_ENROL = 'enrol';

    /** Already on the roll: the roster fills in what the record is missing, and erases nothing. */
    public const ACTION_UPDATE = 'update';

    /** The same name twice in one file — not something to guess about. */
    public const ACTION_SKIP = 'skip';

    /**
     * @param  list<RosterRow>  $rosterRows
     * @param  list<PhotoMatch>  $photoMatches
     * @param  \Closure(string): ?int  $enrolledAs  Receives the name already normalized (squished, lowercased) — not the raw roster spelling — and answers with the id of the enrollment that student already has in this class, or null. A real (database-backed) implementation must compare against an equally normalized column/value.
     * @return list<array{name: string, class_number: ?int, birth_date: ?string, situation_code: string, situation_recognized: bool, process_number: ?string, note: ?string, photo_index: ?int, photo_extension: ?string, duplicate_in_file: bool, already_enrolled: bool, enrollment_id: ?int, action: string, include: bool}>
     */
    public function build(array $rosterRows, array $photoMatches, \Closure $enrolledAs): array
    {
        $nameCounts = [];

        foreach ($rosterRows as $row) {
            $key = $this->normalize($row->name);
            $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
        }

        $preview = [];

        foreach ($rosterRows as $row) {
            $key = $this->normalize($row->name);

            $duplicateInFile = $nameCounts[$key] > 1;
            $enrollmentId = $enrolledAs($key);
            $photoIndex = $this->findPhotoIndex($row->name, $photoMatches);

            // A name appearing twice in the same file is not something to guess
            // about; everyone else is either new here, or already on the roll and
            // therefore an UPDATE rather than a second enrolment (§8).
            $action = match (true) {
                $duplicateInFile => self::ACTION_SKIP,
                $enrollmentId !== null => self::ACTION_UPDATE,
                default => self::ACTION_ENROL,
            };

            $preview[] = [
                'name' => $row->name,
                'class_number' => $row->classNumber,
                'birth_date' => $row->birthDate,
                'situation_code' => $row->situationCode,
                'situation_recognized' => in_array($row->situationCode, self::RECOGNIZED_SITUATIONS, true),
                'process_number' => $row->processNumber,
                'note' => $row->note,
                'photo_index' => $photoIndex,
                'photo_extension' => $photoIndex !== null ? $photoMatches[$photoIndex]->extension : null,
                'duplicate_in_file' => $duplicateInFile,
                'already_enrolled' => $enrollmentId !== null,
                'enrollment_id' => $enrollmentId,
                'action' => $action,
                'include' => $action !== self::ACTION_SKIP,
            ];
        }

        return $preview;
    }

    /**
     * Matches freshly-parsed photos against ALREADY-BUILT preview rows —
     * used by the "attach photos" step, which runs after the roster has
     * already been previewed (and possibly edited by the teacher). Unlike
     * build(), $rows here are plain arrays (the client's current row data,
     * name edits included), not RosterRow objects, and there is no
     * duplicate/already-enrolled recalculation: those flags were already
     * decided by the original build() call and are passed through unchanged.
     *
     * A row whose name matches no parsed photo is left exactly as it came
     * in — so a row that already had no photo simply keeps photo_index:
     * null, and this never clobbers a manual assignment from an earlier
     * attach-photos call that this call's photo file happens not to repeat.
     *
     * @param  list<array<string, mixed>>  $rows  each must at least have a 'name' key (string)
     * @param  list<PhotoMatch>  $photoMatches
     * @return list<array<string, mixed>>
     */
    public function matchPhotosToRows(array $rows, array $photoMatches): array
    {
        foreach ($rows as &$row) {
            $photoIndex = $this->findPhotoIndex($row['name'], $photoMatches);

            if ($photoIndex !== null) {
                $row['photo_index'] = $photoIndex;
                $row['photo_extension'] = $photoMatches[$photoIndex]->extension;
            }
        }

        return $rows;
    }

    /**
     * Real Intuitivo exports were verified to name-match this way, not by
     * exact string equality: the Word photo sheet's captions carry only
     * first+last name ("Afonso Mordomo"), while the Excel roster carries the
     * full name including middle names ("Afonso Pito Mordomo"). A plain
     * normalized-string comparison never matches a single real photo against
     * a real roster — this checks each name's words against the other's, in
     * order, so either one may be the abbreviated side.
     *
     * @param  list<PhotoMatch>  $photoMatches
     */
    protected function findPhotoIndex(string $name, array $photoMatches): ?int
    {
        $targetWords = $this->words($name);

        foreach ($photoMatches as $index => $photo) {
            $photoWords = $this->words($photo->name);

            if ($this->isWordSubsequence($photoWords, $targetWords) || $this->isWordSubsequence($targetWords, $photoWords)) {
                return $index;
            }
        }

        return null;
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)->squish()->lower()->value();
    }

    /**
     * @return list<string>
     */
    protected function words(string $value): array
    {
        return array_values(Str::of($value)->squish()->lower()->explode(' ')->all());
    }

    /**
     * True when every word in $needle appears in $haystack, in the same
     * relative order — e.g. ["afonso", "mordomo"] is a subsequence of
     * ["afonso", "pito", "mordomo"], but never of ["pito", "afonso",
     * "mordomo"] or of a haystack missing either word. An empty $needle
     * never matches — it would otherwise be trivially "found" in anything.
     *
     * @param  list<string>  $needle
     * @param  list<string>  $haystack
     */
    protected function isWordSubsequence(array $needle, array $haystack): bool
    {
        if ($needle === []) {
            return false;
        }

        $position = 0;

        foreach ($needle as $word) {
            while (true) {
                if (! array_key_exists($position, $haystack)) {
                    return false;
                }

                if ($haystack[$position] === $word) {
                    $position++;

                    continue 2;
                }

                $position++;
            }
        }

        return true;
    }
}
