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
        $rowNodes = $this->elements('.//tr', $xpath, $tableNode);

        if ($rowNodes === [] || count($rowNodes) > self::MAX_ROWS) {
            return null;
        }

        $rows = [];
        $rowIndex = 1;

        foreach ($rowNodes as $rowNode) {
            $cells = $this->extractRow($rowNode, $xpath, $rowIndex);

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
     * @return list<ExtractedCell>|null null means the row exceeds the column ceiling and the whole table is refused
     */
    private function extractRow(DOMElement $rowNode, DOMXPath $xpath, int $rowIndex): ?array
    {
        $cells = [];
        $column = 1;

        foreach ($this->elements('./td|./th', $xpath, $rowNode) as $cellNode) {
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
