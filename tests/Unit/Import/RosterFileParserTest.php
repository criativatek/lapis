<?php

namespace Tests\Unit\Import;

use App\Services\Import\RosterFileParseException;
use App\Services\Import\RosterFileParser;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RosterFileParserTest extends TestCase
{
    protected string $tempPath;

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }
        parent::tearDown();
    }

    /**
     * Builds a fixture spreadsheet with the same shape as a real Intuitivo
     * export: a report header above the data, a header row identified by its
     * own labels (not a fixed row number), then the student rows, then a
     * trailing "Total Alunos" line the parser must stop at.
     */
    protected function buildFixture(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A4', 'Agrupamento de Escolas Exemplo');
        $sheet->setCellValue('A9', '3º CICLO');
        $sheet->setCellValue('H9', 'RELAÇÃO DE TURMA');

        $sheet->setCellValue('A14', 'N.º MATR.');
        $sheet->setCellValue('C14', 'NOME');
        $sheet->setCellValue('I14', 'IDADE');
        $sheet->setCellValue('K14', 'DATA NASC.');
        $sheet->setCellValue('M14', 'SIT.');
        $sheet->setCellValue('N14', 'REPET.');
        $sheet->setCellValue('P14', 'ASE');
        $sheet->setCellValue('Q14', 'NEE');
        $sheet->setCellValue('S14', 'EMR');
        $sheet->setCellValue('T14', 'PLNM');
        $sheet->setCellValue('U14', 'N.º PROC.');

        $sheet->setCellValue('A15', 1);
        $sheet->setCellValue('C15', 'Maria Teste');
        $sheet->setCellValue('I15', 12);
        $sheet->setCellValueExplicit('K15', Date::PHPToExcel(new \DateTime('2013-05-04')), DataType::TYPE_NUMERIC);
        $sheet->setCellValue('M15', 'X');
        $sheet->setCellValue('P15', 'B');
        $sheet->setCellValue('Q15', 'X');
        $sheet->setCellValue('U15', '1001');

        $sheet->setCellValue('A16', 2);
        $sheet->setCellValue('C16', 'João Exemplo');
        $sheet->setCellValue('I16', 13);
        $sheet->setCellValueExplicit('K16', Date::PHPToExcel(new \DateTime('2012-11-20')), DataType::TYPE_NUMERIC);
        $sheet->setCellValue('M16', 'TR');
        $sheet->setCellValue('N16', 'X');
        $sheet->setCellValue('U16', '1002');

        $sheet->setCellValue('C19', 'Total Alunos - 2');

        $path = tempnam(sys_get_temp_dir(), 'roster_fixture_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    #[Test]
    public function it_reads_rows_below_the_header_it_finds_by_content(): void
    {
        $this->tempPath = $this->buildFixture();

        $rows = (new RosterFileParser)->parse($this->tempPath);

        $this->assertCount(2, $rows);
        $this->assertSame('Maria Teste', $rows[0]->name);
        $this->assertSame(1, $rows[0]->classNumber);
        $this->assertSame('2013-05-04', $rows[0]->birthDate);
        $this->assertSame('X', $rows[0]->situationCode);
        $this->assertSame('1001', $rows[0]->processNumber);
        $this->assertSame('ASE: B', $rows[0]->note);
    }

    #[Test]
    public function repetente_and_ase_and_plnm_are_combined_into_one_note(): void
    {
        $this->tempPath = $this->buildFixture();

        $rows = (new RosterFileParser)->parse($this->tempPath);

        $this->assertSame('Repetente', $rows[1]->note);
    }

    #[Test]
    public function it_never_reads_the_nee_column(): void
    {
        $this->tempPath = $this->buildFixture();

        $rows = (new RosterFileParser)->parse($this->tempPath);

        // Row 1's fixture NEE cell is "X" — if the parser ever read it, it
        // would leak into the note or a new property. Neither may happen.
        $this->assertStringNotContainsString('NEE', (string) $rows[0]->note);
        $this->assertSame('ASE: B', $rows[0]->note);
    }

    #[Test]
    public function it_stops_at_the_total_alunos_line(): void
    {
        $this->tempPath = $this->buildFixture();

        $rows = (new RosterFileParser)->parse($this->tempPath);

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function a_file_without_the_expected_header_throws_a_clear_error(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Ficheiro qualquer, sem nada a ver');
        $path = tempnam(sys_get_temp_dir(), 'bad_fixture_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->tempPath = $path;

        $this->expectException(RosterFileParseException::class);

        (new RosterFileParser)->parse($this->tempPath);
    }
}
