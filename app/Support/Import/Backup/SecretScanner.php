<?php

namespace App\Support\Import\Backup;

/**
 * Recursively walks a decoded backup payload looking for key names that
 * should never appear in a LÁPIS export (§7 of the import brief). Run
 * against the RAW decoded upload, before anything is whitelisted into
 * `canonical_snapshot` — a genuine LÁPIS backup never carries any of these
 * keys at any depth, so finding one is treated as a strong signal that the
 * file is not what it claims to be, not merely a field to drop silently.
 *
 * Substring matching, not exact-key matching, and deliberately broad: the
 * failure mode of a false positive is "backup inválido, tente de novo,"
 * which is safe. The failure mode of a false negative is a leaked secret,
 * which is not.
 */
final class SecretScanner
{
    /**
     * @var list<string>
     */
    private const array FORBIDDEN_KEY_SUBSTRINGS = [
        'password',
        'remember_token',
        'two_factor',
        'passkey',
        'session',
        'api_key',
        'api_token',
        'smtp',
        'invitation_token',
        'token_hash',
        'token',
        'csrf',
        'encryption_key',
        'app_key',
        'secret',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string> forbidden key paths found, empty when none
     */
    public function scan(array $payload, string $path = ''): array
    {
        $found = [];

        foreach ($payload as $key => $value) {
            $currentPath = $path === '' ? (string) $key : "{$path}.{$key}";

            if (is_string($key) && $this->isForbidden($key)) {
                $found[] = $currentPath;
            }

            if (is_array($value)) {
                array_push($found, ...$this->scan($value, $currentPath));
            }
        }

        return $found;
    }

    private function isForbidden(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::FORBIDDEN_KEY_SUBSTRINGS as $forbidden) {
            if (str_contains($normalized, $forbidden)) {
                return true;
            }
        }

        return false;
    }
}
