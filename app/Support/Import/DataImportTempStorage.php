<?php

namespace App\Support\Import;

use App\Support\Storage\RemovalOutcome;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where an uploaded Lapispro backup sits while it is validated and reviewed —
 * on the private `local` disk, never `public`, never web-reachable. Same
 * shape and reasoning as CorrectionImportTempStorage: a random stored name,
 * an extension taken from an allowlist rather than from what the upload
 * claimed, and the teacher's own filename kept only as a label in the
 * database, never resolved back into a path.
 *
 * A backup restore is the single most sensitive upload this app accepts —
 * it can contain real students' pseudonymised data — so the same rule
 * applies: delete on success, delete on cancel, prune whatever was
 * abandoned in between (data-imports:prune).
 */
class DataImportTempStorage
{
    protected const ROOT = 'data-imports';

    protected const DISK = 'local';

    /**
     * @var list<string>
     */
    protected const SAFE_EXTENSIONS = ['zip', 'json'];

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

    public function absolutePath(string $relativePath): string
    {
        return Storage::disk(self::DISK)->path($relativePath);
    }

    /**
     * Removes the stored upload and says what actually happened.
     *
     * Deliberately not `void`, and deliberately not a bare bool. Flysystem's
     * local adapter opens its delete with `file_exists()` and returns early
     * when that is false — but a directory the process may not traverse also
     * answers false, so "I cannot see it" was being reported as "it is gone".
     * The caller has to be able to tell those apart before it drops the only
     * pointer to the file (see RemovalOutcome).
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

        // Ask the filesystem itself rather than trust the return value: the
        // delete we care about is the one that actually happened. Safe to
        // read `file_exists()` straight here, unlike above — getting this far
        // means the file could be seen a moment ago, so the directory is
        // traversable and a false now really does mean gone.
        $absolutePath = $disk->path($relativePath);
        clearstatcache(true, $absolutePath);

        return file_exists($absolutePath) ? RemovalOutcome::Failed : RemovalOutcome::Removed;
    }

    /**
     * Whether "not found" can be believed.
     *
     * A file inside a directory we cannot read is indistinguishable from a
     * file that was never there — unless we ask the directory itself, which
     * needs only its own parent to be traversable. When the directory is
     * gone entirely there is nothing left to doubt.
     */
    protected function absenceIsProvable(string $relativePath): bool
    {
        $directory = dirname(Storage::disk(self::DISK)->path($relativePath));

        return ! is_dir($directory) || is_readable($directory);
    }

    /**
     * Content hash of the stored upload — recognising the same backup again,
     * and the provenance record of what was actually read.
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
