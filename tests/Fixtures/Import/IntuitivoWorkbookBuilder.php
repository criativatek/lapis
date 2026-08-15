<?php

namespace Tests\Fixtures\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Builds Intuitivo-shaped workbooks from nothing.
 *
 * Not a copy of a real export, and deliberately not derived from one: a real
 * file carries children's names in its cells and the teacher's name in its OOXML
 * metadata, and neither belongs in a repository. What is reproduced here is the
 * SHAPE — one sheet called «Notas», group names merged across their questions on
 * row 1, «Item N (Máximo: X)» headers on row 2, names down column A, a Total and
 * a Total (%) column — with entirely invented people and marks.
 *
 * Every fixture the tests use is produced by this builder at runtime rather than
 * committed as binary, so what a test asserts is visible in the test rather than
 * hidden inside a zip nobody opens.
 */
class IntuitivoWorkbookBuilder
{
    /** @var list<array{label: string, items: list<array{label: string, max: float|null}>}> */
    protected array $groups = [];

    /** @var list<array{name: string, scores: list<float|null>}> */
    protected array $students = [];

    protected string $sheetName = 'Notas';

    protected bool $withTotalColumn = true;

    protected bool $withPercentageColumn = true;

    protected ?float $declaredMaximum = null;

    /** @var array<int, float|null> student index => total that overrides the computed one */
    protected array $overriddenTotals = [];

    protected ?string $formulaInCell = null;

    protected bool $mergeGroupHeaders = true;

    public static function make(): self
    {
        return new self;
    }

    /**
     * The shape observed in the wild: four groups, twenty-five questions, six
     * students, «Item 1» in every group, real zeros and two-decimal marks.
     */
    public static function likeTheObservedExport(): self
    {
        return self::make()
            ->group('GRUPO I', [4, 4, 4, 4, 4])
            ->group('GRUPO II', [3, 3, 3, 12])
            ->group('GRUPO III', [2, 2, 2, 6, 5, 6, 6])
            ->group('GRUPO IV', [4, 6, 5, 3, 4, 2, 2, 2, 2])
            ->student('Ana Exemplo', [
                0, 0, 4, 0, 0,
                3, 0, 0, 12,
                2, 2, 0, 0, 3.33, 0, 0,
                1, 4, 3.33, 0, 0, 1.5, 0, 1.5, 0,
            ])
            ->student('Bruno Teste', [
                4, 4, 4, 4, 4,
                3, 3, 3, 12,
                2, 2, 2, 5, 5, 3, 5,
                3, 2, 3.33, 0, 0, 2, 2, 2, 0,
            ])
            ->student('Carla Fictícia', [
                4, 4, 4, 4, 4,
                3, 3, 0, 12,
                2, 2, 2, 5, 3.33, 3, 5,
                4, 4, 1.67, 0, 0, 2, 2, 2, 0,
            ])
            ->student('Diogo Inventado', [
                4, 0, 4, 0, 0,
                3, 0, 0, 12,
                2, 0, 2, 2, 0, 4, 2,
                2, 4, 5, 3, 0, 1.5, 0, 0, 0,
            ])
            ->student('Elsa Suposta', [
                4, 0, 0, 4, 4,
                3, 0, 3, 12,
                2, 2, 2, 2, 3.33, 0, 3,
                2, 0, 3.33, 0, 0, 0, 2, 2, 0,
            ])
            ->student('Filipe Imaginário', [
                4, 0, 4, 4, 4,
                3, 0, 3, 12,
                2, 2, 0, 6, 3.33, 5, 5,
                4, 4, 5, 0, 0, 2, 0, 2, 0,
            ]);
    }

    /**
     * @param  list<float|null>  $maxima  one per question, in order
     */
    public function group(string $label, array $maxima): self
    {
        $items = [];

        foreach ($maxima as $index => $max) {
            // «Item 1» restarts in every group — which is exactly why a code is
            // never identity on its own.
            $items[] = ['label' => 'Item '.($index + 1), 'max' => $max];
        }

        $this->groups[] = ['label' => $label, 'items' => $items];

        return $this;
    }

    /**
     * @param  list<float|null>  $scores  one per question across all groups, in order
     */
    public function student(string $name, array $scores): self
    {
        $this->students[] = ['name' => $name, 'scores' => $scores];

        return $this;
    }

    public function sheetNamed(string $name): self
    {
        $this->sheetName = $name;

        return $this;
    }

    public function withoutTotalColumn(): self
    {
        $this->withTotalColumn = false;

        return $this;
    }

    public function withoutPercentageColumn(): self
    {
        $this->withPercentageColumn = false;

        return $this;
    }

    /** A declared maximum that disagrees with the sum of the questions. */
    public function declaringMaximum(float $maximum): self
    {
        $this->declaredMaximum = $maximum;

        return $this;
    }

    /** A student total that disagrees with the sum of their own marks. */
    public function overridingTotalOf(int $studentIndex, ?float $total): self
    {
        $this->overriddenTotals[$studentIndex] = $total;

        return $this;
    }

    /** Puts a formula where a mark should be, to prove the parser refuses it. */
    public function withFormulaAt(string $cell): self
    {
        $this->formulaInCell = $cell;

        return $this;
    }

    /** Group names written without merging, so the structure cannot be read. */
    public function withoutMergedGroupHeaders(): self
    {
        $this->mergeGroupHeaders = false;

        return $this;
    }

    /**
     * Writes the workbook and returns the path it was written to.
     *
     * Identical workbooks are built once and copied afterwards. Not premature
     * optimisation: PhpSpreadsheet is expensive in memory and does not give all
     * of it back, and a suite that asks for the same six-student export forty
     * times over one PHP process walks into the memory limit — which is exactly
     * what happened. The signature includes this file's own timestamp, so
     * editing the builder invalidates every cached workbook.
     */
    public function writeTo(string $path): string
    {
        $signature = md5(serialize([
            filemtime(__FILE__),
            $this->groups,
            $this->students,
            $this->sheetName,
            $this->withTotalColumn,
            $this->withPercentageColumn,
            $this->declaredMaximum,
            $this->overriddenTotals,
            $this->formulaInCell,
            $this->mergeGroupHeaders,
        ]));

        $cached = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lapis-intuitivo-cache-'.$signature.'.xlsx';

        if (! is_file($cached) || filesize($cached) === 0) {
            // Built beside the cache and moved into place, never written to it
            // directly. A run killed mid-save — which is exactly how this was
            // found, PHP hitting its memory limit inside the zip writer — would
            // otherwise leave a truncated file that every later run happily
            // copies, and the failure surfaces far from its cause.
            $partial = $cached.'.'.getmypid().'.partial';

            $this->build($partial);

            if (! is_file($partial) || filesize($partial) === 0) {
                @unlink($partial);

                throw new \RuntimeException('O livro Intuitivo de teste não chegou a ser escrito.');
            }

            rename($partial, $cached);
        }

        copy($cached, $path);

        return $path;
    }

    protected function build(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetName);

        $sheet->setCellValue('A1', 'Nome do estudante');
        $sheet->mergeCells('A1:A2');

        $column = 2; // B

        foreach ($this->groups as $group) {
            $first = $column;

            foreach ($group['items'] as $item) {
                $letter = $this->letter($column);
                $header = $item['max'] === null
                    ? $item['label']
                    : sprintf('%s (Máximo: %.2f)', $item['label'], $item['max']);

                $sheet->setCellValue($letter.'2', $header);
                $column++;
            }

            $sheet->setCellValue($this->letter($first).'1', $group['label']);

            if ($this->mergeGroupHeaders && $column - 1 > $first) {
                $sheet->mergeCells($this->letter($first).'1:'.$this->letter($column - 1).'1');
            }
        }

        $questionCount = $column - 2;
        $maximum = $this->declaredMaximum ?? $this->sumOfMaxima();

        if ($this->withTotalColumn) {
            $letter = $this->letter($column);
            $sheet->setCellValue($letter.'1', sprintf('Total (Máximo: %d)', $maximum));
            $sheet->mergeCells($letter.'1:'.$letter.'2');
            $totalColumn = $column;
            $column++;
        }

        if ($this->withPercentageColumn) {
            $letter = $this->letter($column);
            $sheet->setCellValue($letter.'1', 'Total (%)');
            $sheet->mergeCells($letter.'1:'.$letter.'2');
            $percentageColumn = $column;
        }

        $row = 3;

        foreach ($this->students as $index => $student) {
            $sheet->setCellValue('A'.$row, $student['name']);

            $sum = 0.0;
            $anyMissing = false;

            for ($i = 0; $i < $questionCount; $i++) {
                $score = $student['scores'][$i] ?? null;

                if ($score === null) {
                    // Left genuinely empty — no cell value at all.
                    $anyMissing = true;

                    continue;
                }

                $sheet->setCellValue($this->letter($i + 2).$row, $score);
                $sum += $score;
            }

            if ($this->withTotalColumn) {
                $total = array_key_exists($index, $this->overriddenTotals)
                    ? $this->overriddenTotals[$index]
                    : ($anyMissing ? null : round($sum, 2));

                if ($total !== null) {
                    $sheet->setCellValue($this->letter($totalColumn).$row, $total);
                }
            }

            if ($this->withPercentageColumn && ! $anyMissing && $maximum > 0) {
                $sheet->setCellValue(
                    $this->letter($percentageColumn).$row,
                    number_format(round($sum / $maximum * 100, 2), 2, '.', '').'%',
                );
            }

            $row++;
        }

        if ($this->formulaInCell !== null) {
            $sheet->setCellValue($this->formulaInCell, '=SUM(B3:F3)');
        }

        (new XlsxWriter($spreadsheet))->save($path);

        // Same reason the parser disconnects: cells reference the sheet which
        // references the workbook, and holding every one of them until the
        // memory limit says stop is not a useful way to run a test suite.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    protected function sumOfMaxima(): float
    {
        $total = 0.0;

        foreach ($this->groups as $group) {
            foreach ($group['items'] as $item) {
                $total += $item['max'] ?? 0;
            }
        }

        return $total;
    }

    protected function letter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }
}
