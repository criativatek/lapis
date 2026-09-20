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
 */
readonly class ClassifiedColumn
{
    public function __construct(
        public int $index,
        public string $header,
        public ColumnRole $role,
        public ?SupportMeasureLevel $level = null,
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
        ];
    }
}
