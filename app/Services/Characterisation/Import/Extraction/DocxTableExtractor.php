<?php

namespace App\Services\Characterisation\Import\Extraction;

use App\Services\Import\Tabular\UnreadableSpreadsheet;
use App\Support\Import\SpreadsheetZipSafety;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Reads `word/document.xml` out of a .docx directly, rather than through
 * PhpWord's reader.
 *
 * phpoffice/phpword ^1.4 IS already a dependency (the correction-grid
 * importer's Word2007 reader pulls it in), but it was built to reconstruct a
 * WHOLE document — sections, styles, headers — for round-tripping, and its
 * table reader collapses `w:vMerge`'s "this cell continues the one above" into
 * whatever PhpWord's own row/cell model happens to do with it, which is not
 * documented and was not something this importer could depend on without a
 * much larger investigation than a table extractor justifies. Reading the XML
 * ourselves means `w:gridSpan` and `w:vMerge` are exactly the two attributes
 * we look for, and nothing else in the document (styles, headers, footers,
 * embedded objects) is ever touched.
 *
 * VALIDATION BEFORE TRUST. A .docx extension proves nothing: the file must
 * open as a zip, must contain `[Content_Types].xml` and `word/document.xml`,
 * and must pass the same zip-bomb defences SpreadsheetZipSafety already
 * applies to .xlsx uploads (entry count, uncompressed size, compression
 * ratio) — reused via SpreadsheetZipSafety::isSafe(), which is file-format
 * agnostic beyond the .xlsx-specific macro/external-link prefixes it also
 * checks (harmless no-ops against a .docx's directory names). A .docx-specific
 * macro path (`word/vbaProject.bin`) is refused here for the same reason
 * those .xlsx prefixes are refused there: a macro is an instruction to run
 * something when the file is opened, and its mere presence disqualifies the
 * package.
 */
class DocxTableExtractor implements TableExtractor
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_TABLES = 20;

    private const MAX_ROWS = 500;

    private const MAX_COLUMNS = 40;

    private const MAX_CELLS = 20000;

    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function __construct(
        private readonly SpreadsheetZipSafety $zipSafety = new SpreadsheetZipSafety,
    ) {}

    public function supports(mixed $source): bool
    {
        if (! $source instanceof UploadedFile) {
            return false;
        }

        $extension = strtolower((string) pathinfo($source->getClientOriginalName(), PATHINFO_EXTENSION));

        return $extension === 'docx' && $this->looksLikeOoxml($source->getRealPath());
    }

    /**
     * @return list<ExtractedTable>
     */
    public function extract(mixed $source): array
    {
        if (! $source instanceof UploadedFile) {
            return [];
        }

        $path = $source->getRealPath();

        if ($path === false || filesize($path) === false) {
            throw new UnreadableSpreadsheet(__('Não foi possível ler o ficheiro Word.'));
        }

        if (filesize($path) > self::MAX_BYTES) {
            throw new UnreadableSpreadsheet(__('O ficheiro Word é demasiado grande para ser lido com segurança.'));
        }

        if (! $this->looksLikeOoxml($path)) {
            throw new UnreadableSpreadsheet(__('Este ficheiro não é um documento Word (.docx) válido.'));
        }

        if (! $this->zipSafety->isSafe($path) || $this->hasMacro($path)) {
            throw new UnreadableSpreadsheet(__('Este documento Word não pode ser aberto em segurança. Documentos com macros não são suportados.'));
        }

        $documentXml = $this->documentXml($path);

        if ($documentXml === null) {
            throw new UnreadableSpreadsheet(__('Não foi possível ler o conteúdo deste documento Word.'));
        }

        $tables = $this->tablesFromXml($documentXml, $source->getClientOriginalName());

        if ($tables === []) {
            throw new UnreadableSpreadsheet(__('Não foi encontrada nenhuma tabela neste documento Word.'));
        }

        if (count($tables) > self::MAX_TABLES) {
            throw new UnreadableSpreadsheet(__('Este documento Word tem mais do que :count tabelas.', ['count' => self::MAX_TABLES]));
        }

        return $tables;
    }

    /**
     * A .docx is a zip whose first four bytes are the local-file-header magic
     * number, so this catches a renamed .doc or .rtf before a ZipArchive is
     * even opened on it.
     */
    private function looksLikeOoxml(string|false $path): bool
    {
        if ($path === false) {
            return false;
        }

        $header = @file_get_contents($path, false, null, 0, 4);

        if ($header !== "PK\x03\x04") {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            return $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName('word/document.xml') !== false;
        } finally {
            $zip->close();
        }
    }

    private function hasMacro(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return true;
        }

        try {
            return $zip->locateName('word/vbaProject.bin') !== false;
        } finally {
            $zip->close();
        }
    }

    private function documentXml(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $xml = $zip->getFromName('word/document.xml');

            return is_string($xml) && $xml !== '' ? $xml : null;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<ExtractedTable>
     */
    private function tablesFromXml(string $documentXml, string $filename): array
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET: this content came from inside an uploaded zip, not
            // the network, but the same defensive posture as HtmlTableExtractor
            // applies — no external entity resolution, ever.
            $loaded = $document->loadXML($documentXml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NAMESPACE);

        $tables = [];

        foreach ($this->elements('//w:tbl', $xpath, $document) as $tableNode) {
            $extracted = $this->extractTable($tableNode, $xpath, $filename);

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
     * the same "is it really a node list of elements" narrowing six times.
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

    private function firstElement(string $expression, DOMXPath $xpath, DOMNode $context): ?DOMElement
    {
        return $this->elements($expression, $xpath, $context)[0] ?? null;
    }

    private function extractTable(DOMElement $tableNode, DOMXPath $xpath, string $filename): ?ExtractedTable
    {
        $rowNodes = $this->elements('./w:tr', $xpath, $tableNode);

        if ($rowNodes === [] || count($rowNodes) > self::MAX_ROWS) {
            return null;
        }

        $rows = [];
        $cellCount = 0;
        /** @var array<int, array{row: int, text: string}> $verticalMerges keyed by column */
        $verticalMerges = [];

        $rowIndex = 1;

        foreach ($rowNodes as $rowNode) {
            $cells = [];
            $column = 1;

            foreach ($this->elements('./w:tc', $xpath, $rowNode) as $cellNode) {
                $properties = $this->firstElement('./w:tcPr', $xpath, $cellNode);
                $gridSpan = $this->gridSpan($properties, $xpath);
                $vMerge = $this->vMergeState($properties, $xpath);

                if ($column + $gridSpan - 1 > self::MAX_COLUMNS) {
                    return null;
                }

                $text = $this->cellText($cellNode, $xpath);

                if ($vMerge === 'continue' && isset($verticalMerges[$column])) {
                    // The continuation cell inherits the text of the cell it
                    // continues, and the origin cell is the one that carries
                    // the rowspan — that origin cell was already appended to
                    // $rows on an earlier iteration, so it is patched in place.
                    $this->growRowspan($rows, $verticalMerges[$column]['row'], $column);
                } else {
                    $cells[] = new ExtractedCell(
                        text: $text,
                        row: $rowIndex,
                        column: $column,
                        colspan: $gridSpan,
                    );

                    $cellCount++;

                    if ($vMerge === 'start') {
                        $verticalMerges[$column] = ['row' => $rowIndex, 'text' => $text];
                    } else {
                        unset($verticalMerges[$column]);
                    }

                    if ($cellCount > self::MAX_CELLS) {
                        return null;
                    }
                }

                $column += $gridSpan;
            }

            if ($cells !== []) {
                $rows[$rowIndex] = new ExtractedRow($rowIndex, $cells);
            }

            $rowIndex++;
        }

        if ($rows === []) {
            return null;
        }

        return new ExtractedTable(
            rows: array_values($rows),
            sourceType: ExtractedTableSource::Docx,
            sourceFilename: $filename,
        );
    }

    /**
     * @param  array<int, ExtractedRow>  $rows
     */
    private function growRowspan(array &$rows, int $originRowIndex, int $column): void
    {
        $row = $rows[$originRowIndex] ?? null;

        if ($row === null) {
            return;
        }

        $cells = array_map(
            fn (ExtractedCell $cell) => $cell->column === $column
                ? new ExtractedCell($cell->text, $cell->row, $cell->column, $cell->colspan, $cell->rowspan + 1)
                : $cell,
            $row->cells,
        );

        $rows[$originRowIndex] = new ExtractedRow($row->index, $cells, $row->kind);
    }

    private function gridSpan(?DOMElement $properties, DOMXPath $xpath): int
    {
        if ($properties === null) {
            return 1;
        }

        $node = $this->firstElement('./w:gridSpan', $xpath, $properties);

        if ($node === null) {
            return 1;
        }

        $value = (int) $node->getAttributeNS(self::WORD_NAMESPACE, 'val');

        return $value > 0 ? $value : 1;
    }

    /**
     * "start" begins a vertically-merged group, "continue" extends the one
     * above, and null means the cell is not merged at all.
     */
    private function vMergeState(?DOMElement $properties, DOMXPath $xpath): ?string
    {
        if ($properties === null) {
            return null;
        }

        $node = $this->firstElement('./w:vMerge', $xpath, $properties);

        if ($node === null) {
            return null;
        }

        $value = $node->getAttributeNS(self::WORD_NAMESPACE, 'val');

        // A w:vMerge with no val attribute means "continue" — that is how
        // OOXML itself encodes it; only an explicit val="restart" starts a
        // new merge.
        return $value === 'restart' ? 'start' : 'continue';
    }

    /**
     * Every w:p paragraph in a cell joined with "\n", and every fragmented
     * w:r run inside a paragraph joined without a separator — a run break is
     * a formatting artefact (a bold word starting a new run), not a word
     * boundary.
     */
    private function cellText(DOMElement $cellNode, DOMXPath $xpath): string
    {
        $paragraphs = [];

        foreach ($this->elements('./w:p', $xpath, $cellNode) as $paragraphNode) {
            $text = '';

            foreach ($this->elements('.//w:t', $xpath, $paragraphNode) as $textNode) {
                $text .= $textNode->textContent;
            }

            $paragraphs[] = $text;
        }

        return trim(implode("\n", $paragraphs));
    }
}
