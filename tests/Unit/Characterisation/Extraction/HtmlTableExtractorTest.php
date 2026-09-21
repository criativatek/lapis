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
        $this->assertSame(1, $secondRowCells[0]->column);
        $this->assertSame(2, $secondRowCells[0]->rowspan);
        $this->assertStringContainsString("Participa.\nFalta pouco.", $secondRowCells[2]->text);

        // Row 3 has only two <td>s of its own ("Bruno Costa" and the
        // multiline observation) because "Turma A"'s rowspan from row 2 still
        // covers column 1 here. Antes desta correção este teste afirmava o
        // bug: sem seguir a ocupação do rowspan, "Bruno Costa" ficava na
        // coluna 1 (a coluna da turma) e a observação na coluna 2, deslocando
        // toda a linha uma coluna para a esquerda. As colunas reais são 2 e 3.
        $thirdRowCells = $table->rows[2]->cells;
        $this->assertSame('Bruno Costa', $thirdRowCells[0]->text);
        $this->assertSame(2, $thirdRowCells[0]->column);
        $this->assertSame(3, $thirdRowCells[1]->column);
        $this->assertStringContainsString("Linha um\nlinha dois", $thirdRowCells[1]->text);
    }

    public function test_it_advances_columns_past_a_rowspan_covering_several_rows(): void
    {
        // A "Turma"/"Grupo"/"Nº"-style first column merged down across every
        // row of the table — the shape a school export uses for "all these
        // students share one class" — must never cause a student row to slip
        // into the wrong column just because it has fewer <td>s than the
        // header row.
        $html = <<<'HTML'
            <table>
              <tr><td>Turma</td><td>Nome</td><td>Notas</td></tr>
              <tr><td rowspan="3">7ºA</td><td>Ana Silva</td><td>Boa</td></tr>
              <tr><td>Bruno Costa</td><td>Regular</td></tr>
              <tr><td>Carla Dias</td><td>Boa</td></tr>
            </table>
            HTML;

        $tables = $this->extractor()->extract($html);
        $table = $tables[0];

        // Row 1 (index 2) carries "7ºA" itself, the origin of the rowspan, so
        // its name/notes cells are at [1]/[2]; rows 2-3 (indices 3-4) never
        // repeat "7ºA" at all, so their name/notes cells are at [0]/[1] — but
        // in both cases the COLUMN NUMBER, not the array index, must land on
        // 2/3 either way.
        $first = $table->rows[1]->cells;
        $this->assertSame(2, $first[1]->column);
        $this->assertSame(3, $first[2]->column);
        $this->assertSame('Ana Silva', $first[1]->text);

        foreach ([2, 3] as $rowIndex) {
            $row = $table->rows[$rowIndex]->cells;
            $this->assertSame(2, $row[0]->column, "linha {$rowIndex}: nome deve ficar na coluna 2");
            $this->assertSame(3, $row[1]->column, "linha {$rowIndex}: notas deve ficar na coluna 3");
        }

        $this->assertSame('Bruno Costa', $table->rows[2]->cells[0]->text);
        $this->assertSame('Carla Dias', $table->rows[3]->cells[0]->text);
    }

    public function test_it_does_not_duplicate_rows_from_a_table_nested_inside_a_cell(): void
    {
        // Word clipboard markup occasionally nests a whole table inside one
        // <td> (a note, a mini legend). The descendant axis (`.//tr`) would
        // pull the inner table's rows into the outer table's row list AND
        // `//table` at the top level would extract the inner table again on
        // its own — duplicating and misattributing its cells. The child axis
        // must keep the outer table's own two rows exactly two rows.
        $html = <<<'HTML'
            <table>
              <tr><td>Nome</td><td>Notas</td></tr>
              <tr>
                <td>Ana Silva</td>
                <td>
                  <table><tr><td>Legenda interna</td></tr></table>
                  Boa
                </td>
              </tr>
            </table>
            HTML;

        $tables = $this->extractor()->extract($html);

        $outer = $tables[0];
        $this->assertCount(2, $outer->rows);
        $this->assertSame('Ana Silva', $outer->rows[1]->cells[0]->text);
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

    /**
     * Regression guard for a false alarm, not a real defect: a structural
     * review of the live app once showed this cell fused into
     * "MU a) b) e)Necessita de apoio na organizacao." with no separator at
     * all — but that reading came from a single-line `<input>`'s `.value`,
     * which cannot hold or display "\n" in the first place (see the §39
     * structural-step textarea fix for the actual bug that caused). This
     * pins what HtmlTableExtractor itself returns for the exact cell from
     * that report, straight from the DOM, with no UI layer in between: the
     * "\n" IS there.
     */
    public function test_it_separates_a_br_between_two_p_wrapped_fragments_with_a_newline(): void
    {
        $html = <<<'HTML'
            <table>
              <tr>
                <td><p>MU a) b) e)</p><br><p>Necessita de apoio na organizacao.</p></td>
              </tr>
            </table>
            HTML;

        $tables = $this->extractor()->extract($html);

        // ONE newline, not two: a cell never carries a blank line. The `<br>`
        // and the two paragraph boundaries are the same separation stated
        // twice, and collapsing the run is what makes this deterministic
        // across libxml builds — see textOf(). This assertion read "\n\n"
        // until CI on Linux disagreed with the Windows machine that wrote it.
        $this->assertSame("MU a) b) e)\nNecessita de apoio na organizacao.", $tables[0]->rows[0]->cells[0]->text);
    }

    public function test_it_never_matches_content_without_a_table(): void
    {
        $this->assertFalse($this->extractor()->supports('<p>Sem tabela aqui.</p>'));
        $this->assertSame([], $this->extractor()->extract('<p>Sem tabela aqui.</p>'));
    }
}
