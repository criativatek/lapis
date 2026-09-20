<?php

namespace App\Services\Characterisation\Import;

use App\Support\Characterisation\AcronymSuggestion;
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
     * @param  array<int, ?float>  $extractionConfidence  §18 — "did I read this cell right?",
     *                                                    keyed by spl_object_id() of the SAME
     *                                                    CodeResolution objects that populate
     *                                                    measures/resources/unresolved — never a
     *                                                    property on CodeResolution itself, so this
     *                                                    axis can never be merged into the domain
     *                                                    confidence CodeConfidence carries.
     * @param  array<int, AcronymSuggestion>  $suggestions  §19 — a plausible correction for an
     *                                                      unresolved token that may have been
     *                                                      misread, keyed the same way. Only ever
     *                                                      has an entry for a member of `unresolved`,
     *                                                      and only when one was found.
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
        public array $extractionConfidence = [],
        public array $suggestions = [],
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
                    ...$this->extractionArray($r),
                ],
                $this->measures
            ),
            'resources' => array_map(
                fn (CodeResolution $r) => [...$r->toArray(), ...$this->extractionArray($r)],
                $this->resources
            ),
            'unresolved' => array_map(
                fn (CodeResolution $r) => [...$r->toArray(), ...$this->extractionArray($r), ...$this->suggestionArray($r)],
                $this->unresolved
            ),
        ];
    }

    /**
     * §18: 'extraction_confidence' — always present, null wherever the cell
     * was read exactly rather than by OCR. A SEPARATE key from 'confidence'
     * (CodeConfidence, already in $r->toArray()) — see this class's own
     * constructor docblock for why the two must never collapse into one.
     *
     * @return array{extraction_confidence: ?float}
     */
    private function extractionArray(CodeResolution $r): array
    {
        return ['extraction_confidence' => $this->extractionConfidence[spl_object_id($r)] ?? null];
    }

    /**
     * §19: 'suggested_correction' — null unless a plausible near-miss was
     * found for this (unresolved, low-extraction-confidence) token. The
     * client shows it as an explicit, declined-by-default control — never a
     * pre-applied rewrite (see AcronymSuggestion's own docblock).
     *
     * @return array{suggested_correction: ?array<string, mixed>}
     */
    private function suggestionArray(CodeResolution $r): array
    {
        return ['suggested_correction' => ($this->suggestions[spl_object_id($r)] ?? null)?->toArray()];
    }
}
