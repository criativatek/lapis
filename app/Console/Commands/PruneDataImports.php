<?php

namespace App\Console\Commands;

use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Support\Import\DataImportTempStorage;
use Illuminate\Console\Command;
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
 * Two passes: an import row still open past its `expires_at`, and a file on
 * disk with no row pointing at it at all. The second pass only removes what
 * is old enough that no wizard could still be using it.
 */
class PruneDataImports extends Command
{
    protected $signature = 'data-imports:prune';

    protected $description = 'Delete abandoned backup uploads and close the imports that owned them';

    public function __construct(protected DataImportTempStorage $storage)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();

        $abandoned = DataImport::query()
            ->withoutGlobalScope('organization')
            ->whereNotNull('stored_path')
            ->whereIn('status', [DataImportStatus::Uploaded, DataImportStatus::Validated])
            ->where(function ($query) use ($now) {
                $query->where('expires_at', '<', $now)->orWhereNull('expires_at');
            })
            ->get();

        foreach ($abandoned as $import) {
            $this->storage->delete($import->stored_path);

            // The canonical snapshot stays: it is the record of what the
            // backup said, and it is what makes deleting the file safe.
            // Only the route back to the raw upload is closed.
            $import->forceFill([
                'stored_path' => null,
                'status' => DataImportStatus::Cancelled,
                'failure_reason' => __('Importação abandonada; o ficheiro carregado foi removido.'),
            ])->save();
        }

        $orphans = $this->pruneOrphanFiles($now->subDay()->getTimestamp());

        $this->info("{$abandoned->count()} importação(ões) abandonada(s) encerrada(s); {$orphans} ficheiro(s) órfão(s) removido(s).");

        return self::SUCCESS;
    }

    /**
     * Files with no import row pointing at them — a row deleted, a write
     * that raced a failure. Only touched once older than the cutoff, so an
     * upload in flight is never pulled out from under a live wizard.
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

        $removed = 0;

        foreach ($disk->files($root) as $file) {
            if ($known->has($file) || $disk->lastModified($file) >= $cutoff) {
                continue;
            }

            $disk->delete($file);
            $removed++;
        }

        return $removed;
    }
}
