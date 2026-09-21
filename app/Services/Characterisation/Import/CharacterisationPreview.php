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
     * @param  int  $dataRowCount  Every row the grid carried below the header — including
     *                             the ones that never reach $rows. This is what makes "0 de
     *                             0" honest instead of ambiguous: it tells the teacher
     *                             whether the table had 0 rows, or had 27 that none of them
     *                             survived to be shown.
     * @param  int  $footerRowCount  The subset of $dataRowCount skipped as a footer/total/
     *                               blank line (identifies nobody: no name, no process
     *                               number).
     * @param  bool  $hasRecognisedContentColumn  Whether ClassifyColumns recognised AT LEAST
     *                                            ONE column that can carry content (a
     *                                            characterisation section, a measure or a
     *                                            resource). False here is a structural
     *                                            failure of the HEADER, not a fact about any
     *                                            one row — every row looks empty for the same
     *                                            reason, and that reason must be named rather
     *                                            than presented as "nothing was ready to
     *                                            import".
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public bool $identifiable,
        public int $dataRowCount = 0,
        public int $footerRowCount = 0,
        public bool $hasRecognisedContentColumn = true,
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
            'data_row_count' => $this->dataRowCount,
            'footer_row_count' => $this->footerRowCount,
            'has_recognised_content_column' => $this->hasRecognisedContentColumn,
        ];
    }
}
