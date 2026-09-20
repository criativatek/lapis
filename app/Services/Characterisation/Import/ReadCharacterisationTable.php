<?php

namespace App\Services\Characterisation\Import;

use App\Services\Characterisation\Import\Extraction\DocxTableExtractor;
use App\Services\Characterisation\Import\Extraction\ExtractedTable;
use App\Services\Characterisation\Import\Extraction\HtmlTableExtractor;
use App\Services\Characterisation\Import\Extraction\NormaliseExtractedTable;
use App\Services\Characterisation\Import\Extraction\SpreadsheetTableExtractor;
use App\Services\Characterisation\Import\Extraction\TsvTableExtractor;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use Illuminate\Http\UploadedFile;

/**
 * Turns whatever a teacher hands us into one TableGrid.
 *
 * Pasted text, pasted HTML (from Word/Excel/Google Sheets clipboards), CSV,
 * XLSX and .docx tables — a live image paste and an OCR'd upload would need a
 * recognition subsystem rather than a parser, and the architecture does not
 * need those to exist yet in order to be ready for them: everything downstream
 * reads a TableGrid, so a future source arrives by producing an ExtractedTable
 * and calling fromExtractedTable(), exactly like every source above already
 * does.
 *
 * FILES ARE READ BY THE READERS THAT ALREADY EXIST. SpreadsheetTableExtractor
 * wraps CsvTabularReader/XlsxTabularReader, written for the correction-grid
 * importer, which already refuse zip bombs (SpreadsheetZipSafety), verify the
 * encoding instead of guessing it, measure the delimiter rather than assuming
 * a comma, and decline to trust a formula's cached value. Calling
 * PhpSpreadsheet directly from here would mean a second, weaker door into the
 * same building.
 *
 * EVERY SOURCE GOES THROUGH AN EXTRACTOR, THEN THROUGH
 * NormaliseExtractedTable, ALWAYS. That used to be true only of the pasted-
 * HTML and .docx paths — fromPastedText() and fromUploadedFile() had their
 * own, older private toGrid() that never touched an extractor or the
 * normaliser at all, which meant a plain CSV/XLSX upload or a plain-text
 * paste got NO merge expansion, group-row detection, legend detection,
 * multi-level header joining, or warnings, while the exact same table pasted
 * as HTML got all of it. A teacher choosing "colar como texto" over "colar
 * como HTML" — or simply uploading the file instead of copy/pasting it —
 * should never change whether a caption row survives into the class's
 * characterisation. Every entry point below now dispatches to the matching
 * TableExtractor and lands on fromExtractedTable(), which is the one place
 * NormaliseExtractedTable is ever called from.
 *
 * Nothing in this class writes to disk. The uploaded file is read inside the
 * request that carried it and then forgotten: no staging folder to prune, and
 * no second copy of thirty children's names sitting in storage waiting for
 * someone to remember it.
 */
class ReadCharacterisationTable
{
    public function __construct(
        private readonly TsvTableExtractor $tsv = new TsvTableExtractor,
        private readonly SpreadsheetTableExtractor $spreadsheet = new SpreadsheetTableExtractor,
        private readonly HtmlTableExtractor $html = new HtmlTableExtractor,
        private readonly DocxTableExtractor $docx = new DocxTableExtractor,
        private readonly NormaliseExtractedTable $normaliser = new NormaliseExtractedTable,
    ) {}

    /**
     * The warnings from the most recent normalise() call — see
     * NormalisedTable's own docblock for why they arrive bundled with the
     * grid from that one call rather than read back separately afterwards.
     * Kept here, on THIS class (built fresh per import — see the class
     * docblock), rather than reintroducing the same statefulness on the
     * normaliser itself.
     *
     * @var list<string>
     */
    private array $lastWarnings = [];

    /**
     * Pasted plain text — TSV, comma- or semicolon-separated, whatever
     * SniffDelimiter measures it to be — routed through TsvTableExtractor
     * rather than parsed here directly, so a caption row or a multi-level
     * header pasted as plain text is classified exactly the same way the
     * same table would be if it had arrived as pasted HTML instead.
     */
    public function fromPastedText(string $text): TableGrid
    {
        if (trim($text) === '') {
            throw new UnreadableSpreadsheet(__('Não foi possível ler nenhuma linha do texto colado. Copie a tabela incluindo a linha dos títulos.'));
        }

        $tables = $this->tsv->extract($text);

        if ($tables === []) {
            throw new UnreadableSpreadsheet(__('Não foi possível ler nenhuma linha do texto colado. Copie a tabela incluindo a linha dos títulos.'));
        }

        return $this->fromExtractedTable($tables[0]);
    }

    /**
     * A CSV or XLSX upload, routed through SpreadsheetTableExtractor — which
     * itself wraps CsvTabularReader/XlsxTabularReader (see the class
     * docblock) — and then through the same fromExtractedTable() pipeline
     * every other source uses. CSV carries no merge information, so
     * NormaliseExtractedTable's structural group-row test never fires for
     * it; the content-based fallback still does, exactly as it already does
     * for a plain-text paste.
     */
    public function fromUploadedFile(UploadedFile $file): TableGrid
    {
        if (! $this->spreadsheet->supports($file)) {
            throw new UnreadableSpreadsheet(
                __('Só é possível importar ficheiros CSV ou Excel (.xlsx). Guarde a folha num destes formatos e tente de novo.'),
            );
        }

        $tables = $this->spreadsheet->extract($file);

        if ($tables === []) {
            throw new UnreadableSpreadsheet(__('A tabela não tem linhas com conteúdo.'));
        }

        return $this->fromExtractedTable($tables[0]);
    }

    /**
     * A clipboard HTML fragment — Word, Excel and Google Sheets all put a real
     * `<table>` on the clipboard alongside their plain text, and reading it
     * instead of the plain text is strictly more information for free: a
     * merged cell, a multiline cell, a multi-level header are all visible in
     * the markup and already lost in the tab-separated text next to it.
     *
     * Routed through HtmlTableExtractor + NormaliseExtractedTable rather than
     * parsed here, because turning merged cells into a rectangle and turning
     * rows into Header/Group/Legend/Data is exactly what fromExtractedTable's
     * pipeline already does — a second copy of that classification, tuned
     * only for this entry point, is exactly the kind of drift this feature's
     * architecture (everything downstream reads one shape) exists to avoid.
     */
    public function fromPastedHtml(string $html): TableGrid
    {
        $tables = $this->html->extract($html);

        if ($tables === []) {
            throw new UnreadableSpreadsheet(__('Não foi possível reconhecer nenhuma tabela no conteúdo colado.'));
        }

        return $this->fromExtractedTable($tables[0]);
    }

    /**
     * The shared landing point for every ExtractedTable, whatever produced
     * it — pasted HTML, a spreadsheet, one table out of several read from a
     * .docx. NormaliseExtractedTable is what actually expands merges and
     * classifies rows; this method exists so callers reach it through the
     * same class that already enforces MAX_ROWS/MAX_COLUMNS elsewhere.
     */
    public function fromExtractedTable(ExtractedTable $table): TableGrid
    {
        $normalised = $this->normaliser->normalise($table);
        $this->lastWarnings = $normalised->warnings;

        return $normalised->grid;
    }

    /**
     * Whatever NormaliseExtractedTable had to leave out of the most recent
     * call to any `from*()` method — a caption or legend row dropped, say.
     * Every source now goes through fromExtractedTable(), so this is
     * populated for pasted text and an uploaded CSV/XLSX exactly as it
     * already was for pasted HTML and a .docx.
     *
     * @return list<string>
     */
    public function lastWarnings(): array
    {
        return $this->lastWarnings;
    }

    /**
     * A .docx can hold several tables — a class characterisation and a
     * legend table, say — so this returns all of them rather than guessing
     * which one the teacher meant. The controller decides what to do with
     * more than one; this method's job stops at reading them.
     *
     * @return list<ExtractedTable>
     */
    public function tablesFromUploadedFile(UploadedFile $file): array
    {
        return $this->docx->extract($file);
    }
}
