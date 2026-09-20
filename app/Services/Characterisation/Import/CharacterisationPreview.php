<?php

namespace App\Services\Characterisation\Import;

/**
 * The whole proposal, ready to be shown and not to be saved.
 *
 * It is returned to the browser and never persisted. Keeping a server-side copy
 * would mean a second store of thirty children's pedagogical text, living for
 * as long as somebody left the tab open — so the preview lives in the page, and
 * confirmation sends back decisions rather than a token pointing at a file.
 */
readonly class CharacterisationPreview
{
    /**
     * @param  list<ClassifiedColumn>  $columns
     * @param  list<PreviewRow>  $rows
     * @param  bool  $identifiable  Whether any column identifies a student at all.
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public bool $identifiable,
    ) {}

    /**
     * @return array<string, int>
     */
    public function tally(): array
    {
        $tally = [];

        foreach (RowMatchState::cases() as $state) {
            $tally[$state->value] = 0;
        }

        foreach ($this->rows as $row) {
            $tally[$row->match->state->value]++;
        }

        return $tally;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'columns' => array_map(fn (ClassifiedColumn $column) => $column->toArray(), $this->columns),
            'rows' => array_map(fn (PreviewRow $row) => $row->toArray(), $this->rows),
            'identifiable' => $this->identifiable,
            'tally' => $this->tally(),
        ];
    }
}
