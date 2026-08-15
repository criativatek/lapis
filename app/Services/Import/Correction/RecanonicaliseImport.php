<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\ImportMapping;
use App\Models\CorrectionImport;
use App\Support\Import\CorrectionImportTempStorage;
use App\Support\Import\WithoutLeakingTheGrid;

/**
 * Re-reads the uploaded file whenever what it MEANS has changed.
 *
 * Only sources whose parser implements MappedCorrectionGridParser are affected;
 * for Plickers and Intuitivo this does nothing at all, because their canonical
 * grid was complete the moment the file was read and no answer the teacher gives
 * can change what the file said (§7).
 *
 * For a generic sheet the grid is a pure function of (file, mapping), so it is
 * recomputed rather than patched. Patching would mean keeping a grid and a
 * mapping in agreement across every field either of them has, which is the kind
 * of invariant that holds until the day somebody adds a field.
 *
 * THE STORED SNAPSHOT IS NEVER DISCARDED ON FAILURE. If the upload is gone —
 * pruned after a day untouched, or deleted by a cancellation racing a save — the
 * grid already on the row stays exactly as it was. A teacher who has mapped
 * thirty students does not lose them because a file vanished; they simply cannot
 * change the structure any further, and the preview goes on describing what was
 * last understood.
 */
class RecanonicaliseImport
{
    public function __construct(
        protected CorrectionGridParserRegistry $registry,
        protected CorrectionImportTempStorage $storage,
    ) {}

    /**
     * Returns true when the snapshot was rewritten.
     */
    public function for(CorrectionImport $import): bool
    {
        $parser = $this->registry->for($import->source);

        if (! $parser instanceof MappedCorrectionGridParser) {
            return false;
        }

        $path = $import->stored_path;

        if ($path === null || ! $this->storage->exists($path)) {
            return false;
        }

        $mapping = ImportMapping::fromArray($import->mapping_snapshot);

        // Only a sheet the teacher DESCRIBED is re-read from its description.
        // A file that explained itself — a LÁPIS grid — was canonicalised once,
        // at upload, against a contract no later answer changes; re-parsing it
        // here would also throw away a refusal already recorded against it.
        if (! $mapping->describesATable()) {
            return false;
        }

        $grid = $parser->parseWith(
            $this->storage->absolutePath($path),
            (string) $import->original_filename,
            $mapping,
        );

        // The snapshot is the whole sheet — names, marks, everything. A failure
        // writing it must not carry the payload into the log.
        WithoutLeakingTheGrid::run(function () use ($import, $grid): void {
            $import->forceFill([
                'canonical_snapshot' => $grid->toArray(),
                'source_metadata' => $grid->sourceMetadata,
            ])->save();
        }, 'ao reinterpretar a folha de cálculo');

        return true;
    }
}
