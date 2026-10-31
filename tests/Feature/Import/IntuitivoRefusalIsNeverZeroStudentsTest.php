<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\TabularMapping;
use App\Models\CorrectionImport;
use App\Services\Import\Correction\GenericSpreadsheetParser;
use App\Services\Import\Correction\IntuitivoXlsxParser;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Import\IntuitivoWorkbookBuilder;

/**
 * A file the Intuitivo reader refuses must never reach the wizard as
 * «0 alunos no ficheiro».
 *
 * That sentence reads as a finding about the class; the real finding — which
 * column, which cell, what to do — was sitting in an issue list further down the
 * page. A refusal is said on the upload form, in the teacher's words, and nothing
 * is stored. A file that IS read keeps arriving whole, with the class it was
 * uploaded against playing no part in how many students it has.
 *
 * Every workbook is invented (§10).
 */
class IntuitivoRefusalIsNeverZeroStudentsTest extends CorrectionImportHttpTest
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/intuitivo-refusal-'.bin2hex(random_bytes(5)).'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    /**
     * @return TestResponse<Response>
     */
    protected function upload_book(IntuitivoWorkbookBuilder $builder, ?callable $tamper = null, string $source = 'intuitivo'): TestResponse
    {
        Storage::fake('local');

        $builder->writeTo($this->path);

        if ($tamper !== null) {
            $spreadsheet = IOFactory::load($this->path);
            $tamper($spreadsheet->getSheetByName('Notas'));
            (new Xlsx($spreadsheet))->save($this->path);
        }

        return $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => $source,
            'file' => new UploadedFile($this->path, 'FICHA DE AVALIAÇÃO DE PORTUGUÊS 1 - 7.º A - 2026_2027.xlsx', null, null, true),
        ]);
    }

    protected function imports(): int
    {
        return app(CurrentOrganization::class)->runFor($this->organization, fn (): int => CorrectionImport::count());
    }

    // ------------------------------------------------------------ the refusals

    /**
     * @return array<string, array{0: \Closure, 1: string}>
     */
    public static function structurallyIncompatibleFiles(): array
    {
        return [
            'a text where a mark should be' => [fn (Worksheet $s) => $s->setCellValue('B3', 'Ausente'), 'B3 não contém um número'],
            'a column with marks and no group' => [fn (Worksheet $s) => $s->unmergeCells('B1:E1'), 'não pertence a nenhum grupo'],
            'a question without its maximum' => [fn (Worksheet $s) => $s->setCellValue('B2', 'Item 1'), 'não declara a cotação máxima'],
            'a formula in a mark' => [fn (Worksheet $s) => $s->setCellValue('B3', '=1+1'), 'contém uma fórmula'],
            'marks after the total' => [fn (Worksheet $s) => $s->setCellValue('V3', 7), 'depois do total'],
            'no student column' => [fn (Worksheet $s) => $s->setCellValue('A1', 'Aluno'), 'Nome do estudante'],
        ];
    }

    #[Test]
    #[DataProvider('structurallyIncompatibleFiles')]
    public function a_refused_workbook_is_said_on_the_upload_form_and_stores_nothing(callable $tamper, string $because): void
    {
        $response = $this->upload_book(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), $tamper);

        $response->assertSessionHasErrors('file');
        $message = session('errors')->first('file');

        // The cause, concretely, and a way out.
        $this->assertStringContainsString($because, $message);
        $this->assertStringContainsString('Folha de cálculo', $message);
        $this->assertStringNotContainsString('0 alunos', $message);

        $this->assertSame(0, $this->imports());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function a_refusal_is_the_same_whichever_class_was_chosen(): void
    {
        // The class plays no part in whether a file can be read: the same
        // refusal, the same sentence, for a class that has nothing to do with it.
        $this->upload_book(IntuitivoWorkbookBuilder::withSingleColumnLastGroup(), fn (Worksheet $s) => $s->setCellValue('B3', 'Faltou'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, $this->imports());
    }

    // ------------------------------------------------- the file that is read

    #[Test]
    public function the_observed_shape_arrives_with_every_student_whatever_the_class(): void
    {
        // 21 students, 17 questions, a one-column last group, Total 100 — and a
        // class whose three enrolments share no name with them.
        $this->withoutVite();
        $this->upload_book(IntuitivoWorkbookBuilder::withSingleColumnLastGroup())->assertRedirect();

        $import = app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::firstOrFail());

        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $preview = $page->toArray()['props']['preview'];

                $this->assertSame(21, $preview['counts']['students_in_file']);
                $this->assertSame(17, $preview['counts']['questions']);
                $this->assertCount(21, $preview['students']);

                // Nobody in this class carries those names: they are UNMATCHED,
                // never dropped, and the page says so.
                $this->assertSame(21, $preview['counts']['unmatched']);
                $this->assertFalse($preview['can_confirm']);
            });
    }

    #[Test]
    public function zero_blank_and_decimal_marks_keep_their_meaning_through_upload(): void
    {
        $builder = IntuitivoWorkbookBuilder::withSingleColumnLastGroup();

        $this->upload_book($builder, function (Worksheet $s): void {
            $s->setCellValue('B3', 0);       // a real zero
            $s->setCellValue('C3', null);    // blank: no mark, no opinion
            $s->setCellValue('D3', 2.75);    // a decimal
        })->assertRedirect();

        $snapshot = app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::firstOrFail()->canonical_snapshot);

        $first = [];

        foreach ($snapshot['results'] as $result) {
            if ($result['student_source_key'] === 'student:3') {
                $first[$result['item_source_key']] = $result;
            }
        }

        $this->assertSame('0', $first['item:group:1:B']['points_earned']);
        $this->assertArrayNotHasKey('item:group:1:C', $first);
        $this->assertSame('2.75', $first['item:group:1:D']['points_earned']);
        $this->assertCount(21, $snapshot['students']);

        // The question's maximum is the maximum, not a mark.
        $maxima = array_column($snapshot['items'], 'points_possible', 'source_key');
        $this->assertSame('4', $maxima['item:group:1:B']);
    }

    #[Test]
    public function the_source_totals_are_carried_as_totals_and_not_as_marks(): void
    {
        $this->upload_book(IntuitivoWorkbookBuilder::withSingleColumnLastGroup())->assertRedirect();

        $snapshot = app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::firstOrFail()->canonical_snapshot);

        $this->assertCount(21, $snapshot['summaries']);
        $this->assertCount(357, $snapshot['results']); // 21 × 17: no total, no percentage.
        $this->assertSame('100', $snapshot['instrument']['source_total']);
    }

    // ------------------------------------- the header the teacher named herself

    protected function parseTampered(callable $tamper): CanonicalCorrectionGrid
    {
        IntuitivoWorkbookBuilder::withSingleColumnLastGroup()->writeTo($this->path);
        $spreadsheet = IOFactory::load($this->path);
        $tamper($spreadsheet->getSheetByName('Notas'));
        (new Xlsx($spreadsheet))->save($this->path);

        return (new IntuitivoXlsxParser)->parse($this->path, 'ficha.xlsx');
    }

    #[Test]
    public function a_group_header_with_a_name_after_the_maximum_is_read(): void
    {
        // The real file: "GRUPO V (Máximo: 24.00)- Expressão escrita", a vertical
        // merge with the maximum in the MIDDLE of the text.
        $grid = $this->parseTampered(function (Worksheet $s): void {
            $s->setCellValue('R1', 'GRUPO V (Máximo: 24.00)- Expressão escrita');
            $s->setCellValue('B1', 'GRUPO I Oralidade');
        });

        $this->assertTrue($grid->canBeConfirmed());
        $this->assertCount(21, $grid->students);
        $this->assertCount(17, $grid->items);
        $this->assertCount(357, $grid->results);
        $this->assertSame('24', $grid->items[16]->pointsPossible);
        $this->assertSame('GRUPO V - Expressão escrita', $grid->groups[4]->label);
        $this->assertSame('GRUPO I Oralidade', $grid->groups[0]->label);
    }

    #[Test]
    public function two_maxima_in_one_header_stay_ambiguous_and_are_refused(): void
    {
        $grid = $this->parseTampered(fn (Worksheet $s) => $s->setCellValue('R1', 'GRUPO V (Máximo: 24.00) e (Máximo: 20.00)'));

        $this->assertSame([], $grid->students);
        $this->assertStringContainsString('não declara a cotação máxima', $grid->errors()[0]->message);
    }

    #[Test]
    public function the_spreadsheet_source_reads_the_same_file_but_asks_for_the_cotacoes(): void
    {
        // What the refusal message promises, and no more.
        IntuitivoWorkbookBuilder::withSingleColumnLastGroup()->writeTo($this->path);

        $mapping = new ImportMapping(
            resultMode: 'per_question',
            table: new TabularMapping(sheet: 'Notas', headerRow: 2, studentColumn: 'A', resultColumns: range('B', 'R')),
        );
        $grid = app(GenericSpreadsheetParser::class)->parseWith($this->path, 'ficha.xlsx', $mapping);

        $this->assertCount(21, $grid->students);
        $this->assertCount(17, $grid->items);
        $this->assertCount(357, $grid->results);
        $this->assertSame([], $grid->groups);
        $this->assertNotSame([], $grid->errors());
    }

    // ------------------------------------------------ the others do not move

    #[Test]
    public function the_parser_still_answers_with_a_refusal_for_direct_callers(): void
    {
        // The seam is unchanged: the parser refuses by returning, and the
        // controller decides what to do about it.
        IntuitivoWorkbookBuilder::withSingleColumnLastGroup()->writeTo($this->path);
        $spreadsheet = IOFactory::load($this->path);
        $spreadsheet->getSheetByName('Notas')->setCellValue('B3', 'Faltou');
        (new Xlsx($spreadsheet))->save($this->path);

        $grid = (new IntuitivoXlsxParser)->parse($this->path, 'ficha.xlsx');

        $this->assertSame([], $grid->students);
        $this->assertFalse($grid->canBeConfirmed());
    }

    #[Test]
    public function plickers_is_still_read_and_still_refused_as_before(): void
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'file' => $this->fixture(),
        ])->assertRedirect();

        $this->assertSame(1, $this->imports());

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'file' => $this->fixture('plickers-malformado.csv'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(1, $this->imports());
    }
}
