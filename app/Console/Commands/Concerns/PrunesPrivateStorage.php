<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use League\Flysystem\FilesystemException;

/**
 * The parts every private-storage prune got wrong the same way.
 *
 * `Storage::files()`, `Storage::directories()`, `Storage::allFiles()` and
 * `Storage::lastModified()` are NOT covered by a disk's `'throw' => false`:
 * unlike `delete()` and `copy()`, Laravel wraps none of them in a try/catch,
 * so a directory the process cannot read throws `UnableToListContents`
 * straight out of the command. On 2026-08-30 that killed `data-imports:prune`
 * every hour for 22 hours, because `storage/app/private/data-imports` had been
 * created 0700 by php-fpm while the scheduler runs as `lapis-deploy`.
 *
 * The four other prunes had the identical exposure and were only spared by
 * accident: their directories either predate the last group-permission fix or
 * do not exist in production at all, in which case the `exists()` guard
 * returns early and the command reports a success it never earned.
 *
 * Two rules live here, and they are the same rule twice: an operational
 * failure must never look like success, and it must never be fatal either —
 * one unreachable directory must not stop another organization's cleanup. So
 * every listing returns null instead of throwing, every failure is logged
 * with structure, and the count decides the exit code.
 */
trait PrunesPrivateStorage
{
    /**
     * Operations that could not be completed this run. Decides the exit code;
     * never aborts the run on its own.
     */
    protected int $pruneFailures = 0;

    /**
     * @return list<string>|null null when the directory refused to be listed
     */
    protected function listFilesSafely(Filesystem $disk, string $directory): ?array
    {
        try {
            return array_values($disk->files($directory));
        } catch (FilesystemException $exception) {
            $this->noteFailure('prune.listing_failed', ['directory' => $directory, 'reason' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @return list<string>|null null when the directory refused to be listed
     */
    protected function listDirectoriesSafely(Filesystem $disk, string $directory): ?array
    {
        try {
            return array_values($disk->directories($directory));
        } catch (FilesystemException $exception) {
            $this->noteFailure('prune.listing_failed', ['directory' => $directory, 'reason' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * One file's modification time, or null when it could not be read —
     * because it stopped existing underneath us, or stopped being readable.
     * Either way its age is unknown, and an unknown age is not a licence to
     * delete.
     */
    protected function modifiedAtSafely(Filesystem $disk, string $path): ?int
    {
        try {
            return $disk->lastModified($path);
        } catch (FilesystemException $exception) {
            $this->noteFailure('prune.metadata_failed', ['reason' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * The newest modification time under a folder, or null when it could not
     * be read. Null must never be treated as "old" — deleting a folder whose
     * age is unknown is exactly the mistake this whole slice is about.
     */
    protected function newestModifiedAt(Filesystem $disk, string $folder): ?int
    {
        try {
            $latest = 0;

            foreach ($disk->allFiles($folder) as $file) {
                $latest = max($latest, $disk->lastModified($file));
            }

            return $latest;
        } catch (FilesystemException $exception) {
            $this->noteFailure('prune.metadata_failed', ['directory' => $folder, 'reason' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * A private path is never logged: the file name is the only thing
     * standing between a log line and a student's data. The directory and the
     * reason are enough to act on.
     *
     * @param  array<string, mixed>  $context
     */
    protected function noteFailure(string $event, array $context = []): void
    {
        $this->pruneFailures++;

        Log::error($event, $context + ['command' => $this->getName()]);
    }

    protected function pruneFailed(): bool
    {
        return $this->pruneFailures > 0;
    }
}
