<?php

namespace App\Domain\Export;

/**
 * The file a record points at: which disk, which path, the checksum of its
 * BYTES, and the extension it was born with.
 *
 * The checksum is of the file itself and not of the payload. They answer
 * different questions and both are kept: `payload_hash` says the frozen
 * document has not been edited, this says the spreadsheet downloaded today is
 * byte-for-byte the one that was generated.
 *
 * The extension travels because the format is the grid's own — a grid INOVAR
 * sent as `.xls` goes back as `.xls`, and converting it would be changing a
 * contract we do not own.
 */
final readonly class GeneratedExportFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $checksum,
        public string $extension,
    ) {}
}
