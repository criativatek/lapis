<?php

// tests/Support/RosterFixture.php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A two-student roster fixture matching the real Intuitivo export shape: a
 * report header above the data, and a header row the parser must locate by
 * content. Fictional data only.
 *
 * Kept in full parity with the fixture RosterFileParserTest originally built
 * inline (Task 3) — including the title block above the header and the
 * REPET./ASE/NEE cells that drive its note-building and NEE-exclusion
 * assertions — so extracting this class changes where the fixture lives, not
 * what it contains.
 */
class RosterFixture
{
    public function build(): string
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
        $sheet->setCellValueExplicit('K15', ExcelDate::PHPToExcel(new \DateTime('2013-05-04')), DataType::TYPE_NUMERIC);
        $sheet->setCellValue('M15', 'X');
        $sheet->setCellValue('P15', 'B');
        $sheet->setCellValue('Q15', 'X');
        $sheet->setCellValue('U15', '1001');

        $sheet->setCellValue('A16', 2);
        $sheet->setCellValue('C16', 'João Exemplo');
        $sheet->setCellValue('I16', 13);
        $sheet->setCellValueExplicit('K16', ExcelDate::PHPToExcel(new \DateTime('2012-11-20')), DataType::TYPE_NUMERIC);
        $sheet->setCellValue('M16', 'TR');
        $sheet->setCellValue('N16', 'X');
        $sheet->setCellValue('U16', '1002');

        $sheet->setCellValue('C19', 'Total Alunos - 2');

        $path = tempnam(sys_get_temp_dir(), 'roster_fixture_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
