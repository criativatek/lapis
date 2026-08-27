<?php

namespace App\Support\Import;

use ZipArchive;

/**
 * What has to be true about an .xlsx package before a workbook library sees it.
 *
 * An XLSX is a zip, so a small upload can expand into a very large document. By
 * the time PhpSpreadsheet has the file the memory is already spent, which is why
 * every one of these checks happens against the package itself and before the
 * reader is handed anything.
 *
 * Extracted from the Intuitivo reader when the generic one needed exactly the
 * same guarantees. Two copies of a security check are two things to remember to
 * change, and the second copy is always the one nobody updates (§27).
 *
 * The ceilings are defensive, not functional. A real export measured 9.7 KB
 * expanding to 24.5 KB — a ratio of 2.5 — so these leave three orders of
 * magnitude of room for an honest file while refusing a hostile one.
 */
class SpreadsheetZipSafety
{
    public const MAX_UNCOMPRESSED_BYTES = 40 * 1024 * 1024;

    public const MAX_COMPRESSION_RATIO = 200;

    public const MAX_ENTRIES = 200;

    /**
     * Active content, refused at the door rather than ignored later.
     *
     * A macro project and an external link are both instructions to do something
     * when the file is opened. Lapispro opens spreadsheets for a living and has no
     * business carrying either, so their mere presence disqualifies the package —
     * it is not enough that this reader would not run them (§27).
     *
     * @var list<string>
     */
    protected const FORBIDDEN_PREFIXES = [
        'xl/vbaProject.bin',
        'xl/externalLinks/',
        'xl/activeX/',
        'xl/embeddings/',
    ];

    public function isSafe(string $absolutePath): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return false;
        }

        try {
            return $this->packageLooksSafe($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * `xl/workbook.xml`, read only from a package that passed every check.
     *
     * Callers use it to learn what a workbook claims about itself — its sheet
     * names — without loading it. Returns null for anything unsafe or absent, so
     * a caller cannot accidentally read from a package this class rejected.
     */
    public function workbookXml(string $absolutePath): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return null;
        }

        try {
            if (! $this->packageLooksSafe($zip)) {
                return null;
            }

            $workbook = $zip->getFromName('xl/workbook.xml');

            return is_string($workbook) && $workbook !== '' ? $workbook : null;
        } finally {
            $zip->close();
        }
    }

    protected function packageLooksSafe(ZipArchive $zip): bool
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return false;
        }

        $compressed = 0;
        $uncompressed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);

            if ($entry === false) {
                return false;
            }

            $name = (string) $entry['name'];

            foreach (self::FORBIDDEN_PREFIXES as $forbidden) {
                if (str_starts_with($name, $forbidden)) {
                    return false;
                }
            }

            $compressed += (int) $entry['comp_size'];
            $uncompressed += (int) $entry['size'];

            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                return false;
            }
        }

        return $compressed === 0 || $uncompressed / max($compressed, 1) <= self::MAX_COMPRESSION_RATIO;
    }
}
