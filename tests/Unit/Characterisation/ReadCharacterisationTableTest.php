<?php

namespace Tests\Unit\Characterisation;

use App\Services\Characterisation\Import\ReadCharacterisationTable;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * Three containers, one grid. What matters is that all three arrive at the same
 * shape, and that a file which cannot be read honestly is refused rather than
 * half-read.
 */
class ReadCharacterisationTableTest extends TestCase
{
    private function reader(): ReadCharacterisationTable
    {
        return new ReadCharacterisationTable;
    }

    public function test_it_reads_a_tab_separated_paste(): void
    {
        $grid = $this->reader()->fromPastedText(
            "Nome\tMedidas\tObservações\n".
            "Ana Silva\tMS b) + ACNS\tParticipa\n".
            "Bruno Costa\t\tFalta muito\n"
        );

        $this->assertSame(['Nome', 'Medidas', 'Observações'], $grid->headers);
        $this->assertCount(2, $grid->rows);
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[0], 0));
        $this->assertSame('MS b) + ACNS', $grid->cell($grid->rows[0], 1));
    }

    /**
     * A Portuguese export is as likely to be semicolon-separated as
     * comma-separated, and a name written «Silva, Ana» makes the naive choice
     * actively wrong.
     */
    public function test_it_measures_the_delimiter_rather_than_assuming_a_comma(): void
    {
        $grid = $this->reader()->fromPastedText(
            "Nome;Medidas\n".
            "Silva, Ana;ACNS\n"
        );

        $this->assertSame(['Nome', 'Medidas'], $grid->headers);
        $this->assertSame('Silva, Ana', $grid->cell($grid->rows[0], 0));
    }

    /**
     * School exports carry a printed report above the table. Reading row 1 as
     * the header would shift every student's text up by a row.
     */
    public function test_it_finds_the_header_row_below_a_report_preamble(): void
    {
        $grid = $this->reader()->fromPastedText(
            "Agrupamento de Escolas de Exemplo\n".
            "Ano letivo 2026/2027\n".
            "Nome\tObservações\n".
            "Ana Silva\tParticipa\n"
        );

        $this->assertSame(['Nome', 'Observações'], $grid->headers);
        $this->assertCount(1, $grid->rows);
    }

    public function test_a_row_shorter_than_the_header_is_read_without_error(): void
    {
        $grid = $this->reader()->fromPastedText(
            "Nome\tMedidas\tObservações\n".
            "Ana Silva\tACNS\n"
        );

        $this->assertSame('', $grid->cell($grid->rows[0], 2));
    }

    public function test_an_empty_paste_is_refused(): void
    {
        $this->expectException(UnreadableSpreadsheet::class);

        $this->reader()->fromPastedText('   ');
    }

    public function test_it_reads_a_csv_upload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'carac').'.csv';
        file_put_contents($path, "Nome;Observações\nAna Silva;Participa\n");

        $grid = $this->reader()->fromUploadedFile(
            new UploadedFile($path, 'caracterizacao.csv', 'text/csv', null, true),
        );

        $this->assertSame(['Nome', 'Observações'], $grid->headers);
        $this->assertSame('Ana Silva', $grid->cell($grid->rows[0], 0));

        @unlink($path);
    }

    public function test_it_reads_an_xlsx_upload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'carac').'.xlsx';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Nome', 'Medidas'],
            ['Ana Silva', 'MS b) + ACNS'],
        ]);
        (new XlsxWriter($spreadsheet))->save($path);

        $grid = $this->reader()->fromUploadedFile(
            new UploadedFile($path, 'caracterizacao.xlsx', null, null, true),
        );

        $this->assertSame(['Nome', 'Medidas'], $grid->headers);
        $this->assertSame('MS b) + ACNS', $grid->cell($grid->rows[0], 1));

        @unlink($path);
    }

    /**
     * REGRESSION GUARD (2026-09-20 structural review report): the exact
     * Word-clipboard fixture that surfaced defects 1 and 3 in one pass,
     * driven through the REAL production entry point
     * (`fromPastedHtml()` -> `NormaliseExtractedTable` -> `TableGrid`), not a
     * hand-built ExtractedTable. Pins the WHOLE shape in one test so none of
     * the three defects it exposed — the second header level offered as a
     * student, a `<br>` dropped inside a multiline observation, and a
     * trailing legend miscounted as a grouping caption — can regress on its
     * own without this test noticing.
     */
    public function test_the_word_rtp_fixture_is_fully_and_correctly_classified(): void
    {
        $html = <<<'HTML'
            <table>
              <tr>
                <td rowspan="2">Aluno</td>
                <td rowspan="2">RTP/PEI</td>
                <td colspan="2">Apoios</td>
                <td rowspan="2">Observações</td>
              </tr>
              <tr>
                <td>P</td>
                <td>Ing.</td>
              </tr>
              <tr><td colspan="5"><b>Alunos com RTP</b></td></tr>
              <tr>
                <td>Maria Santos</td>
                <td>RTP</td>
                <td>X</td>
                <td></td>
                <td>MU a) b) e)<br>Necessita de apoio na organizacao.</td>
              </tr>
              <tr>
                <td>Joao Pinto</td>
                <td>PEI</td>
                <td></td>
                <td>X</td>
                <td>MS b) ACNS</td>
              </tr>
              <tr><td colspan="5"><b>Alunos sem RTP</b></td></tr>
              <tr>
                <td>Leonor Machado</td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
              </tr>
              <tr><td colspan="5">X (continua) - N (novo)</td></tr>
            </table>
            HTML;

        $reader = $this->reader();
        $grid = $reader->fromPastedHtml($html);

        // Defect 1: the merged "Apoios" header joins with its two sub-labels
        // — row 1 (the second header level) never reaches the data grid.
        $this->assertSame(
            ['Aluno', 'RTP/PEI', 'Apoios P', 'Apoios Ing.', 'Observações'],
            $grid->headers,
        );

        // Maria, Joao and Leonor survive as data — Leonor included, despite
        // every one of her columns but the name being empty.
        $this->assertCount(3, $grid->rows);
        $this->assertSame('Maria Santos', $grid->cell($grid->rows[0], 0));
        $this->assertSame('Joao Pinto', $grid->cell($grid->rows[1], 0));
        $this->assertSame('Leonor Machado', $grid->cell($grid->rows[2], 0));

        // The <br> inside Maria's observation survives as a real newline,
        // not fused into one word.
        $this->assertSame(
            "MU a) b) e)\nNecessita de apoio na organizacao.",
            $grid->cell($grid->rows[0], 4),
        );

        // Full classification, row by row, in the structural review.
        $kinds = [];

        foreach ($reader->lastStructuralRows() as $row) {
            $kinds[$row['number']] = $row['kind'];
        }

        $this->assertSame('header', $kinds[1]); // Aluno | RTP/PEI | Apoios | Apoios | Observações
        $this->assertSame('header', $kinds[2]); // Aluno | RTP/PEI | P | Ing. | Observações (never offered as a student)
        $this->assertSame('group', $kinds[3]);  // Alunos com RTP
        $this->assertSame('data', $kinds[4]);   // Maria Santos
        $this->assertSame('data', $kinds[5]);   // Joao Pinto
        $this->assertSame('group', $kinds[6]);  // Alunos sem RTP
        $this->assertSame('data', $kinds[7]);   // Leonor Machado
        $this->assertSame('legend', $kinds[8]); // X (continua) - N (novo) — defect 3: a legend, not a group

        // Defect 3's warning count: one group caption, one legend — never
        // both counted as "grupo".
        $this->assertContains('2 linhas de agrupamento não foram importadas como alunos.', $reader->lastWarnings());
        $this->assertContains('1 linha de legenda foi ignorada.', $reader->lastWarnings());
    }

    /** PDF is out of scope by decision, not by oversight, and says so. */
    public function test_an_unsupported_format_is_refused_with_an_actionable_message(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'carac').'.pdf';
        file_put_contents($path, '%PDF-1.4 not really a pdf');

        $this->expectException(UnreadableSpreadsheet::class);

        try {
            $this->reader()->fromUploadedFile(
                new UploadedFile($path, 'caracterizacao.pdf', 'application/pdf', null, true),
            );
        } finally {
            @unlink($path);
        }
    }
}
