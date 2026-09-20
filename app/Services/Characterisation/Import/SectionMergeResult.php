<?php

namespace App\Services\Characterisation\Import;

use App\Support\Characterisation\SectionMergeAction;

/**
 * What merging ONE section decided, and what it would produce.
 *
 * `mergedValue` is the value the write path is meant to save — it is what the
 * preview calls "a acrescentar" shown next to "já registado", and it is never
 * itself the currentValue with the incoming text silently dropped: an ADD
 * carries both, joined; an ALREADY_PRESENT or IGNORE carries the current value
 * unchanged, which is what "write nothing new" means when something still has
 * to be persisted.
 */
readonly class SectionMergeResult
{
    public function __construct(
        public string $section,
        public SectionMergeAction $action,
        public ?string $currentValue,
        public ?string $incomingValue,
        public ?string $mergedValue,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'section' => $this->section,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'current_value' => $this->currentValue,
            'incoming_value' => $this->incomingValue,
            'merged_value' => $this->mergedValue,
        ];
    }
}
