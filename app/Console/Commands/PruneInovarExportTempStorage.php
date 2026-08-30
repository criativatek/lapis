<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrunesPrivateStorage;
use Illuminate\Console\Command;
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
    use PrunesPrivateStorage;

    protected const ROOT = 'inovar-exports';

    protected $signature = 'inovar-exports:prune {--older-than=360 : Minutes a temp folder must sit untouched before it is deleted}';

    protected $description = 'Delete abandoned INOVAR export temp folders older than the given threshold';

    public function handle(): int
    {
        $this->pruneFailures = 0;

        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes((int) $this->option('older-than'))->getTimestamp();

        if (! $disk->exists(self::ROOT)) {
            return self::SUCCESS;
        }

        $folders = $this->listDirectoriesSafely($disk, self::ROOT);

        if ($folders === null) {
            $this->error('Não foi possível listar a pasta de exportações temporárias — ver o registo.');

            return self::FAILURE;
        }

        $pruned = 0;

        foreach ($folders as $tokenFolder) {
            // An empty folder still reports 0 — ancient, pruned at once, as
            // before. Null means the age could not be read at all, and an
            // INOVAR grid holds names, process numbers and marks: not
            // something to delete on a guess.
            $modifiedAt = $this->newestModifiedAt($disk, $tokenFolder);

            if ($modifiedAt === null || $modifiedAt >= $cutoff) {
                continue;
            }

            $disk->deleteDirectory($tokenFolder);

            if ($disk->exists($tokenFolder)) {
                $this->noteFailure('inovar_export.prune.delete_failed', ['directory' => self::ROOT]);

                continue;
            }

            $pruned++;
        }

        $this->info("{$pruned} pasta(s) temporária(s) de exportação removida(s).");

        if ($this->pruneFailed()) {
            $this->error("{$this->pruneFailures} operação(ões) de limpeza falhou/falharam — ver o registo para o motivo.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
