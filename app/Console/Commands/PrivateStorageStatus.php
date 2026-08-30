<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

/**
 * Can whoever is running this actually clean the private storage?
 *
 * The question nobody could answer on 2026-08-30. `data-imports:prune` had
 * been failing every hour for 22 hours; `schedule:list` showed it scheduled,
 * `scheduler.log` showed `DONE`, and the only honest signal was a stack trace
 * buried in `laravel.log`. The cause was mundane and invisible from inside the
 * application: `storage/app/private/data-imports` is created 0700 by php-fpm
 * (Flysystem's default for a disk with no `visibility`, and the `local` disk
 * declares none), while the scheduler runs as a different user that only
 * shares the group.
 *
 * So this asks the filesystem the same three questions the prunes need
 * answered, as the user actually running it, and changes nothing:
 *
 *   - is the directory there at all?
 *   - can it be listed? (what `Storage::files()` needs, and what threw)
 *   - can it be written to? (what deleting a file inside it needs)
 *
 * Run it as the scheduler's user to get the answer that matters:
 *   sudo -u lapis-deploy php artisan storage:private-status
 *
 * Exits non-zero when a directory exists but cannot be used, so it works as a
 * probe rather than only as something to read.
 */
class PrivateStorageStatus extends Command
{
    /**
     * The private roots the scheduled prunes have to be able to work in.
     *
     * @var list<string>
     */
    protected const ROOTS = [
        'data-imports',
        'correction-imports',
        'roster-imports',
        'inovar-exports',
        'data-exports',
    ];

    protected $signature = 'storage:private-status {--json : Machine-readable output}';

    protected $description = 'Read-only check that the private storage directories can be listed and written by the current user';

    public function handle(): int
    {
        $disk = Storage::disk('local');

        $rows = [];
        $blocked = 0;

        foreach (self::ROOTS as $root) {
            $absolutePath = $disk->path($root);
            clearstatcache(true, $absolutePath);

            $exists = is_dir($absolutePath);
            $listable = $exists ? $this->isListable($disk, $root) : null;
            $writable = $exists ? is_writable($absolutePath) : null;

            if ($listable === false || $writable === false) {
                $blocked++;
            }

            $rows[] = [
                'directory' => $root,
                'exists' => $exists,
                'listable' => $listable,
                'writable' => $writable,
                'mode' => $exists ? $this->mode($absolutePath) : null,
                'owner' => $exists ? $this->owner($absolutePath) : null,
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['running_as' => $this->runningAs(), 'roots' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $blocked > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->info('A correr como: '.$this->runningAs());
        $this->newLine();

        $this->table(
            ['Pasta', 'Existe', 'Listável', 'Gravável', 'Modo', 'Dono'],
            array_map(fn (array $row): array => [
                $row['directory'],
                $this->tick($row['exists']),
                $this->tick($row['listable']),
                $this->tick($row['writable']),
                $row['mode'] ?? '—',
                $row['owner'] ?? '—',
            ], $rows),
        );

        if ($blocked > 0) {
            $this->error("{$blocked} pasta(s) existe(m) mas não pode(m) ser usada(s) por este utilizador — a limpeza agendada vai falhar nelas.");

            return self::FAILURE;
        }

        $this->info('Todas as pastas privadas existentes são utilizáveis por este utilizador.');

        return self::SUCCESS;
    }

    /**
     * The one question a permission bit cannot be trusted to answer on its
     * own: actually try the listing the prunes perform, and see if it throws.
     */
    protected function isListable(Filesystem $disk, string $root): bool
    {
        try {
            $disk->files($root);

            return true;
        } catch (FilesystemException) {
            return false;
        }
    }

    protected function mode(string $absolutePath): ?string
    {
        $permissions = @fileperms($absolutePath);

        return $permissions === false ? null : substr(sprintf('%o', $permissions), -4);
    }

    /**
     * POSIX only, and quietly skipped elsewhere — the Windows dev machines
     * have no meaningful answer, and an invented one would be worse.
     */
    protected function owner(string $absolutePath): ?string
    {
        if (! function_exists('posix_getpwuid') || ! function_exists('posix_getgrgid')) {
            return null;
        }

        $userId = @fileowner($absolutePath);
        $groupId = @filegroup($absolutePath);

        if ($userId === false || $groupId === false) {
            return null;
        }

        $user = posix_getpwuid($userId);
        $group = posix_getgrgid($groupId);

        return ($user['name'] ?? $userId).':'.($group['name'] ?? $groupId);
    }

    protected function runningAs(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (isset($user['name'])) {
                return (string) $user['name'];
            }
        }

        return get_current_user() ?: 'desconhecido';
    }

    protected function tick(?bool $value): string
    {
        return match ($value) {
            true => 'sim',
            false => 'NÃO',
            null => '—',
        };
    }
}
