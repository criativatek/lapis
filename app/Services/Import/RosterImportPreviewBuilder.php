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
     * @param  \Closure(string): bool  $isAlreadyEnrolled
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
            $alreadyEnrolled = $isAlreadyEnrolled($row->name);
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
