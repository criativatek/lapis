<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\IssueCode;
use App\Domain\Import\Correction\IssueSeverity;
use App\Services\Import\Correction\IntuitivoXlsxParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\IntuitivoWorkbookBuilder;
use Tests\TestCase;

/**
 * An export whose last group is ONE column («GRUPO V», merged over rows 1–2) and
 * the rule that follows from it: no column that carries a header or marks is
 * ever skipped in silence.
 *
 * Every workbook here is invented — structure copied from a real export, people
 * and marks made up (§10).
 */
class IntuitivoSingleColumnGroupTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/lapis-intuitivo-single-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
        parent::tearDown();
    }

    protected function parse(IntuitivoWorkbookBuilder $builder, ?callable $tamper = null): CanonicalCorrectionGrid
    {
        $path = $builder->writeTo($this->directory.'/notas-'.bin2hex(random_bytes(3)).'.xlsx');

        if ($tamper !== null) {
            $spreadsheet = IOFactory::load($path);
            $tamper($spreadsheet->getActiveSheet());
            (new Xlsx($spreadsheet))->save($path);
        }

        return (new IntuitivoXlsxParser)->parse($path, 'ficha.xlsx');
    }

    protected function assertRefused(CanonicalCorrectionGrid $grid, string $messagePart): void
    {
        $this->assertFalse($grid->canBeConfirmed());
        $this->assertSame([], $grid->items);
        $this->assertSame([], $grid->results);
        $this->assertSame(IssueCode::UnsupportedStructure, $grid->errors()[0]->code);
        $this->assertSame(IssueSeverity::Error, $grid->errors()[0]->severity);
        $this->assertStringContainsString($messagePart, $grid->errors()[0]->message);
    }

    // --------------------------------------------------------- the shape

    #[Test]
    public function the_one_column_group_is_a_group_and_a_question_of_its_own(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup());

        $this->assertTrue($grid->canBeConfirmed());
        $this->assertSame([], $grid->issues);
        $this->assertCount(5, $grid->groups);
        $this->assertCount(17, $grid->items);
        $this->assertCount(21, $grid->students);
        $this->assertCount(357, $grid->results);
        $this->assertSame('100', $grid->instrument->sourceTotal);
        $this->assertSame(['sheet' => 'Notas', 'groups' => 5, 'questions' => 17], $grid->sourceMetadata);

        $this->assertSame(
            ['4', '4', '4', '4', '4', '4', '4', '6', '5', '5', '5', '5', '6', '7', '4', '5', '24'],
            array_map(fn ($item): ?string => $item->pointsPossible, $grid->items),
        );

        $last = $grid->groups[4];
        $this->assertSame('GRUPO V', $last->label);
        $this->assertSame('group:5', $last->sourceKey);

        $question = $grid->items[16];
        $this->assertSame('GRUPO V', $question->label);
        $this->assertSame('GRUPO V', $question->code);
        $this->assertSame('group:5', $question->groupSourceKey);
        $this->assertSame('item:group:5:R', $question->sourceKey);

        $maximum = '0';

        foreach ($grid->items as $item) {
            $maximum = bcadd($maximum, (string) $item->pointsPossible, 4);
        }

        $this->assertSame(0, bccomp($maximum, '100', 4));
    }

    #[Test]
    public function identities_of_the_four_horizontal_groups_are_the_ones_the_old_format_produced(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup());

        $this->assertSame(['group:1', 'group:2', 'group:3', 'group:4', 'group:5'], array_map(fn ($g): string => $g->sourceKey, $grid->groups));
        $this->assertSame('item:group:1:B', $grid->items[0]->sourceKey);
        $this->assertSame('item:group:2:F', $grid->items[4]->sourceKey);
        $this->assertSame('item:group:4:Q', $grid->items[15]->sourceKey);
    }

    #[Test]
    public function the_variant_without_a_vertical_merge_reads_the_same(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(mergeVertically: false));

        $this->assertTrue($grid->canBeConfirmed());
        $this->assertCount(5, $grid->groups);
        $this->assertCount(17, $grid->items);
        $this->assertCount(357, $grid->results);
        $this->assertSame('24', $grid->items[16]->pointsPossible);
    }

    #[Test]
    public function a_question_header_on_row_two_is_used_and_the_maximum_comes_from_either_row(): void
    {
        $builder = IntuitivoWorkbookBuilder::make()
            ->group('GRUPO I', [4, 4])
            ->singleColumnGroup('GRUPO V', null, true, 'Redação (Máximo: 12.00)')
            ->student('Alda Alvorada', [1, 2, 3]);

        $grid = $this->parse($builder);

        $this->assertTrue($grid->canBeConfirmed());
        $this->assertSame('Redação', $grid->items[2]->label);
        $this->assertSame('12', $grid->items[2]->pointsPossible);
        $this->assertSame('GRUPO V', $grid->groups[1]->label);
    }

    #[Test]
    public function two_different_maxima_for_the_same_column_are_refused(): void
    {
        $builder = IntuitivoWorkbookBuilder::make()
            ->group('GRUPO I', [4, 4])
            ->singleColumnGroup('GRUPO V', 24, true, 'Redação (Máximo: 12.00)')
            ->student('Alda Alvorada', [1, 2, 3]);

        $this->assertRefused($this->parse($builder), 'duas cotações diferentes');
    }

    #[Test]
    public function a_one_column_group_without_any_declared_maximum_is_refused(): void
    {
        $builder = IntuitivoWorkbookBuilder::make()
            ->group('GRUPO I', [4, 4])
            ->singleColumnGroup('GRUPO V', null)
            ->student('Alda Alvorada', [1, 2, 3]);

        $grid = $this->parse($builder);

        $this->assertRefused($grid, 'não declara a cotação máxima');
        $this->assertStringContainsString('coluna D', $grid->errors()[0]->message);
    }

    // ------------------------------------------------- marks and blanks

    #[Test]
    public function zeros_stay_zero_and_blank_cells_produce_no_result(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), function ($sheet): void {
            $sheet->setCellValue('R3', null); // first student, GRUPO V: blank
            $sheet->setCellValue('B3', 0);    // and a real zero on a question
        });

        $this->assertCount(356, $grid->results);

        $first = array_values(array_filter($grid->results, fn ($r): bool => $r->studentSourceKey === 'student:3'));
        $byItem = [];

        foreach ($first as $result) {
            $byItem[$result->itemSourceKey] = $result->pointsEarned;
        }

        $this->assertArrayNotHasKey('item:group:5:R', $byItem);
        $this->assertSame('0', $byItem['item:group:1:B']);
    }

    #[Test]
    public function marks_go_to_the_question_of_their_column_not_to_wherever_the_total_fits(): void
    {
        $original = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup());

        // Swap the marks of columns B and C for everybody: the Total column is
        // untouched, so a reader that trusted the total would see no change.
        $swapped = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), function ($sheet): void {
            for ($row = 3; $row <= 23; $row++) {
                $b = $sheet->getCell('B'.$row)->getValue();
                $sheet->setCellValue('B'.$row, $sheet->getCell('C'.$row)->getValue());
                $sheet->setCellValue('C'.$row, $b);
            }
        });

        $marks = fn (CanonicalCorrectionGrid $grid, string $item): array => array_values(array_map(
            fn ($r): ?string => $r->pointsEarned,
            array_filter($grid->results, fn ($r): bool => $r->itemSourceKey === $item),
        ));

        $this->assertSame($marks($original, 'item:group:1:C'), $marks($swapped, 'item:group:1:B'));
        $this->assertSame($marks($original, 'item:group:1:B'), $marks($swapped, 'item:group:1:C'));
        $this->assertNotSame($marks($original, 'item:group:1:B'), $marks($swapped, 'item:group:1:B'));
        $this->assertCount(357, $swapped->results);
    }

    #[Test]
    public function a_student_total_is_the_sum_of_that_students_marks_including_the_one_column_group(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup());

        $this->assertCount(21, $grid->students);

        foreach ($grid->students as $student) {
            $sum = '0';

            foreach ($grid->resultsForStudent($student->sourceKey) as $result) {
                $sum = bcadd($sum, (string) $result->pointsEarned, 4);
            }

            $this->assertNotNull($student->sourceScore);
            $this->assertLessThanOrEqual(0.01, abs((float) $sum - (float) $student->sourceScore));
        }
    }

    // --------------------------------------------- nothing is ignored in silence

    #[Test]
    public function a_column_with_marks_but_no_header_is_refused_naming_the_column(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), function ($sheet): void {
            // Clear the group header of GRUPO V and its merge: the marks stay.
            $sheet->unmergeCells('R1:R2');
            $sheet->setCellValue('R1', null);
        });

        $this->assertRefused($grid, 'coluna R');
    }

    #[Test]
    public function a_headed_column_between_groups_that_belongs_to_none_is_refused(): void
    {
        // Column B..E is GRUPO I; unmerge it so that C, D and E are headed on
        // row 2 but belong to no group on row 1.
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), function ($sheet): void {
            $sheet->unmergeCells('B1:E1');
        });

        $this->assertRefused($grid, 'coluna C');
    }

    #[Test]
    public function marks_to_the_right_of_the_total_are_refused(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), function ($sheet): void {
            $sheet->setCellValue('V3', 5);
        });

        $this->assertRefused($grid, 'coluna V');
        $this->assertStringContainsString('depois do total', $grid->errors()[0]->message);
    }

    #[Test]
    public function a_header_to_the_right_of_the_total_is_refused(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), function ($sheet): void {
            $sheet->setCellValue('V1', 'GRUPO VI (Máximo: 10.00)');
        });

        $this->assertRefused($grid, 'coluna V');
    }

    #[Test]
    public function a_completely_empty_column_is_ignored(): void
    {
        // Formatting alone is not content: a styled but empty column carries
        // nothing to lose and is ignored.
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), function ($sheet): void {
            $sheet->getStyle('V1:V23')->getFont()->setBold(true);
        });

        $this->assertTrue($grid->canBeConfirmed());
        $this->assertCount(357, $grid->results);
    }

    #[Test]
    public function a_text_where_a_mark_should_be_is_refused_instead_of_dropped(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), function ($sheet): void {
            $sheet->setCellValue('R5', 'faltou');
        });

        $this->assertRefused($grid, 'célula R5');
        $this->assertStringNotContainsString('faltou', $grid->errors()[0]->message);
    }

    #[Test]
    public function overlapping_merges_are_refused(): void
    {
        $grid = $this->parse(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), function ($sheet): void {
            $sheet->setCellValue('Q1', 'GRUPO INTRUSO');
            $sheet->mergeCells('Q1:R1');
        });

        $this->assertFalse($grid->canBeConfirmed());
    }

    // --------------------------------------------- reconciliation of the declared maxima

    #[Test]
    public function a_group_header_that_declares_a_maximum_must_agree_with_its_questions(): void
    {
        $agreeing = $this->parse(
            IntuitivoWorkbookBuilder::make()
                ->group('GRUPO I', [4, 4], 8)
                ->student('Alda Alvorada', [1, 2]),
        );

        $this->assertTrue($agreeing->canBeConfirmed());
        $this->assertSame([], $agreeing->issues);
        $this->assertSame('GRUPO I', $agreeing->groups[0]->label);

        $disagreeing = $this->parse(
            IntuitivoWorkbookBuilder::make()
                ->group('GRUPO I', [4, 4], 10)
                ->student('Alda Alvorada', [1, 2]),
        );

        // A warning the teacher sees, not a refusal: the questions win.
        $this->assertTrue($disagreeing->canBeConfirmed());
        $this->assertCount(1, $disagreeing->issues);
        $this->assertSame(IssueCode::SourceTotalMismatch, $disagreeing->issues[0]->code);
        $this->assertStringContainsString('GRUPO I', $disagreeing->issues[0]->message);
        $this->assertCount(2, $disagreeing->items);
    }
}
