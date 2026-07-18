<?php

namespace App\Support\Privacy;

use Illuminate\Support\Str;

/**
 * Deterministic HMAC of a value, for exact-match search over an encrypted column
 * without decrypting it (§22.3, ADR-0004).
 *
 * The key is derived from APP_KEY, so rotating APP_KEY invalidates existing
 * indexes — the rotation procedure (re-hash on rotate) is a documented follow-up
 * in ADR-0004. The value is normalized (trimmed, lowercased, collapsed spaces)
 * so "Ana  Silva" and "ana silva" match; this deliberately supports only exact
 * match, never partial or sorted search, which would leak information.
 */
class BlindIndex
{
    public static function of(string $value): string
    {
        $normalized = Str::of($value)->squish()->lower()->value();

        return hash_hmac('sha256', $normalized, self::key());
    }

    protected static function key(): string
    {
        // A dedicated derived key, not APP_KEY itself, so the index secret and the
        // encryption secret are not literally the same bytes.
        return hash_hmac('sha256', 'student-name-blind-index', (string) config('app.key'));
    }
}
