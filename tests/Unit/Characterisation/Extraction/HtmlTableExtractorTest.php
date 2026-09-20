<?php

namespace Tests\Unit\Characterisation\Extraction;

use App\Services\Characterisation\Import\Extraction\ExtractedTableSource;
use App\Services\Characterisation\Import\Extraction\HtmlTableExtractor;
use Tests\TestCase;

/**
 * Clipboard HTML from the three sources this feature is meant to read: Word,
 * Excel and Google Sheets. Each application marks up a pasted table a little
 * differently (Word wraps text in <p>, Excel and Sheets often don't), so each
 * gets its own fixture rather than trusting one shape to stand for all three.
 */
class HtmlTableExtractorTest extends TestCase
{
    private function extractor(): HtmlTableExtractor
    {
        return new HtmlTableExtractor;
    }

    public function test_it_reads_word_clipboard_html_with_merged_and_multiline_cells(): void
    {
        $html = <<<'HTML'
            <html xmlns:o="urn:schemas-microsoft-com:office:office">
            <body>
            <table>
              <tr>
                <td colspan="2"><p>Nome</p></td>
                <td><p>Observações</p></td>
              </tr>
              <tr>
                <td rowspan="2"><p>Turma A</p></td>
                <td><p>Ana Silva</p></td>
                <td><p>Participa.<o:p></o:p></p><p>Falta pouco.</p></td>
              </tr>
              <tr>
                <td><p>Bruno Costa</p></td>
                <td><p>Linha um<br>linha dois</p></td>
              </tr>
            </table>
            </body>
            </html>
            HTML;

        $tables = $this->extractor()->extract($html);

        $this->assertCount(1, $tables);
        $table = $tables[0];
        $this->assertSame(ExtractedTableSource::PastedHtml, $table->sourceType);

        // Row 1: header, colspan 2 repeats across columns 1-2.
        $headerCells = $table->rows[0]->cells;
        $this->assertSame('Nome', $headerCells[0]->text);
        $this->assertSame(2, $headerCells[0]->colspan);

        // Row 2's first cell has rowspan 2, and multiline text joined with "\n".
        $secondRowCells = $table->rows[1]->cells;
        $this->assertSame('Turma A', $secondRowCells[0]->text);
        $this->assertSame(2, $secondRowCells[0]->rowspan);
        $this->assertStringContainsString("Participa.\nFalta pouco.", $secondRowCells[2]->text);

        // <br> becomes a newline boundary too.
        $thirdRowCells = $table->rows[2]->cells;
        $this->assertStringContainsString("Linha um\nlinha dois", $thirdRowCells[1]->text);
    }

    public function test_it_reads_excel_clipboard_html(): void
    {
        $html = <<<'HTML'
            <table border="0" cellpadding="0" cellspacing="0" width="200" style="mso-something">
              <tr>
                <td class="xl65">Nome</td>
                <td class="xl65">Medidas</td>
              </tr>
              <tr>
                <td>Ana Silva</td>
                <td>MS b) + ACNS</td>
              </tr>
            </table>
            HTML;

        $tables = $this->extractor()->extract($html);

        $this->assertCount(1, $tables);
        $this->assertSame('Nome', $tables[0]->rows[0]->cells[0]->text);
        $this->assertSame('MS b) + ACNS', $tables[0]->rows[1]->cells[1]->text);
    }

    public function test_it_reads_google_sheets_clipboard_html(): void
    {
        $html = <<<'HTML'
            <google-sheets-html-origin>
            <table xmlns="http://www.w3.org/1999/xhtml" cellspacing="0" cellpadding="0">
              <tbody>
                <tr><td>Nome</td><td>Necessidades</td></tr>
                <tr><td>Ana Silva</td><td>Leitura</td></tr>
              </tbody>
            </table>
            HTML;

        $tables = $this->extractor()->extract($html);

        $this->assertCount(1, $tables);
        $this->assertSame('Necessidades', $tables[0]->rows[0]->cells[1]->text);
        $this->assertSame('Leitura', $tables[0]->rows[1]->cells[1]->text);
    }

    public function test_it_never_matches_content_without_a_table(): void
    {
        $this->assertFalse($this->extractor()->supports('<p>Sem tabela aqui.</p>'));
        $this->assertSame([], $this->extractor()->extract('<p>Sem tabela aqui.</p>'));
    }
}
