<?php

namespace Tests\Unit\Import;

use App\Services\Import\RosterFileParseException;
use App\Services\Import\RosterFileParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RosterFixture;
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
     *
     * Delegates to Tests\Support\RosterFixture, extracted in Task 8 so the
     * same fixture is a single source of truth shared with the roster-import
     * feature tests.
     */
    protected function buildFixture(): string
    {
        return (new RosterFixture)->build();
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
