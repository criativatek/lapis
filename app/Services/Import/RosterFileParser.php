<?php

namespace App\Services\Import;

use App\Domain\Import\RosterRow;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the "Relação de Turma" roster export (Excel, .xls or .xlsx). The
 * report embeds a title block above the real table, so the header row is
 * located by its own cell content — never a fixed row number, and never
 * assumed to survive a slightly different export. NEE is never read.
 */
class RosterFileParser
{
    protected const HEADER_MATRICULA = 'N.º MATR.';

    protected const HEADER_NAME = 'NOME';

    protected const HEADER_AGE = 'IDADE';

    protected const HEADER_BIRTH_DATE = 'DATA NASC.';

    protected const HEADER_SITUATION = 'SIT.';

    protected const HEADER_REPEATER = 'REPET.';

    protected const HEADER_SOCIAL_SUPPORT = 'ASE';

    protected const HEADER_NON_NATIVE_PORTUGUESE = 'PLNM';

    protected const HEADER_PROCESS_NUMBER = 'N.º PROC.';

    protected const STOP_MARKER = 'Total Alunos';

    /**
     * @return list<RosterRow>
     */
    public function parse(string $path): array
    {
        // @ suppresses the legacy .xls reader's harmless "uninitialized string
        // offset" warnings from an embedded logo image the reader doesn't fully
        // understand — extraction still succeeds correctly around it.
        $sheet = @IOFactory::load($path)->getActiveSheet();

        $columns = $this->locateHeaderColumns($sheet);

        $rows = [];
        $row = (int) $columns['header_row'] + 1;

        while ($row <= $sheet->getHighestRow()) {
            $nameCell = $sheet->getCell($columns[self::HEADER_NAME].$row)->getValue();

            if ($nameCell === null || str_contains((string) $nameCell, self::STOP_MARKER)) {
                break;
            }

            if (trim((string) $nameCell) === '') {
                $row++;

                continue;
            }

            $rows[] = $this->rowAt($sheet, $columns, $row);
            $row++;
        }

        return $rows;
    }

    /**
     * @return array<string, string|int>
     */
    protected function locateHeaderColumns(Worksheet $sheet): array
    {
        $needles = [
            self::HEADER_MATRICULA, self::HEADER_NAME, self::HEADER_BIRTH_DATE,
            self::HEADER_SITUATION, self::HEADER_PROCESS_NUMBER,
        ];

        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            $found = [];

            foreach ($sheet->getRowIterator($row, $row)->current()->getCellIterator() as $cell) {
                $value = trim((string) $cell->getValue());

                if ($value !== '') {
                    $found[$value] = $cell->getColumn();
                }
            }

            $hasAllNeedles = count(array_intersect($needles, array_keys($found))) === count($needles);

            if ($hasAllNeedles) {
                $found['header_row'] = $row;

                return $found;
            }
        }

        throw new RosterFileParseException(
            'Não foi possível encontrar as colunas esperadas (N.º MATR., NOME, DATA NASC., SIT., N.º PROC.) neste ficheiro.',
        );
    }

    /**
     * @param  array<string, string|int>  $columns
     */
    protected function rowAt(Worksheet $sheet, array $columns, int $row): RosterRow
    {
        $matriculaRaw = $sheet->getCell($columns[self::HEADER_MATRICULA].$row)->getValue();

        return new RosterRow(
            name: trim((string) $sheet->getCell($columns[self::HEADER_NAME].$row)->getValue()),
            classNumber: is_numeric($matriculaRaw) ? (int) $matriculaRaw : null,
            birthDate: $this->readDate($sheet, $columns, $row),
            situationCode: trim((string) $sheet->getCell($columns[self::HEADER_SITUATION].$row)->getValue()),
            processNumber: $this->nullableString($sheet, $columns, self::HEADER_PROCESS_NUMBER, $row),
            note: $this->buildNote($sheet, $columns, $row),
        );
    }

    /**
     * @param  array<string, string|int>  $columns
     */
    protected function readDate(Worksheet $sheet, array $columns, int $row): ?string
    {
        $raw = $sheet->getCell($columns[self::HEADER_BIRTH_DATE].$row)->getValue();

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
        }

        $parsed = \DateTime::createFromFormat('d-m-Y', (string) $raw);

        if ($parsed === false) {
            $parsed = \DateTime::createFromFormat('Y-m-d', (string) $raw);
        }

        return $parsed === false ? null : $parsed->format('Y-m-d');
    }

    /**
     * @param  array<string, string|int>  $columns
     */
    protected function nullableString(Worksheet $sheet, array $columns, string $header, int $row): ?string
    {
        if (! isset($columns[$header])) {
            return null;
        }

        $value = trim((string) $sheet->getCell($columns[$header].$row)->getValue());

        return $value === '' ? null : $value;
    }

    /**
     * Repetente + ASE + PLNM, exactly as they appear in the file. NEE is
     * intentionally absent from this list — it is never read, matching the
     * standing architectural exclusion (docs/domain-model.md §11.3).
     *
     * @param  array<string, string|int>  $columns
     */
    protected function buildNote(Worksheet $sheet, array $columns, int $row): ?string
    {
        $parts = [];

        if ($this->nullableString($sheet, $columns, self::HEADER_REPEATER, $row) !== null) {
            $parts[] = 'Repetente';
        }

        if (($ase = $this->nullableString($sheet, $columns, self::HEADER_SOCIAL_SUPPORT, $row)) !== null) {
            $parts[] = "ASE: {$ase}";
        }

        if ($this->nullableString($sheet, $columns, self::HEADER_NON_NATIVE_PORTUGUESE, $row) !== null) {
            $parts[] = 'PLNM';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
