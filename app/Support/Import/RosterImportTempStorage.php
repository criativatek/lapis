<?php

namespace App\Support\Import;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Short-lived, per-import scratch space for uploaded photos, on the private
 * `local` disk (never `public` — never web-reachable directly). No database
 * row backs this: the token is just a folder name. Deleted in full once the
 * import is confirmed or abandoned (docs/superpowers/specs/2026-07-28-*:
 * "o ficheiro carregado não é retido indefinidamente").
 */
class RosterImportTempStorage
{
    protected const ROOT = 'roster-imports';

    public function newToken(): string
    {
        return (string) Str::uuid();
    }

    public function storePhoto(string $token, int $index, string $bytes, string $extension): string
    {
        $relativePath = self::ROOT."/{$token}/{$index}.{$extension}";
        Storage::disk('local')->put($relativePath, $bytes);

        return $relativePath;
    }

    public function readPhoto(string $relativePath): ?string
    {
        $contents = Storage::disk('local')->get($relativePath);

        return $contents === null ? null : $contents;
    }

    public function path(string $token): string
    {
        return self::ROOT."/{$token}";
    }

    public function delete(string $token): void
    {
        Storage::disk('local')->deleteDirectory($this->path($token));
    }
}
