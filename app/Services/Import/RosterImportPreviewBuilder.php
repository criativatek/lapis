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

    /**
     * @param  list<RosterRow>  $rosterRows
     * @param  list<PhotoMatch>  $photoMatches
     * @param  \Closure(string): bool  $isAlreadyEnrolled  Receives the name already normalized (squished, lowercased) — not the raw roster spelling. A real (database-backed) implementation must compare against an equally normalized column/value.
     * @return list<array{name: string, class_number: ?int, birth_date: ?string, situation_code: string, situation_recognized: bool, process_number: ?string, note: ?string, photo_index: ?int, photo_extension: ?string, duplicate_in_file: bool, already_enrolled: bool, include: bool}>
     */
    public function build(array $rosterRows, array $photoMatches, \Closure $isAlreadyEnrolled): array
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
            $alreadyEnrolled = $isAlreadyEnrolled($key);
            $photoIndex = $this->findPhotoIndex($row->name, $photoMatches);

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
                'already_enrolled' => $alreadyEnrolled,
                'include' => ! $duplicateInFile && ! $alreadyEnrolled,
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
     * @param  list<PhotoMatch>  $photoMatches
     */
    protected function findPhotoIndex(string $name, array $photoMatches): ?int
    {
        $target = $this->normalize($name);

        foreach ($photoMatches as $index => $photo) {
            if ($this->normalize($photo->name) === $target) {
                return $index;
            }
        }

        return null;
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)->squish()->lower()->value();
    }
}
