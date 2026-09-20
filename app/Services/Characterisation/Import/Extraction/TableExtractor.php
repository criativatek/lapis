<?php

namespace App\Services\Characterisation\Import\Extraction;

/**
 * One way of turning a source into ExtractedTable(s).
 *
 * `extract` returns a LIST because a single .docx can hold several tables —
 * a class characterisation and a legend table, say — and refusing to notice
 * the second one would silently drop it rather than surface it to the
 * teacher as a choice.
 */
interface TableExtractor
{
    public function supports(mixed $source): bool;

    /**
     * @return list<ExtractedTable>
     */
    public function extract(mixed $source): array;
}
