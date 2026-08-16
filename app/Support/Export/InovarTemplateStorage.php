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

    public function store(string $token, string $contents): string
    {
        Storage::disk('local')->put($this->relativePath($token), $contents);

        return $this->relativePath($token);
    }

    public function exists(string $token): bool
    {
        return Storage::disk('local')->exists($this->relativePath($token));
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

    /**
     * A token is a UUID we generated. Anything else never reaches the disk:
     * a path is built from this and traversal is not a risk worth taking.
     */
    protected function safe(string $token): string
    {
        return preg_replace('/[^a-zA-Z0-9\-]/', '', $token) ?? '';
    }
}
