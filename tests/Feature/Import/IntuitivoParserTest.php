<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalItem;
use App\Domain\Import\Correction\CanonicalResult;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Services\Import\Correction\IntuitivoXlsxParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\IntuitivoWorkbookBuilder;
use Tests\TestCase;

/**
 * Reading the Intuitivo «Notas» sheet, and refusing everything else.
 *
 * Exactly one export shape has ever been observed, so this parser is
 * fail-closed by design: a workbook that does not match it precisely is refused
 * rather than interpreted. A spreadsheet invites guessing, and a guess here
 * becomes a mark on a child's record.
 *
 * The fixtures are built at runtime by IntuitivoWorkbookBuilder — never copied
 * from a real export, which carries children's names in its cells and the
 * teacher's name in its OOXML metadata.
 */
class IntuitivoParserTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/lapis-intuitivo-'.bin2hex(random_bytes(6));
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

    protected function path(string $name = 'notas.xlsx'): string
    {
        return $this->directory.'/'.$name;
    }

    protected function parse(IntuitivoWorkbookBuilder $builder, string $name = 'Teste de Português - 7.º E.xlsx'): CanonicalCorrectionGrid
    {
        $path = $builder->writeTo($this->path($name));

        return (new IntuitivoXlsxParser)->parse($path, $name);
    }

    protected function observed(): IntuitivoWorkbookBuilder
    {
        return IntuitivoWorkbookBuilder::likeTheObservedExport();
    }

    // ------------------------------------------------- 1, 2. sheet and groups

    #[Test]
    public function it_recognises_a_notas_workbook_and_refuses_anything_else(): void
    {
        $parser = new IntuitivoXlsxParser;

        $good = $this->observed()->writeTo($this->path('bom.xlsx'));
        $this->assertTrue($parser->supports($good, 'bom.xlsx'));

        // Right shape, wrong sheet name: not this export.
        $wrongSheet = $this->observed()->sheetNamed('Folha1')->writeTo($this->path('outra.xlsx'));
        $this->assertFalse($parser->supports($wrongSheet, 'outra.xlsx'));

        // Right content, wrong extension claim.
        $this->assertFalse($parser->supports($good, 'bom.xls'));
    }

    #[Test]
    public function the_merged_headers_on_row_one_become_the_groups(): void
    {
        $grid = $this->parse($this->observed());

        $this->assertSame(CorrectionGridSource::Intuitivo, $grid->source);
        $this->assertCount(4, $grid->groups);
        $this->assertSame(
            ['GRUPO I', 'GRUPO II', 'GRUPO III', 'GRUPO IV'],
            array_map(fn ($group): ?string => $group->label, $grid->groups),
        );

        // A group is structure and says nothing curricular. No domain is
        // inferred from a label, ever (§4).
        foreach ($grid->items as $item) {
            $this->assertNull($item->domainHint);
        }
    }

    // ------------------------------------------- 3, 4, 5. questions and maxima

    #[Test]
    public function every_question_carries_the_maximum_from_its_own_header(): void
    {
        $grid = $this->parse($this->observed());

        $this->assertCount(25, $grid->items);

        $maxima = array_map(fn (CanonicalItem $item): ?string => $item->pointsPossible, $grid->items);

        $this->assertSame(
            ['4', '4', '4', '4', '4', '3', '3', '3', '12', '2', '2', '2', '6', '5', '6', '6', '4', '6', '5', '3', '4', '2', '2', '2', '2'],
            $maxima,
        );

        // 20 + 21 + 29 + 30 = 100, which is what the file declares.
        $this->assertSame('100', $grid->instrument->sourceTotal);
    }

    #[Test]
    public function item_one_in_two_groups_is_two_different_questions(): void
    {
        $grid = $this->parse($this->observed());

        $first = array_values(array_filter($grid->items, fn (CanonicalItem $item): bool => $item->code === 'Item 1'));

        // «Item 1» exists in all four groups — a real thing on a real test paper.
        $this->assertCount(4, $first);
        $this->assertCount(4, array_unique(array_map(fn (CanonicalItem $item): string => $item->sourceKey, $first)));
        $this->assertCount(4, array_unique(array_map(fn (CanonicalItem $item): ?string => $item->groupSourceKey, $first)));
    }

    // ------------------------------------------------ 6, 7, 8. rows and marks

    #[Test]
    public function formatted_but_empty_rows_are_not_students(): void
    {
        $grid = $this->parse($this->observed());

        $this->assertCount(6, $grid->students);
        $this->assertSame('Ana Exemplo', $grid->students[0]->displayName);
    }

    #[Test]
    public function each_mark_lands_on_the_question_of_its_own_group(): void
    {
        $grid = $this->parse($this->observed());

        // Six students × twenty-five questions, none missing in this fixture.
        $this->assertCount(150, $grid->results);

        $ana = $grid->students[0]->sourceKey;
        $grupoII = array_values(array_filter($grid->items, fn (CanonicalItem $i): bool => $i->groupSourceKey === 'group:2'));

        $marks = [];

        foreach ($grid->resultsForStudent($ana) as $result) {
            $marks[$result->itemSourceKey] = $result->pointsEarned;
        }

        // Ana's Grupo II: 3, 0, 0, 12.
        $this->assertSame(['3', '0', '0', '12'], array_map(fn (CanonicalItem $i): ?string => $marks[$i->sourceKey], $grupoII));
    }

    // ------------------------------------------------- 9, 10. zero vs blank

    #[Test]
    public function a_zero_is_a_mark_and_a_blank_is_nothing_at_all(): void
    {
        $builder = $this->observed()->student('Gina Ausente', [
            // Two blanks in Grupo I, a real zero in the third.
            null, null, 0, 4, 4,
            3, 3, 3, 12,
            2, 2, 2, 6, 5, 6, 6,
            4, 6, 5, 3, 4, 2, 2, 2, 2,
        ]);

        $grid = $this->parse($builder);
        $gina = $grid->students[6]->sourceKey;

        $marks = [];

        foreach ($grid->resultsForStudent($gina) as $result) {
            $marks[$result->itemSourceKey] = $result->pointsEarned;
        }

        // 23 results, not 25: the two blanks produced NO row.
        $this->assertCount(23, $marks);

        $grupoI = array_values(array_filter($grid->items, fn (CanonicalItem $i): bool => $i->groupSourceKey === 'group:1'));

        $this->assertArrayNotHasKey($grupoI[0]->sourceKey, $marks, 'Um vazio não produz resultado nenhum.');
        $this->assertArrayNotHasKey($grupoI[1]->sourceKey, $marks);
        $this->assertSame('0', $marks[$grupoI[2]->sourceKey], 'Um zero é um zero.');

        // And nothing anywhere calls a blank an absence.
        $encoded = (string) json_encode($grid->toArray());

        foreach (['absent', 'not_applicable', 'exempt', 'Faltou'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    // ------------------------------------------- 11, 12, 13. totals, decimals

    #[Test]
    public function the_source_total_is_carried_as_a_summary_and_never_as_a_mark(): void
    {
        $grid = $this->parse($this->observed());

        $ana = $grid->students[0];

        // 4 + 15 + 7.33 + 11.33 = 37.66.
        $this->assertSame('37.66', $ana->sourceScore);

        $summaries = $grid->summariesForStudent($ana->sourceKey);
        $this->assertCount(1, $summaries);
        $this->assertSame('total', $summaries[0]->key);
        $this->assertSame('37.66', $summaries[0]->value);
        $this->assertSame('points', $summaries[0]->unit);
    }

    #[Test]
    public function excel_float_noise_never_reaches_the_canonical_grid(): void
    {
        // 3.33 + 3.33 + 3.34 is the kind of sum that produces 9.999999999999998.
        $builder = IntuitivoWorkbookBuilder::make()
            ->group('GRUPO ÚNICO', [3.33, 3.33, 3.34])
            ->student('Ana Exemplo', [3.33, 3.33, 3.34]);

        $grid = $this->parse($builder);

        foreach ($grid->items as $item) {
            $this->assertMatchesRegularExpression('/^\d+(\.\d{1,4})?$/', (string) $item->pointsPossible);
        }

        foreach ($grid->results as $result) {
            $this->assertMatchesRegularExpression(
                '/^\d+(\.\d{1,4})?$/',
                (string) $result->pointsEarned,
                'Nenhum valor pode trazer ruído de vírgula flutuante.',
            );
        }

        $this->assertSame('10', $grid->students[0]->sourceScore);
    }

    // ------------------------------------------------ 14, 15. refusals

    #[Test]
    public function a_workbook_without_the_student_column_is_refused(): void
    {
        $builder = IntuitivoWorkbookBuilder::make()
            ->group('GRUPO I', [4, 4])
            ->student('Ana Exemplo', [4, 4]);

        $path = $builder->writeTo($this->path());

        // Break the one header the format is anchored on.
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Aluno');
        (new Xlsx($spreadsheet))->save($path);

        $grid = (new IntuitivoXlsxParser)->parse($path, 'notas.xlsx');

        $this->assertFalse($grid->canBeConfirmed());
        $this->assertStringContainsString('Nome do estudante', $grid->errors()[0]->message);
        $this->assertSame([], $grid->students);
    }

    #[Test]
    public function a_question_without_a_declared_maximum_is_refused_rather_than_guessed(): void
    {
        $builder = IntuitivoWorkbookBuilder::make()
            ->group('GRUPO I', [4, null])
            ->student('Ana Exemplo', [4, 2]);

        $grid = $this->parse($builder);

        // Inventing a cotação would be inventing an assessment rule (§1).
        $this->assertFalse($grid->canBeConfirmed());
        $this->assertStringContainsString('cotação', $grid->errors()[0]->message);
    }

    #[Test]
    public function a_formula_where_a_mark_should_be_is_refused_and_never_executed(): void
    {
        $grid = $this->parse($this->observed()->withFormulaAt('B3'));

        $this->assertFalse($grid->canBeConfirmed());
        $this->assertStringContainsString('fórmula', $grid->errors()[0]->message);
        $this->assertStringContainsString('não executa', $grid->errors()[0]->message);
    }

    #[Test]
    public function a_file_that_is_not_an_intuitivo_export_is_not_even_attempted(): void
    {
        $parser = new IntuitivoXlsxParser;

        // The Plickers CSV, offered to the wrong parser.
        $csv = base_path('tests/Fixtures/Import/plickers-basico.csv');
        $this->assertFalse($parser->supports($csv, 'plickers-basico.csv'));

        // And something that is not a zip at all.
        $rubbish = $this->path('lixo.xlsx');
        file_put_contents($rubbish, 'isto não é um xlsx');
        $this->assertFalse($parser->supports($rubbish, 'lixo.xlsx'));

        $grid = $parser->parse($rubbish, 'lixo.xlsx');
        $this->assertFalse($grid->canBeConfirmed());
        $this->assertStringContainsString('ainda não é reconhecido', $grid->errors()[0]->message);
    }

    // ------------------------------------------------------- 16. privacy

    #[Test]
    public function ooxml_metadata_never_reaches_the_canonical_snapshot(): void
    {
        $path = $this->observed()->writeTo($this->path());

        // A real export carries the teacher's name and the folder it was saved
        // from. Put both in, and prove neither survives the parse (§10).
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getProperties()
            ->setCreator('Professor Real Da Escola')
            ->setLastModifiedBy('Professor Real Da Escola')
            ->setTitle('D:\\algum\\caminho\\local');
        (new Xlsx($spreadsheet))->save($path);

        $grid = (new IntuitivoXlsxParser)->parse($path, 'notas.xlsx');
        $encoded = (string) json_encode($grid->toArray());

        $this->assertStringNotContainsString('Professor Real Da Escola', $encoded);
        $this->assertStringNotContainsString('algum', $encoded);
        $this->assertSame(['sheet' => 'Notas', 'groups' => 4, 'questions' => 25], $grid->sourceMetadata);
    }

    #[Test]
    public function overlapping_group_headers_are_refused(): void
    {
        // A question cannot belong to two groups; a file that says so is not
        // one this parser can read honestly. A second LABELLED header spanning
        // columns the first one already claims — an unlabelled merge is simply
        // not a group header and is ignored.
        $path = $this->observed()->writeTo($this->path());

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('D1', 'GRUPO INTRUSO');
        $sheet->mergeCells('D1:H1');
        (new Xlsx($spreadsheet))->save($path);

        $grid = (new IntuitivoXlsxParser)->parse($path, 'notas.xlsx');

        $this->assertFalse($grid->canBeConfirmed());
        $this->assertStringContainsString('sobrepõem', $grid->errors()[0]->message);
    }

    #[Test]
    public function the_parser_writes_nothing_and_needs_no_database(): void
    {
        // A parser translates. It has no idea Instrument exists (§7).
        $grid = $this->parse($this->observed());

        $this->assertInstanceOf(CanonicalCorrectionGrid::class, $grid);
        $this->assertContainsOnlyInstancesOf(CanonicalResult::class, $grid->results);
    }
}
