<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes abandoned roster-import temp folders
 * (storage/app/private/roster-imports/{token}/) left behind when a teacher
 * uploads a roster (and photos), sees the preview, and never confirms —
 * closes the tab, navigates away, gives up. There is no "cancel" action in
 * the UI, so RosterImportTempStorage::delete() only ever runs from
 * RosterImportController::confirm()'s cleanup; without this command those
 * folders — containing real student photos — would never be removed.
 * Matches the design's own promise ("o ficheiro carregado não é retido
 * indefinidamente", docs/superpowers/specs/2026-07-28-roster-import-design.md)
 * and RosterImportTempStorage's own docblock ("deleted in full once the
 * import is confirmed or abandoned").
 */
class PruneRosterImportTempStorage extends Command
{
    protected const ROOT = 'roster-imports';

    protected $signature = 'roster-imports:prune {--older-than=360 : Minutes a temp folder must sit untouched before it is deleted}';

    protected $description = 'Delete abandoned roster-import temp photo folders older than the given threshold';

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

        $this->info("{$pruned} pasta(s) de importação temporária removida(s).");

        return self::SUCCESS;
    }

    /**
     * The most recent modification time of any file inside the folder — an
     * empty folder (no files at all) is treated as ancient (0) so it is
     * pruned immediately rather than lingering forever.
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
