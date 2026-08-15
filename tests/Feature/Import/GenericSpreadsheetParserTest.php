<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\TabularMapping;
use App\Services\Import\Correction\GenericSpreadsheetParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\GenericSpreadsheetBuilder;
use Tests\TestCase;
use ZipArchive;

/**
 * Reading a spreadsheet nobody wrote an adapter for.
 *
 * The premise is the opposite of the other two parsers': this one has never seen
 * the file and is not allowed to pretend otherwise. So what is asserted here is
 * mostly about RESTRAINT — that a heading is not a domain, that a blank is not a
 * zero, that the largest observed mark is not the cotação, and that a file it
 * cannot read honestly is refused with a sentence rather than interpreted.
 *
 * Every fixture is built at runtime and every person in it is invented.
 */
class GenericSpreadsheetParserTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/lapis-generic-'.bin2hex(random_bytes(6));
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

    protected function path(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    protected function parser(): GenericSpreadsheetParser
    {
        return new GenericSpreadsheetParser;
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  array<string, string>  $points
     */
    protected function mapping(array $table = [], string $resultMode = ImportMapping::RESULT_OVERALL, array $points = []): ImportMapping
    {
        return new ImportMapping(
            points: $points,
            resultMode: $resultMode,
            table: TabularMapping::fromArray($table),
        );
    }

    /**
     * @param  array<string, mixed>  $table
     */
    protected function parseCsv(GenericSpreadsheetBuilder $builder, array $table = [], string $resultMode = ImportMapping::RESULT_OVERALL): CanonicalCorrectionGrid
    {
        $path = $builder->writeCsv($this->path('notas.csv'));

        return $this->parser()->parseWith($path, 'notas.csv', $this->mapping($table, $resultMode));
    }

    // ------------------------------------------------------- 1. what it accepts

    #[Test]
    public function it_accepts_csv_and_xlsx_and_nothing_else(): void
    {
        $parser = $this->parser();

        $this->assertSame(['csv', 'xlsx'], $parser->extensions());
        $this->assertSame(CorrectionGridSource::Generic, $parser->source());

        $csv = GenericSpreadsheetBuilder::overall()->writeCsv($this->path('notas.csv'));
        $this->assertTrue($parser->supports($csv, 'notas.csv'));

        // The same bytes under a name this parser does not claim.
        $this->assertFalse($parser->supports($csv, 'notas.ods'));
        $this->assertFalse($parser->supports($csv, 'notas.xlsm'));
        $this->assertFalse($parser->supports($csv, 'notas.xls'));
    }

    // ----------------------------------------------------------- 2, 3. csv shape

    #[Test]
    public function it_reads_a_semicolon_file_and_a_comma_file_the_same_way(): void
    {
        foreach ([';', ',', "\t"] as $delimiter) {
            $description = $this->parser()->describe(
                GenericSpreadsheetBuilder::multiDomain()->separatedBy($delimiter)->writeCsv($this->path('n.csv')),
                $this->mapping(),
            );

            $this->assertTrue($description['readable'], "falhou com «{$delimiter}»");
            $this->assertCount(4, $description['columns']);
            $this->assertSame('Leitura', $description['columns'][1]['heading']);
        }
    }

    #[Test]
    public function a_byte_order_mark_does_not_become_part_of_the_first_heading(): void
    {
        $description = $this->parser()->describe(
            GenericSpreadsheetBuilder::overall()->withByteOrderMark()->writeCsv($this->path('n.csv')),
            $this->mapping(),
        );

        // Without stripping it, the first heading reads «\u{FEFF}Nome» and the
        // student column is quietly a different column from the one shown.
        $this->assertSame('Nome', $description['columns'][0]['heading']);
        $this->assertTrue($description['metadata']['byte_order_mark']);
    }

    #[Test]
    public function a_name_containing_the_delimiter_survives_because_it_is_quoted(): void
    {
        $grid = $this->parseCsv(
            GenericSpreadsheetBuilder::make()->rows([
                ['Nome', 'Nota'],
                ['Exemplo, Ana', 78],
                ['Teste; Bruno', 64],
            ])->separatedBy(','),
            ['sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B'], 'overall_maximum' => '100'],
        );

        $this->assertSame(['Exemplo, Ana', 'Teste; Bruno'], array_map(
            fn ($student): ?string => $student->displayName,
            $grid->students,
        ));
    }

    #[Test]
    public function a_file_that_is_not_utf8_is_refused_rather_than_guessed_at(): void
    {
        $grid = $this->parseCsv(
            GenericSpreadsheetBuilder::multiDomain()->encodedAs('ISO-8859-1'),
            ['sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B']],
        );

        $this->assertFalse($grid->canBeConfirmed());
        $this->assertStringContainsString('UTF-8', $grid->errors()[0]->message);
    }

    #[Test]
    public function a_decimal_comma_is_a_decimal_and_not_a_delimiter(): void
    {
        $grid = $this->parseCsv(
            GenericSpreadsheetBuilder::make()->rows([
                ['Nome', 'Leitura'],
                ['Ana Exemplo', '12,5'],
            ])->separatedBy(';'),
            ['sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B']],
            ImportMapping::RESULT_PER_GROUP,
        );

        $this->assertSame('12.5', $grid->results[0]->pointsEarned);
    }

    // ------------------------------------------------------------ 4. blank rows

    #[Test]
    public function a_title_row_and_a_blank_line_above_the_table_are_survivable(): void
    {
        $grid = $this->parseCsv(
            GenericSpreadsheetBuilder::make()->rows([
                ['Fichas de avaliação — 7.º Z'],
                [],
                ['Nome', 'Leitura'],
                ['Ana Exemplo', 14],
                [],
                ['Bruno Teste', 16],
            ]),
            ['sheet' => 'Folha 1', 'header_row' => 3, 'student_column' => 'A', 'result_columns' => ['B']],
            ImportMapping::RESULT_PER_GROUP,
        );

        $this->assertCount(2, $grid->students);
        // Row numbers are the spreadsheet's own, so the blank line does not
        // shift anybody: Bruno is on row 6, not row 5.
        $this->assertSame(['row:4', 'row:6'], array_map(fn ($student): string => $student->sourceKey, $grid->students));
    }

    // ------------------------------------------------------ 5. duplicate headers

    #[Test]
    public function two_columns_called_item_one_do_not_collide(): void
    {
        $grid = $this->parseCsv(
            GenericSpreadsheetBuilder::perItem(),
            ['sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B', 'C']],
            ImportMapping::RESULT_PER_QUESTION,
        );

        // Same label, different identity — because identity is the position (§20).
        $this->assertSame(['Item 1', 'Item 1'], array_map(fn ($item): ?string => $item->label, $grid->items));
        $this->assertSame(['col:B', 'col:C'], array_map(fn ($item): string => $item->sourceKey, $grid->items));

        $ana = $grid->resultsForStudent('row:2');
        $this->assertSame('2', $ana[0]->pointsEarned);
        $this->assertSame('3', $ana[1]->pointsEarned);
    }

    // ---------------------------------------------------- 6. typing and blanks

    #[Test]
    public function a_zero_is_a_mark_and_a_blank_is_nothing_at_all(): void
    {
        $grid = $this->parseCsv(
            GenericSpreadsheetBuilder::blankAgainstZero(),
            ['sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B', 'C']],
            ImportMapping::RESULT_PER_GROUP,
        );

        $ana = $grid->resultsForStudent('row:2');
        $this->assertSame('0', $ana[0]->pointsEarned, 'um zero escrito é um zero');

        // Bruno's Leitura is empty. Not a zero, not an absence — no result at all.
        $bruno = $grid->resultsForStudent('row:3');
        $this->assertCount(1, $bruno);
        $this->assertSame('col:C', $bruno[0]->itemSourceKey);
    }

    #[Test]
    public function text_where_a_mark_should_be_is_left_unresolved_and_never_interpreted(): void
    {
        $grid = $this->parseCsv(
            GenericSpreadsheetBuilder::make()->rows([
                ['Nome', 'Leitura'],
                ['Ana Exemplo', 'F'],
                ['Bruno Teste', 'NR'],
                ['Carla Fictícia', 14],
            ]),
            ['sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B']],
            ImportMapping::RESULT_PER_GROUP,
        );

        // «F» is not a zero, not a falta, not a dispensa. It is a thing the
        // teacher has to resolve, and it is said so out loud (§30).
        $this->assertCount(1, $grid->results);
        $this->assertSame('row:4', $grid->results[0]->studentSourceKey);

        $warning = $grid->warnings()[0]->message;
        $this->assertStringContainsString('«F»', $warning);
        $this->assertStringContainsString('«NR»', $warning);
    }

    // --------------------------------------------------------- 7. xlsx and sheets

    #[Test]
    public function a_workbook_and_a_csv_of_the_same_table_produce_the_same_grid(): void
    {
        $table = ['sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B', 'C', 'D']];

        $fromCsv = $this->parser()->parseWith(
            GenericSpreadsheetBuilder::multiDomain()->writeCsv($this->path('n.csv')),
            'n.csv',
            $this->mapping($table, ImportMapping::RESULT_PER_GROUP),
        );

        $fromXlsx = $this->parser()->parseWith(
            GenericSpreadsheetBuilder::multiDomain()->writeXlsx($this->path('n.xlsx')),
            'n.xlsx',
            $this->mapping($table, ImportMapping::RESULT_PER_GROUP),
        );

        $marks = fn (CanonicalCorrectionGrid $grid): array => array_map(
            fn ($result): array => [$result->studentSourceKey, $result->itemSourceKey, $result->pointsEarned],
            $grid->results,
        );

        $this->assertSame($marks($fromCsv), $marks($fromXlsx));
        $this->assertSame(
            array_map(fn ($student): ?string => $student->displayName, $fromCsv->students),
            array_map(fn ($student): ?string => $student->displayName, $fromXlsx->students),
        );
    }

    #[Test]
    public function one_occupied_sheet_is_chosen_and_several_are_asked_about(): void
    {
        $single = $this->parser()->describe(
            GenericSpreadsheetBuilder::multiDomain()->onlySheetNamed('Notas')->writeXlsx($this->path('a.xlsx')),
            $this->mapping(),
        );

        $this->assertSame('Notas', $single['selected_sheet']);
        $this->assertFalse($single['needs_sheet_choice']);

        $several = $this->parser()->describe(
            GenericSpreadsheetBuilder::multiDomain()
                ->onlySheetNamed('1.º Período')
                ->sheet('2.º Período')->rows([['Nome', 'Leitura'], ['Ana Exemplo', 15]])
                ->writeXlsx($this->path('b.xlsx')),
            $this->mapping(),
        );

        // Two sheets with content is a question, not a default. Picking the
        // first because it is first is the guess this refuses (§11).
        $this->assertNull($several['selected_sheet']);
        $this->assertTrue($several['needs_sheet_choice']);
        $this->assertSame(['1.º Período', '2.º Período'], array_column($several['sheets'], 'name'));
    }

    #[Test]
    public function an_empty_second_sheet_is_not_a_choice(): void
    {
        $description = $this->parser()->describe(
            GenericSpreadsheetBuilder::multiDomain()
                ->onlySheetNamed('Notas')
                ->sheet('Folha2')->rows([])
                ->writeXlsx($this->path('c.xlsx')),
            $this->mapping(),
        );

        $this->assertSame('Notas', $description['selected_sheet']);
        $this->assertFalse($description['needs_sheet_choice']);
    }

    // ------------------------------------------------------- 8. percentages (§24)

    #[Test]
    public function a_percent_formatted_cell_reads_as_the_percentage_it_displays(): void
    {
        $path = GenericSpreadsheetBuilder::make()
            ->rows([['Nome', 'Nota'], ['Ana Exemplo', 0.75]])
            ->percentageColumn('B')
            ->writeXlsx($this->path('pct.xlsx'));

        $grid = $this->parser()->parseWith($path, 'pct.xlsx', $this->mapping([
            'sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A',
            'result_columns' => ['B'], 'value_kind' => TabularMapping::VALUE_PERCENTAGE,
        ]));

        // Excel stores 0,75 and shows 75%. What the teacher sees is what counts.
        $this->assertSame('75', $grid->students[0]->sourceScore);
    }

    #[Test]
    public function a_bare_decimal_is_never_read_as_a_percentage(): void
    {
        $path = GenericSpreadsheetBuilder::make()
            ->rows([['Nome', 'Nota'], ['Ana Exemplo', 0.75]])
            ->writeXlsx($this->path('plain.xlsx'));

        $description = $this->parser()->describe($path, $this->mapping());

        $this->assertFalse($description['sample'][1]['cells'][1]['is_percentage']);
        $this->assertSame('0.75', $description['sample'][1]['cells'][1]['number']);
    }

    // ---------------------------------------------------------- 9. formulas (§28)

    #[Test]
    public function a_formula_is_refused_and_never_evaluated(): void
    {
        $path = GenericSpreadsheetBuilder::make()
            ->rows([['Nome', 'Leitura', 'Total'], ['Ana Exemplo', 14, null]])
            ->formulaAt('C2', '=B2*2')
            ->writeXlsx($this->path('formula.xlsx'));

        $grid = $this->parser()->parseWith($path, 'formula.xlsx', $this->mapping([
            'sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B', 'C'],
        ], ImportMapping::RESULT_PER_GROUP));

        $this->assertFalse($grid->canBeConfirmed());

        $message = $grid->errors()[0]->message;
        $this->assertStringContainsString('fórmulas', $message);
        $this->assertStringContainsString('C', $message);

        // And emphatically not the answer: 28 never appears anywhere.
        $this->assertStringNotContainsString('28', (string) json_encode($grid->toArray()));
    }

    // ------------------------------------------------------ 10. security (§27)

    #[Test]
    public function a_workbook_carrying_a_macro_project_is_refused(): void
    {
        $path = GenericSpreadsheetBuilder::multiDomain()->writeXlsx($this->path('macro.xlsx'));

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/vbaProject.bin', 'not really a macro, but it is enough to be refused');
        $zip->close();

        $this->assertFalse($this->parser()->supports($path, 'macro.xlsx'));

        $grid = $this->parser()->parseWith($path, 'macro.xlsx', $this->mapping());
        $this->assertFalse($grid->canBeConfirmed());
        $this->assertStringContainsString('macros', $grid->errors()[0]->message);
    }

    #[Test]
    public function a_workbook_linking_to_another_document_is_refused(): void
    {
        $path = GenericSpreadsheetBuilder::multiDomain()->writeXlsx($this->path('linked.xlsx'));

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/externalLinks/externalLink1.xml', '<externalLink/>');
        $zip->close();

        $this->assertFalse($this->parser()->supports($path, 'linked.xlsx'));
    }

    // ------------------------------------------------------- 11. privacy (§13)

    #[Test]
    public function ooxml_metadata_never_reaches_the_snapshot(): void
    {
        $path = GenericSpreadsheetBuilder::multiDomain()->writeXlsx($this->path('meta.xlsx'));

        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getProperties()
            ->setCreator('Professora Inventada')
            ->setLastModifiedBy('Professora Inventada')
            ->setTitle('C:\\uma\\pasta\\qualquer');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $grid = $this->parser()->parseWith($path, 'meta.xlsx', $this->mapping([
            'sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B'],
        ], ImportMapping::RESULT_PER_GROUP));

        $encoded = (string) json_encode($grid->toArray());
        $this->assertStringNotContainsString('Professora Inventada', $encoded);
        $this->assertStringNotContainsString('uma', $encoded);

        // And the metadata column, which travels separately, carries only facts
        // about the container.
        $this->assertSame(
            ['kind', 'sheets', 'sheet', 'header_row', 'columns', 'result_columns'],
            array_keys($grid->sourceMetadata),
        );
    }

    #[Test]
    public function the_source_metadata_of_a_csv_says_nothing_about_who_is_in_it(): void
    {
        $grid = $this->parseCsv(
            GenericSpreadsheetBuilder::multiDomain(),
            ['sheet' => 'Folha 1', 'header_row' => 1, 'student_column' => 'A', 'result_columns' => ['B']],
            ImportMapping::RESULT_PER_GROUP,
        );

        $encoded = (string) json_encode($grid->sourceMetadata);

        foreach (['Ana', 'Bruno', 'Carla', 'Diogo'] as $name) {
            $this->assertStringNotContainsString($name, $encoded);
        }

        $this->assertSame('csv', $grid->sourceMetadata['kind']);
        $this->assertSame(';', $grid->sourceMetadata['delimiter']);
    }

    // -------------------------------------------------------- 12. suggestions

    #[Test]
    public function it_suggests_a_structure_without_reading_any_heading(): void
    {
        $description = $this->parser()->describe(
            GenericSpreadsheetBuilder::make()->rows([
                // Headings that say nothing useful, on purpose: the suggestion
                // has to come from the SHAPE of the cells, not from words.
                ['Coluna 1', 'Coluna 2', 'Coluna 3'],
                ['Ana Exemplo', 14, 12],
                ['Bruno Teste', 16, 10],
            ])->writeCsv($this->path('n.csv')),
            $this->mapping(),
        );

        $this->assertSame(1, $description['suggestions']['header_row']);
        $this->assertSame('A', $description['suggestions']['student_column']);
        $this->assertSame(['B', 'C'], $description['suggestions']['result_columns']);
        // A total column is never suggested — a column called «Total» might be a
        // section called Total (§31).
        $this->assertArrayNotHasKey('total_column', $description['suggestions']);
    }

    #[Test]
    public function a_suggestion_is_not_a_decision(): void
    {
        $path = GenericSpreadsheetBuilder::overall()->writeCsv($this->path('n.csv'));

        // Nothing chosen: the grid refuses rather than proceeding on what it
        // would have suggested (§3, §14).
        $grid = $this->parser()->parse($path, 'notas.csv');

        $this->assertFalse($grid->canBeConfirmed());
        $this->assertSame([], $grid->students);
        $this->assertSame([], $grid->items);

        // And it says what is missing, in the order the wizard asks.
        $this->assertStringContainsString('linha com os títulos', $grid->errors()[0]->message);
    }
}
