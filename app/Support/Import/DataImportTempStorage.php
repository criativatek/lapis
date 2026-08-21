<?php

namespace App\Support\Import;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where an uploaded LÁPIS backup sits while it is validated and reviewed —
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

    public function delete(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($relativePath);
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
