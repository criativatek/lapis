<?php

namespace App\Support\Import;

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

    public function delete(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($relativePath);
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
