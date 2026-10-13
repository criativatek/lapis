<?php

namespace App\Support\Language;

/**
 * First and last name from the single full name a student identity holds
 * (`display_name` — there are no separate name fields). For a message the
 * teacher reviews before copying; never for matching (PersonNameKey does that).
 *
 * «Ana Sofia Martins Costa» → «Ana Costa»; a generational suffix travels with
 * the surname it qualifies: «Pedro Costa Júnior» → «Pedro Costa Júnior».
 */
final class StudentNameParts
{
    private const array SUFFIXES = ['júnior', 'junior', 'filho', 'filha', 'neto', 'neta', 'sobrinho', 'sobrinha'];

    public static function firstAndLast(?string $fullName): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return null;
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $first = $parts[0];
        $last = $parts[count($parts) - 1];

        if (count($parts) > 2 && in_array(mb_strtolower($last), self::SUFFIXES, true)) {
            $last = $parts[count($parts) - 2].' '.$last;
        }

        return $first.' '.$last;
    }
}
