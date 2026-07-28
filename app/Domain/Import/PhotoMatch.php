<?php

// app/Domain/Import/PhotoMatch.php

namespace App\Domain\Import;

/**
 * One (name, photo) pair extracted from the Word photo sheet, before matching
 * against the roster. The name is the raw caption text — matching against a
 * RosterRow happens in RosterImportPreviewBuilder, not here.
 */
final readonly class PhotoMatch
{
    public function __construct(
        public string $name,
        public string $imageBytes,
        public string $extension,
    ) {}
}
