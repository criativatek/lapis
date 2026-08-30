<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrunesPrivateStorage;
use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Support\Import\DataImportTempStorage;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes backup uploads that nobody came back to confirm.
 *
 * A teacher uploads a backup, sees the preview, and closes the tab — or
 * simply never confirms within the retention window. Nothing in the wizard
 * runs on the way out, so without this the file would sit on the private
 * disk indefinitely. Same reasoning and shape as
 * PruneCorrectionImportTempStorage: `withoutGlobalScope('organization')`
 * because this runs from the scheduler, where no tenant is ever resolved
 * (§16 of the lifecycle brief already documents why that matters here).
 *
 * THREE passes, and the third one is a net under the other two:
 *
 *   1. an import still open past its `expires_at` — cancelled, file removed;
 *   2. an import already in a terminal state that is STILL holding a file
 *      past that window. `Imported` and `Cancelled` get here only when the
 *      wizard's own delete failed; `Failed` used to get here always, because
 *      DataImportController::confirm() marked the row and walked away from
 *      the upload. Terminal history is not rewritten — only the file goes;
 *   3. a file on disk with no row pointing at it at all, old enough that no
 *      wizard could still be using it.
 *
 * THE RULE, learned the hard way in production on 2026-08-30: never clear
 * `stored_path` before the file is confirmed gone. The pointer is the only
 * record of whose data an upload held; dropping it while the file survives
 * turns a retained backup into an unattributable one. So every delete here
 * goes through RemovalOutcome, a failure leaves the row exactly as it was
 * for the next run to retry, and the command exits non-zero so the failure
 * is visible to something other than a stack trace.
 */
class PruneDataImports extends Command
{
    use PrunesPrivateStorage;

    protected $signature = 'data-imports:prune';

    protected $description = 'Delete abandoned backup uploads and close the imports that owned them';

    public function __construct(protected DataImportTempStorage $storage)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->pruneFailures = 0;
        $now = now();

        $cancelled = $this->cancelAbandonedImports($now);
        $swept = $this->sweepTerminalLeftovers($now);
        $orphans = $this->pruneOrphanFiles($now->copy()->subDay()->getTimestamp());

        $this->info("{$cancelled} importação(ões) abandonada(s) encerrada(s); {$swept} ficheiro(s) residual(is) de importações terminadas removido(s); {$orphans} ficheiro(s) órfão(s) removido(s).");

        if ($this->pruneFailed()) {
            $this->error("{$this->pruneFailures} operação(ões) de limpeza falhou/falharam — nada foi dado como removido sem prova. Ver o registo para o motivo.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Pass 1 — still open, past the window. The canonical snapshot stays: it
     * is the record of what the backup said, and it is what makes deleting
     * the file safe. Only the route back to the raw upload is closed.
     */
    protected function cancelAbandonedImports(CarbonInterface $now): int
    {
        $cancelled = 0;

        foreach ($this->expiredWithFile([DataImportStatus::Uploaded, DataImportStatus::Validated], $now) as $import) {
            if (! $this->removeFileOf($import)) {
                continue;
            }

            $import->forceFill([
                'stored_path' => null,
                'status' => DataImportStatus::Cancelled,
                'failure_reason' => __('Importação abandonada; o ficheiro carregado foi removido.'),
            ])->save();

            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * Pass 2 — terminal, but still holding the upload past its window.
     *
     * The status is left exactly as it is. An import that failed, failed; one
     * that was imported, was imported. What is corrected here is only the
     * file that should not have outlived it, and the pointer that goes with
     * it — never the history a teacher may already have been shown.
     */
    protected function sweepTerminalLeftovers(CarbonInterface $now): int
    {
        $swept = 0;

        $terminal = array_filter(
            DataImportStatus::cases(),
            fn (DataImportStatus $status): bool => $status->isFinal(),
        );

        foreach ($this->expiredWithFile($terminal, $now) as $import) {
            if (! $this->removeFileOf($import)) {
                continue;
            }

            $import->forceFill(['stored_path' => null])->save();

            $swept++;
        }

        return $swept;
    }

    /**
     * Imports in the given states that still point at a file and whose
     * window has passed. A null `expires_at` is treated as expired for the
     * same reason it always was: a row with no window is a row nothing will
     * ever come back for.
     *
     * @param  array<int, DataImportStatus>  $statuses
     * @return Collection<int, DataImport>
     */
    protected function expiredWithFile(array $statuses, CarbonInterface $now): Collection
    {
        return DataImport::query()
            ->withoutGlobalScope('organization')
            ->whereNotNull('stored_path')
            ->whereIn('status', array_map(fn (DataImportStatus $status): string => $status->value, $statuses))
            ->where(function ($query) use ($now) {
                $query->where('expires_at', '<', $now)->orWhereNull('expires_at');
            })
            ->get();
    }

    /**
     * Deletes one import's upload, and answers whether the row may now
     * forget where it was. A failure is counted and logged with the id — not
     * the path, which is a private disk location — and the row is left
     * untouched for the next run.
     */
    protected function removeFileOf(DataImport $import): bool
    {
        $outcome = $this->storage->delete($import->stored_path);

        if ($outcome->pointerMayBeCleared()) {
            return true;
        }

        $this->noteFailure('data_import.prune.delete_failed', [
            'data_import_id' => $import->getKey(),
            'organization_id' => $import->organization_id,
            'status' => $import->status->value,
            'outcome' => $outcome->value,
        ]);

        return false;
    }

    /**
     * Files with no import row pointing at them — a row deleted, a write
     * that raced a failure. Only touched once older than the cutoff, so an
     * upload in flight is never pulled out from under a live wizard.
     *
     * `Storage::files()` and `Storage::lastModified()` are NOT covered by the
     * disk's own `'throw' => false`: unlike `delete()` and `copy()`, Laravel
     * wraps neither in a try/catch, so a directory the process cannot read
     * throws straight out of here. That is exactly what happened in
     * production every hour from 2026-08-30 02:00. It is an operational
     * condition, not a fatal one — it is reported and counted, never
     * swallowed, and never allowed to look like success.
     */
    protected function pruneOrphanFiles(int $cutoff): int
    {
        $disk = Storage::disk('local');
        $root = $this->storage->root();

        if (! $disk->exists($root)) {
            return 0;
        }

        $known = DataImport::query()
            ->withoutGlobalScope('organization')
            ->whereNotNull('stored_path')
            ->pluck('stored_path')
            ->flip();

        $files = $this->listFilesSafely($disk, $root);

        if ($files === null) {
            return 0;
        }

        $removed = 0;

        foreach ($files as $file) {
            if ($known->has($file)) {
                continue;
            }

            $modifiedAt = $this->modifiedAtSafely($disk, $file);

            // An age we could not read is not a licence to delete: the file
            // may have gone away underneath us, or stopped being readable.
            if ($modifiedAt === null || $modifiedAt >= $cutoff) {
                continue;
            }

            if ($disk->delete($file)) {
                $removed++;

                continue;
            }

            $this->noteFailure('data_import.prune.orphan_delete_failed', ['directory' => $root]);
        }

        return $removed;
    }
}
