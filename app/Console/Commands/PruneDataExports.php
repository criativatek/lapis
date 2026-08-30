<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrunesPrivateStorage;
use App\Models\DataExport;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes expired "my data" export ZIPs (Fatia 4, §27) — a user-generated
 * convenience artifact, not source data, kept only for
 * `config('retention.data_export_availability_hours')` (default 24h).
 *
 * `DataExportPolicy` already refuses a download past `expires_at` even if
 * this command hasn't run yet — this is the disk cleanup, not the access
 * control. It also clears `disk_path` on the row so a stale path never
 * outlives the file on disk.
 *
 * `withoutGlobalScope('organization')` is required, not incidental: this
 * runs from the scheduler, where no tenant is ever resolved
 * (CurrentOrganization::id() throws otherwise — there is nothing to scope
 * TO), and its whole job is to sweep every organization's expired exports in
 * one pass, the same legitimate cross-tenant case AdminAccountController's
 * blockingDependencies() already uses this for.
 *
 * ORDER MATTERS, and it did not until 0.101.3. This used to open with a bulk
 * `update(['disk_path' => null])` over every expired row and only then go
 * looking for folders to delete — so the pointer was dropped whether or not
 * the ZIP ever went, and a folder that survived became unattributable in the
 * same breath. It is now the other way round: delete the folder, prove it is
 * gone, and only then let the row forget where it was. A row that keeps its
 * `disk_path` is not a leak — `DataExportPolicy` still refuses the download
 * on `expires_at` — it is simply an honest record for the next run to retry.
 */
class PruneDataExports extends Command
{
    use PrunesPrivateStorage;

    protected const ROOT = 'data-exports';

    protected $signature = 'data-exports:prune {--older-than= : Minutes a token folder must sit untouched before it is deleted (defaults to the configured export availability window)}';

    protected $description = 'Delete expired data-export ZIPs and clear their database rows';

    public function handle(): int
    {
        $this->pruneFailures = 0;

        $disk = Storage::disk('local');
        $olderThanMinutes = (int) ($this->option('older-than') ?? ((int) config('retention.data_export_availability_hours')) * 60);
        $cutoff = now()->subMinutes($olderThanMinutes)->getTimestamp();

        $cleared = $this->removeExpiredExports($disk);
        $orphans = $this->removeOrphanFolders($disk, $cutoff);

        $this->info("{$cleared} exportação(ões) expirada(s) removida(s); {$orphans} pasta(s) órfã(s) removida(s).");

        if ($this->pruneFailed()) {
            $this->error("{$this->pruneFailures} operação(ões) de limpeza falhou/falharam — ver o registo para o motivo.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Each expired row's own token folder, deleted before its pointer is
     * dropped. `disk_path` is `data-exports/{token}/export.zip`, so the unit
     * to remove is the folder that holds it — the same unit the generator
     * created.
     */
    protected function removeExpiredExports(Filesystem $disk): int
    {
        $expired = DataExport::query()
            ->withoutGlobalScope('organization')
            ->whereNotNull('disk_path')
            ->where('expires_at', '<', now())
            ->get();

        $cleared = 0;

        foreach ($expired as $export) {
            $folder = dirname((string) $export->disk_path);

            if (! $this->removeFolder($disk, $folder)) {
                $this->noteFailure('data_export.prune.delete_failed', [
                    'data_export_id' => $export->getKey(),
                    'organization_id' => $export->organization_id,
                ]);

                continue;
            }

            $export->forceFill(['disk_path' => null])->save();

            $cleared++;
        }

        return $cleared;
    }

    /**
     * Folders nothing points at any more — a row deleted, a generation that
     * raced a failure, or an export cleared by an earlier version of this
     * command before it learned to check. Only touched past the cutoff, so a
     * ZIP still being written is never pulled out from under its request.
     */
    protected function removeOrphanFolders(Filesystem $disk, int $cutoff): int
    {
        if (! $disk->exists(self::ROOT)) {
            return 0;
        }

        $known = DataExport::query()
            ->withoutGlobalScope('organization')
            ->whereNotNull('disk_path')
            ->pluck('disk_path')
            ->map(fn (string $path): string => dirname($path))
            ->flip();

        $folders = $this->listDirectoriesSafely($disk, self::ROOT);

        if ($folders === null) {
            return 0;
        }

        $pruned = 0;

        foreach ($folders as $folder) {
            if ($known->has($folder)) {
                continue;
            }

            $modifiedAt = $this->newestModifiedAt($disk, $folder);

            // A folder whose age could not be read is left alone. Unknown is
            // not the same as old, and this one holds a person's whole export.
            if ($modifiedAt === null || $modifiedAt >= $cutoff) {
                continue;
            }

            if (! $this->removeFolder($disk, $folder)) {
                $this->noteFailure('data_export.prune.orphan_delete_failed', ['directory' => self::ROOT]);

                continue;
            }

            $pruned++;
        }

        return $pruned;
    }

    /**
     * Whether the folder is now genuinely gone — asked again afterwards
     * rather than taken on the return value, for the same reason every other
     * delete in this slice is verified.
     */
    protected function removeFolder(Filesystem $disk, string $folder): bool
    {
        if (! $disk->exists($folder)) {
            return true;
        }

        $disk->deleteDirectory($folder);

        // Asked of the filesystem, not of the return value — a folder that
        // survived its own deletion is the case this whole slice exists for.
        $absolutePath = $disk->path($folder);
        clearstatcache(true, $absolutePath);

        // `file_exists`, not `is_dir`: anything still standing at that path —
        // including a plain file where a token folder was expected — means
        // the removal did not happen, and the row keeps its pointer.
        return ! file_exists($absolutePath);
    }
}
