<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes abandoned INOVAR-export temp folders
 * (storage/app/private/inovar-exports/{token}/) left behind when a teacher
 * uploads a grid, reads the preview, and never generates — closes the tab,
 * finds a blocking error they need to go and fix, gives up.
 *
 * Generating deletes the folder itself, so this only ever catches the
 * abandoned ones. Without it they would sit there forever, and an INOVAR grid
 * holds names, process numbers and marks — the same promise the roster and
 * correction imports already keep for their own uploads.
 */
class PruneInovarExportTempStorage extends Command
{
    protected const ROOT = 'inovar-exports';

    protected $signature = 'inovar-exports:prune {--older-than=360 : Minutes a temp folder must sit untouched before it is deleted}';

    protected $description = 'Delete abandoned INOVAR export temp folders older than the given threshold';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes((int) $this->option('older-than'))->getTimestamp();

        if (! $disk->exists(self::ROOT)) {
            return self::SUCCESS;
        }

        $pruned = 0;

        foreach ($disk->directories(self::ROOT) as $tokenFolder) {
            if ($this->lastModifiedAt($disk, $tokenFolder) < $cutoff) {
                $disk->deleteDirectory($tokenFolder);
                $pruned++;
            }
        }

        $this->info("{$pruned} pasta(s) temporária(s) de exportação removida(s).");

        return self::SUCCESS;
    }

    /**
     * The most recent modification time of any file inside the folder — an
     * empty folder is treated as ancient so it is pruned immediately rather
     * than lingering forever.
     */
    protected function lastModifiedAt(Filesystem $disk, string $folder): int
    {
        $latest = 0;

        foreach ($disk->allFiles($folder) as $file) {
            $latest = max($latest, $disk->lastModified($file));
        }

        return $latest;
    }
}
