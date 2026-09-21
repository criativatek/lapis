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
 */
readonly class ClassifiedColumn
{
    public function __construct(
        public int $index,
        public string $header,
        public ColumnRole $role,
        public ?SupportMeasureLevel $level = null,
        public bool $alsoFreeText = false,
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
        ];
    }
}
