<?php

namespace App\Support\Export;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Short-lived scratch space for the grid a teacher uploads, on the private
 * `local` disk — never `public`, never web-reachable.
 *
 * The grid carries names, process numbers and marks, so it is held for exactly
 * as long as the two steps between uploading it and downloading the filled copy,
 * and deleted on the way out of either. The token is a random folder name and
 * the file inside is always called the same thing: nothing about the path
 * reveals whose class it is, and nothing a client sends can steer it.
 */
class InovarTemplateStorage
{
    protected const ROOT = 'inovar-exports';

    /** One name for every upload, so a token can never point at anything else. */
    protected const FILENAME = 'template.xls';

    public function newToken(): string
    {
        return (string) Str::uuid();
    }

    /**
     * $classId is stamped beside the upload the moment it lands, so any
     * later reader can prove the token was issued FOR this class — a token
     * on its own identifies a file, never who it belongs to, and a token
     * seen elsewhere (a shared screen, a support report, browser history)
     * must not double as a key to somebody else's grid.
     */
    public function store(string $token, int $classId, string $contents): string
    {
        Storage::disk('local')->put($this->relativePath($token), $contents);
        Storage::disk('local')->put($this->classMarkerPath($token), (string) $classId);

        return $this->relativePath($token);
    }

    public function exists(string $token): bool
    {
        return Storage::disk('local')->exists($this->relativePath($token));
    }

    /** Whether $token was issued for $classId — checked before ever reading it back. */
    public function belongsToClass(string $token, int $classId): bool
    {
        $stored = Storage::disk('local')->get($this->classMarkerPath($token));

        return $stored !== null && (int) $stored === $classId;
    }

    /** The absolute path, for a reader that needs a real file on disk. */
    public function absolutePath(string $token): string
    {
        return Storage::disk('local')->path($this->relativePath($token));
    }

    public function delete(string $token): void
    {
        Storage::disk('local')->deleteDirectory(self::ROOT.'/'.$this->safe($token));
    }

    protected function relativePath(string $token): string
    {
        return self::ROOT.'/'.$this->safe($token).'/'.self::FILENAME;
    }

    protected function classMarkerPath(string $token): string
    {
        return self::ROOT.'/'.$this->safe($token).'/class_id';
    }

    /**
     * A token is a UUID we generated. Anything else never reaches the disk:
     * a path is built from this and traversal is not a risk worth taking.
     */
    protected function safe(string $token): string
    {
        return preg_replace('/[^a-zA-Z0-9\-]/', '', $token) ?? '';
    }
}
