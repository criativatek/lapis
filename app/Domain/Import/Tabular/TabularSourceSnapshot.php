<?php

namespace App\Domain\Import\Tabular;

/**
 * A whole file, read as sheets of cells, with nothing decided about it.
 *
 * This is the pre-canonical stage the two known formats never needed. Plickers
 * and Intuitivo have shapes Lapispro can recognise, so their parsers go straight to
 * the canonical vocabulary. A sheet the teacher made up has no shape anyone can
 * recognise, and the honest order is therefore: read the rectangle → show it →
 * let the teacher say what it means → only then canonicalise (§5).
 *
 * Deliberately shared by CSV and XLSX. A comma-separated file and a workbook are
 * different containers for the same idea, and once both are a rectangle of cells
 * everything downstream — suggestion, mapping, canonicalisation, testing — is
 * written once (§40).
 *
 * Transient by design: this is never persisted. It is rebuilt from the stored
 * upload whenever the teacher changes what the sheet means, which is what keeps
 * a second copy of thirty children's names out of the database.
 */
final readonly class TabularSourceSnapshot
{
    /**
     * @param  list<TabularSheet>  $sheets
     * @param  array<string, mixed>  $metadata  How the file was read. Never personal data.
     */
    public function __construct(
        public array $sheets = [],
        public array $metadata = [],
    ) {}

    public function sheet(?string $name): ?TabularSheet
    {
        if ($name === null) {
            return null;
        }

        foreach ($this->sheets as $sheet) {
            if ($sheet->name === $name) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * The sheet the wizard should open on, or null when the teacher has to say.
     *
     * One sheet with content is not a choice, so it is pre-selected. Several are
     * a choice, and picking the first merely because it is first is exactly the
     * silent guess this importer refuses to make (§11).
     */
    public function onlyOccupiedSheet(): ?TabularSheet
    {
        $occupied = $this->occupiedSheets();

        return count($occupied) === 1 ? $occupied[0] : null;
    }

    /**
     * @return list<TabularSheet>
     */
    public function occupiedSheets(): array
    {
        return array_values(array_filter($this->sheets, fn (TabularSheet $sheet): bool => ! $sheet->isEmpty()));
    }

    /**
     * @return list<string>
     */
    public function sheetNames(): array
    {
        return array_map(fn (TabularSheet $sheet): string => $sheet->name, $this->sheets);
    }

    public function isEmpty(): bool
    {
        return $this->occupiedSheets() === [];
    }
}
