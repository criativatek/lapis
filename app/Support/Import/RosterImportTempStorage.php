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

    /**
     * Um token nasce SEMPRE ligado à turma para que foi emitido.
     *
     * A turma é argumento e não um `markClass()` à parte de propósito: quem
     * lê a pasta mais tarde tem de poder provar de quem ela é, e uma marca que
     * depende de o chamador se lembrar acaba esquecida — foi assim que dois
     * testes criaram tokens órfãos sem ninguém dar por isso.
     */
    public function newToken(int $classId): string
    {
        $token = (string) Str::uuid();

        $this->markClass($token, $classId);

        return $token;
    }

    public function storePhoto(string $token, int $index, string $bytes, string $extension): string
    {
        $relativePath = self::ROOT."/{$token}/{$index}.{$extension}";
        Storage::disk('local')->put($relativePath, $bytes);

        return $relativePath;
    }

    /**
     * Stamps $token with the class it was created for, so a later reader
     * (previewPhoto) can prove the token was actually issued for THIS class
     * before serving anything from it — a token alone identifies a folder,
     * never who it belongs to.
     */
    public function markClass(string $token, int $classId): void
    {
        Storage::disk('local')->put(self::ROOT."/{$token}/class_id", (string) $classId);
    }

    public function belongsToClass(string $token, int $classId): bool
    {
        $stored = Storage::disk('local')->get(self::ROOT."/{$token}/class_id");

        return $stored !== null && (int) $stored === $classId;
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
