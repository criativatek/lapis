<?php

namespace App\Services\Characterisation\Import\Extraction;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

/**
 * Reads the HTML fragment that Word, Excel and Google Sheets all put on the
 * clipboard alongside their plain-text representation — a real `<table>`,
 * complete with `rowspan`/`colspan`, that carries structure the tab-separated
 * text next to it has already lost. When that fragment is present, reading it
 * instead of the plain text is strictly more information for free.
 *
 * THE RAW HTML IS NEVER STORED. It exists only inside this method call: it is
 * parsed, its text is copied into ExtractedCell, and the DOMDocument goes out
 * of scope. Office clipboard HTML routinely carries `mso-*` styling,
 * conditional comments and namespaced junk (`<o:p>`), none of which has any
 * business surviving into a database row about a child.
 *
 * LIBXML_NONET plus suppressed internal errors: this fragment is untrusted
 * input from a clipboard, so it is parsed defensively — no external entity
 * resolution, and a malformed fragment (Word's HTML is never fully valid)
 * does not throw, it degrades to whatever DOMDocument could recover.
 */
class HtmlTableExtractor implements TableExtractor
{
    /** Same ceiling ReadCharacterisationTable enforces after normalisation; refused here too, before the memory is spent building the DOM rows. */
    private const MAX_ROWS = 500;

    private const MAX_COLUMNS = 40;

    public function supports(mixed $source): bool
    {
        return is_string($source) && stripos($source, '<table') !== false;
    }

    /**
     * @return list<ExtractedTable>
     */
    public function extract(mixed $source): array
    {
        if (! is_string($source)) {
            return [];
        }

        $document = new DOMDocument;

        // Wrapped in a minimal document so fragments missing <html>/<body> (the
        // clipboard never sends a full page) still parse predictably, and given
        // an explicit UTF-8 meta tag because loadHTML() otherwise guesses the
        // encoding from the markup and gets accented pt-PT text wrong.
        $wrapped = '<?xml encoding="utf-8"?><html><head><meta charset="utf-8"></head><body>'.$source.'</body></html>';

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML($wrapped, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $tables = [];

        foreach ($this->elements('//table', $xpath, $document) as $tableNode) {
            $extracted = $this->extractTable($tableNode, $xpath);

            if ($extracted !== null) {
                $tables[] = $extracted;
            }
        }

        return $tables;
    }

    /**
     * DOMXPath::query() is typed as returning `DOMNodeList|false` — or, with a
     * context node, `array|DOMNodeList|false` in this project's stubs — so
     * every call site in this class goes through here rather than repeating
     * the same narrowing at each one.
     *
     * @return list<DOMElement>
     */
    private function elements(string $expression, DOMXPath $xpath, DOMNode $context): array
    {
        $result = $xpath->query($expression, $context);

        if (! $result instanceof DOMNodeList) {
            return [];
        }

        $elements = [];

        foreach ($result as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function extractTable(DOMElement $tableNode, DOMXPath $xpath): ?ExtractedTable
    {
        // Child axis (`./tr`), not descendant (`.//tr`): Word/Excel clipboard
        // HTML can nest a table inside a `<td>` (a merged-cell note, a legend
        // rendered as its own mini-table), and `.//tr` would pull that inner
        // table's rows into the outer table's row list. `//table` at the top
        // of extract() already visits the inner table separately — reading it
        // AGAIN here, folded into the outer table, would duplicate it and
        // misattribute its cells to the outer table's columns. A `<tbody>`
        // wrapper is fine either way: `./tr` still reaches rows one level
        // down through `<tbody>` because a `<tbody>` is itself a child of
        // `<table>` and DOMDocument does not insert an implicit one.
        $rowNodes = $this->rowsOf($tableNode, $xpath);

        if ($rowNodes === [] || count($rowNodes) > self::MAX_ROWS) {
            return null;
        }

        $rows = [];
        $rowIndex = 1;

        // Columns an earlier row's rowspan still covers, keyed by column
        // number, holding the last row index that column remains occupied
        // through — see extractRow()'s own comment for why this has to be
        // tracked at all (unlike DocxTableExtractor's w:vMerge, HTML never
        // repeats a continuation marker on the covered row, so the ONLY
        // record of "this column is taken" is what the origin cell declared).
        $occupiedThrough = [];

        foreach ($rowNodes as $rowNode) {
            $cells = $this->extractRow($rowNode, $xpath, $rowIndex, $occupiedThrough);

            if ($cells === null) {
                return null;
            }

            if ($cells !== []) {
                $rows[] = new ExtractedRow($rowIndex, $cells);
                $rowIndex++;
            }
        }

        if ($rows === []) {
            return null;
        }

        return new ExtractedTable(
            rows: $rows,
            sourceType: ExtractedTableSource::PastedHtml,
        );
    }

    /**
     * `<tr>` elements one level below `<table>` OR its `<tbody>`/`<thead>`/
     * `<tfoot>` — the child axis both times, never descendant, so a table
     * nested inside a `<td>` never contributes its rows here (see the
     * caller's comment).
     *
     * @return list<DOMElement>
     */
    private function rowsOf(DOMElement $tableNode, DOMXPath $xpath): array
    {
        $rows = $this->elements('./tr', $xpath, $tableNode);

        foreach ($this->elements('./tbody|./thead|./tfoot', $xpath, $tableNode) as $section) {
            $rows = [...$rows, ...$this->elements('./tr', $xpath, $section)];
        }

        return $rows;
    }

    /**
     * A `<tr>`'s `<td>`/`<th>` children are numbered as if the row started
     * from a blank sheet — no `<tr>` ever repeats a cell an earlier row's
     * `rowspan` already covers, the way a rectangular grid would. Reading
     * markup that way silently shifts every cell after a rowspan one column
     * to the left, for every row underneath it: a "Turma"/"Nº" column merged
     * down three rows moves three students' worth of data one column over,
     * with nothing in the markup itself signalling the mistake. So
     * $occupiedThrough — carried across every row of the table, not just
     * this one — is consulted before each new `<td>` is placed, exactly the
     * way DocxTableExtractor tracks `w:vMerge`, and the column cursor skips
     * past whatever is still spoken for before advancing.
     *
     * Only the child axis (`./td|./th`) is read here too, for the same
     * nested-table reason as rowsOf(): a `<td>` inside this row that itself
     * contains a `<table><tr><td>…` must not have that inner `<td>` counted
     * as one of THIS row's cells.
     *
     * @param  array<int, int>  $occupiedThrough  column => last row index still covered by an earlier rowspan; mutated in place
     * @return list<ExtractedCell>|null null means the row exceeds the column ceiling and the whole table is refused
     */
    private function extractRow(DOMElement $rowNode, DOMXPath $xpath, int $rowIndex, array &$occupiedThrough): ?array
    {
        $cells = [];
        $column = 1;

        foreach ($this->elements('./td|./th', $xpath, $rowNode) as $cellNode) {
            while (($occupiedThrough[$column] ?? 0) >= $rowIndex) {
                $column++;
            }

            $colspan = max(1, (int) ($cellNode->getAttribute('colspan') ?: 1));
            $rowspan = max(1, (int) ($cellNode->getAttribute('rowspan') ?: 1));

            if ($column + $colspan - 1 > self::MAX_COLUMNS) {
                return null;
            }

            $cells[] = new ExtractedCell(
                text: $this->textOf($cellNode),
                row: $rowIndex,
                column: $column,
                colspan: $colspan,
                rowspan: $rowspan,
            );

            if ($rowspan > 1) {
                for ($c = $column; $c < $column + $colspan; $c++) {
                    $occupiedThrough[$c] = $rowIndex + $rowspan - 1;
                }
            }

            $column += $colspan;
        }

        return $cells;
    }

    /**
     * A cell's text, with <br> and block boundaries (<p>, <div>) turned into
     * "\n" rather than lost. Office wraps a cell's paragraphs in <p> or <div>
     * and separates a manual line break with <br> — collapsing either to
     * nothing would run two lines of a multiline observation together into
     * one illegible sentence.
     */
    private function textOf(DOMNode $node): string
    {
        $text = $this->walk($node);

        // Collapse runs of blank lines left behind by nested block elements,
        // and trim the ends — but never collapse a single internal "\n",
        // which is the multiline content this method exists to preserve.
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function walk(DOMNode $node): string
    {
        if ($node->nodeName === 'br') {
            return "\n";
        }

        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }

        // Office namespaces (o:p, mso-*) and script/style content carry no
        // information a cell's text should keep.
        if (in_array($node->nodeName, ['script', 'style'], true) || str_contains($node->nodeName, ':')) {
            return '';
        }

        $text = '';

        foreach ($this->children($node) as $child) {
            $text .= $this->walk($child);
        }

        $blockLevel = in_array($node->nodeName, ['p', 'div', 'tr', 'table'], true);

        return $blockLevel ? $text."\n" : $text;
    }

    /**
     * @return list<DOMNode>
     */
    private function children(DOMNode $node): array
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        return $children;
    }
}
