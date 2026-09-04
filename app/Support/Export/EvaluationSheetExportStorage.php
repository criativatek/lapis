<?php

namespace App\Support\Export;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where a generated INOVAR grid LIVES, as opposed to where an uploaded one
 * waits.
 *
 * The two are deliberately different places. `InovarTemplateStorage` holds the
 * teacher's upload under `inovar-exports/` for the length of one flow and a
 * scheduled command sweeps it; this holds the file that a history record points
 * at, under a root nothing prunes, because deleting it would leave a record
 * claiming a file that is gone (§13). A pauta guardada without a file is a
 * first-class record; a pauta that says «Exportado» and cannot produce what was
 * exported is a broken one.
 *
 * PRIVATE, AND NOT GUESSABLE. The `local` disk is `storage/app/private` — never
 * `public`, never behind a web-reachable path. The folder is a random ULID that
 * is NOT the record's own: the record's ulid travels in URLs, and a path that
 * could be derived from it would be an invitation to try the filesystem instead
 * of the authorised route. The only way to the bytes is the download route,
 * which authorises the class and checks the record belongs to it.
 */
class EvaluationSheetExportStorage
{
    public const DISK = 'local';

    protected const ROOT = 'evaluation-sheet-exports';

    /** A folder name of its own, unrelated to anything the browser has seen. */
    public function newFolder(): string
    {
        return (string) Str::ulid();
    }

    /**
     * Writes the generated grid and answers with the path to record.
     */
    public function put(string $folder, string $fileName, string $contents): string
    {
        $path = $this->pathFor($folder, $fileName);

        Storage::disk(self::DISK)->put($path, $contents);

        return $path;
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    protected function pathFor(string $folder, string $fileName): string
    {
        return self::ROOT.'/'.$this->safe($folder).'/'.$this->safe($fileName);
    }

    /**
     * Both segments are built here, never taken from a request. This is the
     * belt to that braces: a path is assembled from them and traversal is not
     * a risk worth carrying.
     */
    protected function safe(string $segment): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '', $segment) ?? '';
    }
}
