<?php

namespace App\Domain\Export;

/**
 * A column of the grid that is NOT a domain, offered to the teacher as a place
 * the level could be written.
 *
 * WHY THIS EXISTS AT ALL. Neither of the two real grids audited before this was
 * written names a column for the level: the `.xlsx` has none, and in the `.xls`
 * the column carrying 2/3/4/5 has no header of its own. «The column after the
 * domains» would be a mapping nobody approved, and a grid filled in the wrong
 * column is worse than one not filled at all, because the school would upload
 * it. So the reader reports what is THERE and the teacher says which one it is.
 *
 * `header` is whatever the header row holds for this column — normally nothing,
 * which is precisely why the samples travel: three values off the students'
 * own rows are how a teacher recognises «ah, that is the nível column».
 */
final readonly class InovarTemplateColumn
{
    /**
     * @param  string  $column  the column letter
     * @param  string|null  $header  the header cell's text, null when it has none
     * @param  list<string>  $samples  the first few values found on student rows
     */
    public function __construct(
        public string $column,
        public ?string $header,
        public array $samples,
    ) {}
}
