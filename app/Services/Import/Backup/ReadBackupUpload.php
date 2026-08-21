<?php

namespace App\Services\Import\Backup;

use App\Support\Import\Backup\BackupValidationException;
use ZipArchive;

/**
 * Reads `backup-lapis.json` out of an uploaded file — a ZIP produced by
 * GenerateDataExport, or the JSON file on its own (§2 of the import
 * brief). Never calls ZipArchive::extractTo() and never writes anything to
 * disk: the single entry it needs is read directly into memory via
 * getFromName(), after the whole archive has been checked entry-by-entry.
 * That sidesteps Zip Slip entirely (§17) — there is no extraction path for
 * a crafted entry name to escape — while the entry-count and size checks
 * below (§18) still stop a decompression bomb before any bytes are read.
 */
class ReadBackupUpload
{
    protected const string BACKUP_ENTRY_NAME = 'backup-lapis.json';

    protected const int MAX_ENTRIES = 500;

    protected const int MAX_ENTRY_UNCOMPRESSED_BYTES = 20 * 1024 * 1024;

    protected const int MAX_TOTAL_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    /**
     * @return string the raw JSON text of backup-lapis.json
     *
     * @throws BackupValidationException
     */
    public function readZip(string $absolutePath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            throw BackupValidationException::corruptZip();
        }

        try {
            return $this->readEntrySafely($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return string the raw JSON text, read directly (no ZIP involved)
     */
    public function readJson(string $absolutePath): string
    {
        $contents = file_get_contents($absolutePath);

        if ($contents === false) {
            throw BackupValidationException::invalidJson();
        }

        return $contents;
    }

    protected function readEntrySafely(ZipArchive $zip): string
    {
        $entries = $zip->numFiles;

        if ($entries > self::MAX_ENTRIES) {
            throw BackupValidationException::tooManyEntries();
        }

        $totalUncompressed = 0;
        $entryFound = false;

        for ($index = 0; $index < $entries; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw BackupValidationException::corruptZip();
            }

            $name = (string) $stat['name'];
            $size = (int) $stat['size'];

            if (str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                throw BackupValidationException::unsafeZipEntry();
            }

            if ($size > self::MAX_ENTRY_UNCOMPRESSED_BYTES) {
                throw BackupValidationException::tooLarge();
            }

            $totalUncompressed += $size;

            if ($totalUncompressed > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                throw BackupValidationException::tooLarge();
            }

            if ($name === self::BACKUP_ENTRY_NAME) {
                $entryFound = true;
            }
        }

        if (! $entryFound) {
            throw BackupValidationException::missingBackupEntry();
        }

        $json = $zip->getFromName(self::BACKUP_ENTRY_NAME);

        if ($json === false) {
            throw BackupValidationException::corruptZip();
        }

        return $json;
    }
}
