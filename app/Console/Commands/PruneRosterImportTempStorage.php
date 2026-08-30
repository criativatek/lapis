<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrunesPrivateStorage;
use Illuminate\Console\Command;
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
    use PrunesPrivateStorage;

    protected const ROOT = 'roster-imports';

    protected $signature = 'roster-imports:prune {--older-than=360 : Minutes a temp folder must sit untouched before it is deleted}';

    protected $description = 'Delete abandoned roster-import temp photo folders older than the given threshold';

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
            $this->error('Não foi possível listar a pasta de importações temporárias — ver o registo.');

            return self::FAILURE;
        }

        $pruned = 0;

        foreach ($folders as $tokenFolder) {
            // An empty folder still reports 0 — ancient, and pruned at once
            // rather than lingering forever, exactly as before. Null is the
            // new case: an age we could not read at all, which is not a
            // licence to delete a folder holding real students' photographs.
            $modifiedAt = $this->newestModifiedAt($disk, $tokenFolder);

            if ($modifiedAt === null || $modifiedAt >= $cutoff) {
                continue;
            }

            $disk->deleteDirectory($tokenFolder);

            if ($disk->exists($tokenFolder)) {
                $this->noteFailure('roster_import.prune.delete_failed', ['directory' => self::ROOT]);

                continue;
            }

            $pruned++;
        }

        $this->info("{$pruned} pasta(s) de importação temporária removida(s).");

        if ($this->pruneFailed()) {
            $this->error("{$this->pruneFailures} operação(ões) de limpeza falhou/falharam — ver o registo para o motivo.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
