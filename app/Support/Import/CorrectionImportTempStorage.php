<?php

namespace App\Support\Import;

use App\Support\Storage\RemovalOutcome;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where an uploaded correction grid sits while the teacher works through the
 * wizard — on the private `local` disk, never `public`, never web-reachable.
 *
 * The stored name is random and the extension is taken from an allowlist rather
 * than from what the upload claimed: a filename is user input, and user input
 * that becomes a path is how a traversal happens. The teacher's own filename is
 * kept in the database as a label, where it can be shown without ever being
 * resolved.
 *
 * The file is short-lived by design. These exports contain names and marks of
 * real students, most of them minors, so the retention rule is the same one the
 * roster import already follows: delete on success, delete on cancel, and prune
 * whatever was abandoned in between.
 */
class CorrectionImportTempStorage
{
    protected const ROOT = 'correction-imports';

    protected const DISK = 'local';

    /**
     * Extensions the stored file may carry. Anything else is stored with none —
     * the parser is chosen by the registry, not by the name on disk.
     *
     * @var list<string>
     */
    protected const SAFE_EXTENSIONS = ['csv', 'xlsx', 'xls'];

    /**
     * @return string The path relative to the private disk, for the database.
     */
    public function store(string $absoluteSourcePath, string $originalFilename): string
    {
        $extension = $this->safeExtension($originalFilename);
        $name = (string) Str::uuid();
        $relativePath = self::ROOT.'/'.$name.($extension === null ? '' : '.'.$extension);

        $stream = fopen($absoluteSourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Não foi possível ler o ficheiro carregado.');
        }

        Storage::disk(self::DISK)->writeStream($relativePath, $stream);
        fclose($stream);

        return $relativePath;
    }

    public function exists(string $relativePath): bool
    {
        return Storage::disk(self::DISK)->exists($relativePath);
    }

    /**
     * The absolute path a parser reads from. Parsers work on files, not streams:
     * PhpSpreadsheet needs a real path, and a CSV reader is far easier to get
     * right against one too.
     */
    public function absolutePath(string $relativePath): string
    {
        return Storage::disk(self::DISK)->path($relativePath);
    }

    /**
     * Removes the stored upload and says what actually happened.
     *
     * Same contract, and the same reason, as DataImportTempStorage::delete():
     * Flysystem's local adapter opens its delete with `file_exists()`, which
     * answers false both for "no such file" and for "this directory will not
     * let me look". Only the first is proof, and the caller has to be able to
     * tell them apart before it drops the pointer (see RemovalOutcome).
     */
    public function delete(?string $relativePath): RemovalOutcome
    {
        if ($relativePath === null || $relativePath === '') {
            return RemovalOutcome::AlreadyAbsent;
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($relativePath)) {
            return $this->absenceIsProvable($relativePath)
                ? RemovalOutcome::AlreadyAbsent
                : RemovalOutcome::Failed;
        }

        if (! $disk->delete($relativePath)) {
            return RemovalOutcome::Failed;
        }

        // Asked of the filesystem, not of the return value. Reading
        // `file_exists()` directly is safe here and not above: getting this
        // far means the file was visible a moment ago, so the directory is
        // traversable and a false now really does mean gone.
        $absolutePath = $disk->path($relativePath);
        clearstatcache(true, $absolutePath);

        return file_exists($absolutePath) ? RemovalOutcome::Failed : RemovalOutcome::Removed;
    }

    /**
     * Whether "not found" can be believed — the directory itself is asked,
     * because reaching it needs only its parent to be traversable.
     */
    protected function absenceIsProvable(string $relativePath): bool
    {
        $directory = dirname(Storage::disk(self::DISK)->path($relativePath));

        return ! is_dir($directory) || is_readable($directory);
    }

    /**
     * Content hash of the stored upload — the same file recognised again, and
     * the provenance record of what was actually read (§25).
     */
    public function hash(string $absolutePath): string
    {
        return (string) hash_file('sha256', $absolutePath);
    }

    /**
     * Never trusted as a path, only as a suffix, and only from the allowlist.
     */
    protected function safeExtension(string $originalFilename): ?string
    {
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));

        return in_array($extension, self::SAFE_EXTENSIONS, true) ? $extension : null;
    }

    public function root(): string
    {
        return self::ROOT;
    }
}
