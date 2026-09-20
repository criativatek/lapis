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
     * @param  array<string, SectionMergeResult>  $sectionMerges  What confirming this row would do to
     *                                                            each section already recorded for the matched student —
     *                                                            «já registado» versus «a acrescentar» — keyed the same
     *                                                            way as `sections`. Empty when the row matched nobody, since
     *                                                            there is then nothing recorded to merge against.
     * @param  list<CodeResolution>  $measures  Destination B — recognised, storable.
     * @param  list<string>  $alreadyActiveMeasureCodes  The subset of `$measures`' codes that already
     *                                                   have an active Intervention for the matched student:
     *                                                   confirming the row will NOT create a second one for
     *                                                   these (§30). Empty when the row matched nobody.
     * @param  list<CodeResolution>  $resources  Destination C — understood as supports or
     *                                           resources, and stored by nobody: the catalogue has
     *                                           no item for them, so there is no honest column.
     *                                           Shown, named, and left alone.
     * @param  list<CodeResolution>  $unresolved  Destination D — nothing is stored from these.
     */
    public function __construct(
        public int $rowNumber,
        public string $rawName,
        public ?string $rawProcessNumber,
        public RowMatch $match,
        public array $sections = [],
        public array $sectionMerges = [],
        public array $measures = [],
        public array $alreadyActiveMeasureCodes = [],
        public array $resources = [],
        public array $unresolved = [],
    ) {}

    /**
     * A row with a student but nothing to say about them is noise in the
     * preview: it asks the teacher to confirm writing nothing.
     */
    public function hasContent(): bool
    {
        return $this->sections !== [] || $this->measures !== [] || $this->resources !== [] || $this->unresolved !== [];
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
            'section_merges' => array_map(
                fn (SectionMergeResult $r) => $r->toArray(),
                $this->sectionMerges,
            ),
            'measures' => array_map(
                fn (CodeResolution $r) => [
                    ...$r->toArray(),
                    // «Já registada — não será duplicada» (§29, §30): the
                    // client never decides this — it only ever reads what the
                    // server already checked, exactly as the write path itself
                    // will check it again when the import is confirmed.
                    'already_active' => $r->code !== null && in_array($r->code->value, $this->alreadyActiveMeasureCodes, true),
                ],
                $this->measures
            ),
            'resources' => array_map(fn (CodeResolution $r) => $r->toArray(), $this->resources),
            'unresolved' => array_map(fn (CodeResolution $r) => $r->toArray(), $this->unresolved),
        ];
    }
}
