<?php

namespace App\Services\Characterisation\Import;

use App\Support\Characterisation\CodeResolution;

/**
 * One row of the import, as proposed — never as decided.
 *
 * The four destinations of the brief live here as four separate properties, and
 * that separation is the point. A single «notes» blob would put a recognised
 * measure, a sentence about a child's interests and an acronym nobody could
 * expand into the same field, where none of them could be told apart again.
 */
readonly class PreviewRow
{
    /**
     * @param  array<string, string>  $sections  Destination A, keyed by CharacterisationSection value.
     * @param  list<CodeResolution>  $measures  Destination B — recognised, storable.
     * @param  list<CodeResolution>  $unresolved  Destination D — nothing is stored from these.
     */
    public function __construct(
        public int $rowNumber,
        public string $rawName,
        public ?string $rawProcessNumber,
        public RowMatch $match,
        public array $sections = [],
        public array $measures = [],
        public array $unresolved = [],
    ) {}

    /**
     * A row with a student but nothing to say about them is noise in the
     * preview: it asks the teacher to confirm writing nothing.
     */
    public function hasContent(): bool
    {
        return $this->sections !== [] || $this->measures !== [] || $this->unresolved !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'raw_name' => $this->rawName,
            'raw_process_number' => $this->rawProcessNumber,
            'match' => $this->match->toArray(),
            'sections' => $this->sections,
            'measures' => array_map(fn (CodeResolution $r) => $r->toArray(), $this->measures),
            'unresolved' => array_map(fn (CodeResolution $r) => $r->toArray(), $this->unresolved),
        ];
    }
}
