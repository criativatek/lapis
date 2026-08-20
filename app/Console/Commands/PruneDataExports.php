<?php

namespace App\Console\Commands;

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
 */
class PruneDataExports extends Command
{
    protected const ROOT = 'data-exports';

    protected $signature = 'data-exports:prune {--older-than= : Minutes a token folder must sit untouched before it is deleted (defaults to the configured export availability window)}';

    protected $description = 'Delete expired data-export ZIPs and clear their database rows';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $olderThanMinutes = (int) ($this->option('older-than') ?? ((int) config('retention.data_export_availability_hours')) * 60);
        $cutoff = now()->subMinutes($olderThanMinutes)->getTimestamp();

        DataExport::query()
            ->whereNotNull('disk_path')
            ->where('expires_at', '<', now())
            ->update(['disk_path' => null]);

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

        $this->info("{$pruned} exportação(ões) expirada(s) removida(s).");

        return self::SUCCESS;
    }

    protected function lastModifiedAt(Filesystem $disk, string $folder): int
    {
        $latest = 0;

        foreach ($disk->allFiles($folder) as $file) {
            $latest = max($latest, $disk->lastModified($file));
        }

        return $latest;
    }
}
