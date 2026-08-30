<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrunesPrivateStorage;
use App\Models\CorrectionImport;
use App\Models\CorrectionImportStatus;
use App\Support\Import\CorrectionImportTempStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes correction-grid uploads that nobody came back for.
 *
 * A teacher uploads a CSV of their class's marks, sees the preview, and closes
 * the tab. Nothing in the wizard runs on the way out, so without this the file —
 * names and marks of real students, most of them minors — would sit on the
 * private disk indefinitely. The same reasoning, and the same schedule, as
 * PruneRosterImportTempStorage.
 *
 * Two passes, because there are two ways a file is orphaned: an import row that
 * has gone final without its file being cleaned up, and a file on disk with no
 * row pointing at it at all. The second pass is deliberately conservative — it
 * only removes what is old enough that no wizard could still be using it.
 */
class PruneCorrectionImportTempStorage extends Command
{
    use PrunesPrivateStorage;

    protected $signature = 'correction-imports:prune {--older-than=1440 : Minutes an upload may sit untouched before it is deleted}';

    protected $description = 'Delete abandoned correction-grid uploads and close the imports that owned them';

    public function __construct(protected CorrectionImportTempStorage $storage)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->pruneFailures = 0;
        $minutes = (int) $this->option('older-than');
        $cutoff = now()->subMinutes($minutes);

        $abandoned = CorrectionImport::query()
            ->withoutGlobalScope('organization')
            ->whereNotNull('stored_path')
            ->whereIn('status', [
                CorrectionImportStatus::Uploaded,
                CorrectionImportStatus::Parsed,
                CorrectionImportStatus::NeedsMapping,
                CorrectionImportStatus::Ready,
            ])
            ->where('updated_at', '<', $cutoff)
            ->get();

        $cancelled = 0;

        foreach ($abandoned as $import) {
            // The message below asserts the upload was removed, so it may only
            // be written once that is true. An unproven delete leaves the row
            // exactly as it was, for the next run to retry — never a pointer
            // dropped over a file that is still on disk.
            if (! $this->storage->delete($import->stored_path)->pointerMayBeCleared()) {
                $this->noteFailure('correction_import.prune.delete_failed', [
                    'correction_import_id' => $import->getKey(),
                    'organization_id' => $import->organization_id,
                ]);

                continue;
            }

            // The canonical snapshot stays: it is the record of what the file
            // said, and it is what makes deleting the file safe. Only the route
            // back to the raw upload is closed.
            $import->forceFill([
                'stored_path' => null,
                'status' => CorrectionImportStatus::Cancelled,
                'failure_reason' => __('Importação abandonada; o ficheiro carregado foi removido.'),
            ])->save();

            $cancelled++;
        }

        $orphans = $this->pruneOrphanFiles($cutoff->getTimestamp());

        $this->info("{$cancelled} importação(ões) abandonada(s) encerrada(s); {$orphans} ficheiro(s) órfão(s) removido(s).");

        if ($this->pruneFailed()) {
            $this->error("{$this->pruneFailures} operação(ões) de limpeza falhou/falharam — ver o registo para o motivo.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Files with no import row pointing at them — a row deleted, a write that
     * raced a failure. Only touched once they are older than the same cutoff,
     * so an upload in flight is never pulled out from under a live wizard.
     */
    protected function pruneOrphanFiles(int $cutoff): int
    {
        $disk = Storage::disk('local');
        $root = $this->storage->root();

        if (! $disk->exists($root)) {
            return 0;
        }

        $known = CorrectionImport::query()
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

            if ($modifiedAt === null || $modifiedAt >= $cutoff) {
                continue;
            }

            if ($disk->delete($file)) {
                $removed++;

                continue;
            }

            $this->noteFailure('correction_import.prune.orphan_delete_failed', ['directory' => $root]);
        }

        return $removed;
    }
}
