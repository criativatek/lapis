<?php

namespace App\Support\Hashing;

/**
 * Tamper-evidence hashing over a canonical form of a payload.
 *
 * Canonicalising — recursive key sort, list order preserved, fixed encoding
 * flags — is what lets a hash still verify after a round-trip through a MySQL
 * JSON column, which reorders object keys and strips whitespace. A naive
 * json_encode() never re-hashes to the stored value once the row is reloaded,
 * so every reread snapshot reports itself as tampered with.
 *
 * SQLite keeps the text exactly as written, so nothing here shows up in a
 * suite running on it. `CalculationSnapshot` learned this first and kept the
 * fix to itself; `InterimAssessment` and `Report` were left hashing naively and
 * were reporting `is_intact = false` for untouched records on the engine
 * production actually uses.
 */
final class CanonicalPayload
{
    /** @param  array<array-key, mixed>  $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', self::encode($payload));
    }

    /** @param  array<array-key, mixed>  $payload */
    public static function encode(array $payload): string
    {
        $sort = function (&$value) use (&$sort): void {
            if (! is_array($value)) {
                return;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as &$item) {
                $sort($item);
            }
        };

        $sort($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
