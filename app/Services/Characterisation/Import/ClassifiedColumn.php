<?php

namespace App\Services\Characterisation\Import;

use App\Models\SupportMeasureLevel;

/**
 * One column, after classification.
 *
 * `level` is the quiet hero of the import. When a header reads "Medidas
 * seletivas", every bare "b)" underneath it inherits that level and stops being
 * meaningless. When the header says only "Medidas", the same "b)" stays
 * ambiguous — and the difference between those two outcomes is this one
 * nullable property, not a heuristic somewhere downstream.
 *
 * `alsoFreeText` exists for the one header real schools write that means TWO
 * things at once: «Outras medidas/recursos / Observações» is legally a
 * measures column (it says "medidas") AND, in the same breath, the school's
 * catch-all free-text column (it says "observações" too). Classifying it as
 * ONLY Measures — what a single-role enum forces without this flag — used to
 * hand a teacher's prose («RTP (12/2020); Redução de turma») to
 * LegalCodeResolver, which read it as a string of unrecognised codes instead
 * of the narrative it is. This column now feeds BOTH destinations: its
 * measure-shaped tokens still resolve as measures, and its text ALSO reaches
 * CharacterisationSection::Summary — see ClassifyColumns::roleFor() for the
 * detection and BuildCharacterisationPreview::sectionsFor() for the second
 * destination.
 *
 * `inferredFromContent` exists for the ONE column real schools export that
 * ClassifyColumns cannot name from its header at all: a student-name column
 * with no printed title in either header level (a blank cell above a column
 * of names, common on a printed form). Classified by header text alone, that
 * column stayed Unknown forever, no row ever matched a student, and the
 * preview reported "0 de 0" one stage later than the bug that name suggests —
 * not because no header was found, but because the one column the whole
 * import exists to read had no label to find. See
 * ClassifyColumns::inferNameColumnFromContent() for the last-resort fallback
 * this flag marks, and NormaliseExtractedTable::normalise() for the warning
 * it is never allowed to pass through silently.
 */
readonly class ClassifiedColumn
{
    public function __construct(
        public int $index,
        public string $header,
        public ColumnRole $role,
        public ?SupportMeasureLevel $level = null,
        public bool $alsoFreeText = false,
        public bool $inferredFromContent = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'header' => $this->header,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'level' => $this->level?->value,
            'level_label' => $this->level?->label(),
            'also_free_text' => $this->alsoFreeText,
            'inferred_from_content' => $this->inferredFromContent,
        ];
    }
}
