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
