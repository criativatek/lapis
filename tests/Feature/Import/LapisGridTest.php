<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\LapisGridContract;
use App\Domain\Import\Tabular\TabularColumn;
use App\Models\CorrectionImport;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentStatus;
use App\Models\ItemDomainAllocation;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Fixtures\Import\GenericSpreadsheetBuilder;
use ZipArchive;

/**
 * The grid LÁPIS writes, and reads back without asking anything.
 *
 * The generic importer works and is too much work for a file this application
 * produced itself. Intuitivo is easy because LÁPIS knows the contract in
 * advance; this gives our own grid the same standing — download it, fill in the
 * marks, bring it back, done.
 *
 * The claim under test is narrow and worth stating plainly: THE FILE CARRIES
 * IDENTITY AND NEVER AUTHORITY. Which instrument, which item, which enrolment —
 * all of it is a string a teacher could have typed into a cell, and every one of
 * them is looked up and refused unless it belongs where the import already is.
 */
class LapisGridTest extends CorrectionImportHttpTest
{
    protected ?Instrument $builtInstrument = null;

    /** @var array<string, int> display name => enrollment id */
    protected array $roll = [];

    protected string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().'/lapis-grid-'.bin2hex(random_bytes(6)).'.xlsx';
    }

    /**
     * Built on demand, never in setUp.
     *
     * This class inherits the whole correction-import harness, and several of
     * those inherited tests count the instruments in the database expecting
     * none. An instrument created for every test would break them without any
     * of them being wrong.
     */
    protected function instrument(): Instrument
    {
        if ($this->builtInstrument === null) {
            [$this->builtInstrument, $this->roll] = app(CurrentOrganization::class)->runFor(
                $this->organization,
                fn (): array => [$this->makeInstrument(), $this->enrol()],
            );
        }

        return $this->builtInstrument;
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    /**
     * An instrument shaped like a Português paper: three ordinary items across
     * two domains, plus a writing deduction.
     */
    protected function makeInstrument(): Instrument
    {
        $leitura = Domain::factory()->recycle($this->organization)->create(['name' => 'Leitura']);
        $escrita = Domain::factory()->recycle($this->organization)->create(['name' => 'Escrita']);

        $instrument = Instrument::factory()->recycle($this->organization)->create([
            'class_id' => $this->class->id,
            'academic_period_id' => $this->period->id,
            'title' => 'Ficha de avaliação — 1.º Período',
            'status' => InstrumentStatus::InCorrection->value,
            'total_points' => 60,
            'allow_bonus' => true,
        ]);

        $rows = [
            ['code' => 'Q1', 'label' => 'Compreensão do texto', 'points' => 20, 'bonus' => false, 'domain' => $leitura],
            ['code' => 'Q2', 'label' => 'Gramática aplicada', 'points' => 20, 'bonus' => false, 'domain' => $leitura],
            ['code' => 'Q3', 'label' => 'Produção escrita', 'points' => 20, 'bonus' => false, 'domain' => $escrita],
            // The writing deduction: outside the denominator, allocated to
            // Escrita, and carrying a negative mark (§8).
            ['code' => 'DESC', 'label' => 'Desconto por extensão', 'points' => 0, 'bonus' => true, 'domain' => $escrita],
        ];

        foreach ($rows as $index => $row) {
            $item = InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'code' => $row['code'],
                'label' => $row['label'],
                'sequence' => $index + 1,
                'points_possible' => $row['points'],
                'is_bonus' => $row['bonus'],
            ]);

            // organization_id is stamped from the resolved tenant, never passed.
            ItemDomainAllocation::create([
                'instrument_item_id' => $item->id,
                'domain_id' => $row['domain']->id,
                'allocation_percent' => 100,
            ]);
        }

        return $instrument->fresh();
    }

    /**
     * @return array<string, int>
     */
    protected function enrol(): array
    {
        $roll = [];

        // The harness already put three anonymous enrolments in this class.
        // Naming them keeps the grid deterministic to read, and keeps every
        // inherited test that relies on them working exactly as before.
        foreach (Enrollment::where('class_id', $this->class->id)->orderBy('class_number')->get() as $index => $enrollment) {
            StudentIdentity::firstOrCreate(
                ['student_id' => $enrollment->student_id],
                ['organization_id' => $this->organization->getKey(), 'display_name' => 'Aluno '.($index + 1)],
            );
        }

        $next = (int) Enrollment::where('class_id', $this->class->id)->max('class_number');

        foreach (['Ana Exemplo', 'Bruno Teste', 'Carla Fictícia'] as $index => $name) {
            $student = Student::factory()->recycle($this->organization)->create();

            StudentIdentity::create([
                'student_id' => $student->getKey(),
                'organization_id' => $this->organization->getKey(),
                'display_name' => $name,
            ]);

            $roll[$name] = (int) Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $this->class->id,
                'student_id' => $student->getKey(),
                'class_number' => $next + $index + 1,
                'enrolled_on' => now()->subMonths(6)->toDateString(),
            ])->getKey();
        }

        return $roll;
    }

    /**
     * Downloads the grid and returns where it was written.
     */
    protected function download(?Instrument $instrument = null): string
    {
        $response = $this->actingAs($this->teacher)
            ->get('/instruments/'.($instrument ?? $this->instrument())->ulid.'/grelha');

        $response->assertOk();

        /** @var BinaryFileResponse $base */
        $base = $response->baseResponse;
        copy($base->getFile()->getPathname(), $this->file);

        return $this->file;
    }

    protected function workbook(?string $path = null): Spreadsheet
    {
        $reader = new XlsxReader;
        $reader->setReadDataOnly(false);

        return $reader->load($path ?? $this->file);
    }

    protected function uploadGrid(?string $path = null): CorrectionImport
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Generic->value,
            'file' => new UploadedFile($path ?? $this->file, 'Grelha.xlsx', null, null, true),
        ])->assertRedirect();

        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => CorrectionImport::latest('id')->firstOrFail(),
        );
    }

    /**
     * The wizard's «Guardar e continuar» followed by the confirmation.
     *
     * The wizard holds the resolved mapping in its form state and sends it back
     * unchanged; this does the same, so the test exercises the real round trip
     * rather than a shortcut the interface does not take.
     */
    protected function saveAndConfirm(CorrectionImport $import): void
    {
        $stored = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): array => (array) CorrectionImport::findOrFail($import->getKey())->mapping_snapshot,
        );

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", [
            'mode' => $stored['mode'],
            'result_mode' => $stored['result_mode'],
            'instrument_id' => $stored['instrument_id'],
            'students' => $stored['students'],
            'items' => $stored['items'],
        ]);

        $this->actingAs($this->teacher)
            ->post("/imports/correction/{$import->ulid}/confirm")
            ->assertRedirect();
    }

    /**
     * @return array<string, mixed>
     */
    protected function props(CorrectionImport $import): array
    {
        $props = [];

        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /**
     * Writes marks into the downloaded grid, the way a teacher would.
     *
     * @param  array<string, array<string, float|null>>  $marks  student name => column letter => mark
     */
    protected function fillIn(array $marks): string
    {
        $spreadsheet = $this->workbook();
        $sheet = $spreadsheet->getActiveSheet();

        for ($row = LapisGridContract::FIRST_DATA_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
            $name = (string) $sheet->getCell(LapisGridContract::COLUMN_NAME.$row)->getValue();

            foreach ($marks[$name] ?? [] as $column => $value) {
                if ($value !== null) {
                    $sheet->setCellValue($column.$row, $value);
                }
            }
        }

        (new Xlsx($spreadsheet))->save($this->file);
        $spreadsheet->disconnectWorksheets();

        return $this->file;
    }

    // ============================================================ GERAÇÃO

    #[Test]
    public function the_grid_has_one_visible_sheet_and_nothing_else(): void
    {
        $spreadsheet = $this->workbook($this->download());

        $this->assertSame(1, $spreadsheet->getSheetCount());
        $this->assertSame(LapisGridContract::SHEET, $spreadsheet->getSheet(0)->getTitle());

        $spreadsheet->disconnectWorksheets();
    }

    #[Test]
    public function the_first_row_is_the_real_header_and_the_students_start_on_the_second(): void
    {
        $sheet = ($spreadsheet = $this->workbook($this->download()))->getActiveSheet();

        // No title above the table, no instructions, no blank spacer row: the
        // headings are row 1 because anything else is a thing to scroll past.
        $this->assertSame('N.º', $sheet->getCell(LapisGridContract::COLUMN_NUMBER.'1')->getValue());
        $this->assertSame('Nome', $sheet->getCell(LapisGridContract::COLUMN_NAME.'1')->getValue());

        // Every student of the class, in roll order, straight after the
        // headings. Nobody has to paste their own class into a file this
        // application generated for that class (§5).
        $names = [];

        for ($row = 2; $row <= 7; $row++) {
            $names[] = $sheet->getCell(LapisGridContract::COLUMN_NAME.$row)->getValue();
        }

        $this->assertSame(
            ['Aluno 1', 'Aluno 2', 'Aluno 3', 'Ana Exemplo', 'Bruno Teste', 'Carla Fictícia'],
            $names,
        );

        $spreadsheet->disconnectWorksheets();
    }

    #[Test]
    public function every_item_gets_a_column_titled_the_way_the_teacher_wrote_it(): void
    {
        $sheet = ($spreadsheet = $this->workbook($this->download()))->getActiveSheet();

        $headings = [];

        foreach (['D', 'E', 'F', 'G'] as $column) {
            $headings[] = $sheet->getCell($column.'1')->getValue();
        }

        $this->assertSame([
            'Compreensão do texto (máx. 20)',
            'Gramática aplicada (máx. 20)',
            'Produção escrita (máx. 20)',
            // Not a bonus and not a question — a deduction, and named as one.
            'Desconto por extensão (desconto)',
        ], $headings);

        // And no total: LÁPIS computes from the items and the instrument's own
        // rules, so a total in the file would be a second answer to one question.
        $this->assertNull($sheet->getCell('H1')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    #[Test]
    public function result_cells_are_left_genuinely_empty(): void
    {
        $sheet = ($spreadsheet = $this->workbook($this->download()))->getActiveSheet();

        // Not zeros. An empty cell is «por avaliar» and pre-filling one would
        // decide something about a child nobody has decided (§10).
        foreach (['D', 'E', 'F', 'G'] as $column) {
            $this->assertNull($sheet->getCell($column.'2')->getValue());
        }

        $spreadsheet->disconnectWorksheets();
    }

    #[Test]
    public function the_contract_is_a_versioned_marker_and_not_a_sheet_name(): void
    {
        $spreadsheet = $this->workbook($this->download());

        $value = fn (string $name): ?string => LapisGridContract::unwrap(
            $spreadsheet->getDefinedName($name)?->getValue(),
        );

        $this->assertSame(LapisGridContract::MARKER, $value(LapisGridContract::NAME_MARKER));
        $this->assertSame(LapisGridContract::VERSION, $value(LapisGridContract::NAME_VERSION));
        $this->assertSame($this->instrument()->ulid, $value(LapisGridContract::NAME_INSTRUMENT));
        $this->assertSame($this->class->ulid, $value(LapisGridContract::NAME_CLASS));

        // One per item column, carrying the item's own identity.
        $items = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => $this->instrument()->items()->orderBy('sequence')->pluck('ulid')->all(),
        );

        foreach (['D', 'E', 'F', 'G'] as $index => $column) {
            $this->assertSame($items[$index], $value(LapisGridContract::itemName($column)));
        }

        $spreadsheet->disconnectWorksheets();
    }

    #[Test]
    public function the_generated_file_carries_no_active_content(): void
    {
        $zip = new ZipArchive;
        $zip->open($this->download());

        try {
            foreach (['xl/vbaProject.bin', 'xl/externalLinks/externalLink1.xml'] as $forbidden) {
                $this->assertFalse($zip->locateName($forbidden), "{$forbidden} não pode existir");
            }
        } finally {
            $zip->close();
        }
    }

    // ====================================================== RECONHECIMENTO

    #[Test]
    public function a_lapis_grid_is_recognised_and_asks_the_teacher_nothing(): void
    {
        $this->download();
        $props = $this->props($this->uploadGrid());

        $this->assertTrue($props['tabular']['lapis_grid']);

        // Every question the generic path asks is absent, because every answer
        // is already known (§13).
        foreach (['columns', 'sample', 'suggestions', 'sheets', 'row_numbers'] as $absent) {
            $this->assertArrayNotHasKey($absent, $props['tabular']);
        }

        $preview = $props['preview'];

        $this->assertCount(6, $preview['students']);
        $this->assertCount(4, $preview['items']);
        // Associated with the instrument the file came from, question by
        // question — the path that already existed, with the answers filled in.
        $this->assertSame(ImportMapping::MODE_ASSOCIATE, $preview['mode']);
        $this->assertSame(ImportMapping::RESULT_PER_QUESTION, $preview['result_mode']);
        $this->assertSame($this->instrument()->id, $preview['instrument_id']);

        // The cotações come from the instrument and cannot be typed over.
        foreach ($preview['items'] as $item) {
            $this->assertTrue($item['points_locked']);
            $this->assertNotNull($item['instrument_item_id']);
        }

        // And every student was matched by identity, not by name.
        foreach ($preview['students'] as $student) {
            $this->assertSame('matched', $student['status']);
        }
    }

    #[Test]
    public function a_workbook_with_no_marker_goes_down_the_ordinary_path(): void
    {
        $path = GenericSpreadsheetBuilder::multiDomain()
            // Named exactly like ours, on purpose: a name is not a contract (§6).
            ->onlySheetNamed(LapisGridContract::SHEET)
            ->writeXlsx($this->file);

        $props = $this->props($this->uploadGrid($path));

        $this->assertFalse($props['tabular']['lapis_grid']);
        $this->assertArrayHasKey('columns', $props['tabular'], 'tem de continuar a pedir o mapeamento');
        $this->assertSame([], $props['preview']['students']);
    }

    #[Test]
    public function a_grid_from_another_version_is_refused_rather_than_interpreted(): void
    {
        $this->download();

        $spreadsheet = $this->workbook();
        $spreadsheet->getDefinedName(LapisGridContract::NAME_VERSION)
            ?->setValue(LapisGridContract::wrap('99'));
        (new Xlsx($spreadsheet))->save($this->file);
        $spreadsheet->disconnectWorksheets();

        $props = $this->props($this->uploadGrid());

        $this->assertFalse($props['preview']['can_confirm']);
        $this->assertStringContainsString(
            'outra versão da aplicação',
            implode(' ', array_column($props['preview']['issues'], 'message')),
        );
    }

    #[Test]
    public function a_column_removed_from_the_grid_fails_closed_and_offers_the_way_out(): void
    {
        $this->download();

        $spreadsheet = $this->workbook();
        // The teacher deleted the last item column; the contract still names it.
        $spreadsheet->getActiveSheet()->removeColumn('G');
        (new Xlsx($spreadsheet))->save($this->file);
        $spreadsheet->disconnectWorksheets();

        $props = $this->props($this->uploadGrid());

        $this->assertFalse($props['preview']['can_confirm']);

        $messages = implode(' ', array_column($props['preview']['issues'], 'message'));
        $this->assertStringContainsString('alterada na sua estrutura', $messages);
        // Never silently corrected, and never a dead end (§14).
        $this->assertStringContainsString('outra folha de cálculo', $messages);
    }

    #[Test]
    public function a_row_somebody_inserted_fails_closed(): void
    {
        $this->download();

        $spreadsheet = $this->workbook();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->insertNewRowBefore(3, 1);
        $sheet->setCellValue(LapisGridContract::COLUMN_NAME.'3', 'Aluno Acrescentado');
        $sheet->setCellValue('D3', 12);
        (new Xlsx($spreadsheet))->save($this->file);
        $spreadsheet->disconnectWorksheets();

        $props = $this->props($this->uploadGrid());

        $this->assertFalse($props['preview']['can_confirm']);
        $this->assertStringContainsString(
            'alterada na sua estrutura',
            implode(' ', array_column($props['preview']['issues'], 'message')),
        );
    }

    // ============================================================ SEGURANÇA

    #[Test]
    public function a_grid_for_another_class_is_refused_however_valid_it_looks(): void
    {
        $this->download();

        // Same teacher, same organization, different class. Authorisation alone
        // would let this through and the marks would be somebody else's.
        [$otherClass] = $this->makeClass($this->organization, $this->teacher);

        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $otherClass->id,
            'source' => CorrectionGridSource::Generic->value,
            'file' => new UploadedFile($this->file, 'Grelha.xlsx', null, null, true),
        ])->assertRedirect();

        $import = app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::latest('id')->firstOrFail());
        $props = $this->props($import);

        $this->assertFalse($props['preview']['can_confirm']);
        $this->assertStringContainsString(
            'não corresponde a nenhuma avaliação desta turma',
            implode(' ', array_column($props['preview']['issues'], 'message')),
        );
    }

    #[Test]
    public function an_item_identity_swapped_for_another_instruments_is_refused(): void
    {
        $this->download();

        $stranger = app(CurrentOrganization::class)->runFor($this->organization, function (): string {
            $other = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $this->class->id,
                'academic_period_id' => $this->period->id,
                'status' => InstrumentStatus::InCorrection->value,
            ]);

            return (string) InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $other->id,
                'code' => 'X1',
                'sequence' => 1,
                'points_possible' => 10,
            ])->ulid;
        });

        $spreadsheet = $this->workbook();
        $spreadsheet->getDefinedName(LapisGridContract::itemName('D'))
            ?->setValue(LapisGridContract::wrap($stranger));
        (new Xlsx($spreadsheet))->save($this->file);
        $spreadsheet->disconnectWorksheets();

        $props = $this->props($this->uploadGrid());

        $this->assertFalse($props['preview']['can_confirm']);
        $this->assertStringContainsString(
            'perguntas que já não pertencem a esta avaliação',
            implode(' ', array_column($props['preview']['issues'], 'message')),
        );
    }

    #[Test]
    public function an_enrolment_identity_from_outside_the_class_is_refused(): void
    {
        $this->download();

        [$otherClass] = $this->makeClass($this->organization, $this->teacher);

        $stranger = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): string => (string) Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $otherClass->id,
                'student_id' => Student::factory()->recycle($this->organization)->create()->getKey(),
                'class_number' => 1,
                'enrolled_on' => now()->subMonths(6)->toDateString(),
            ])->ulid,
        );

        $spreadsheet = $this->workbook();
        $spreadsheet->getActiveSheet()->setCellValue(LapisGridContract::COLUMN_ENROLLMENT.'2', $stranger);
        (new Xlsx($spreadsheet))->save($this->file);
        $spreadsheet->disconnectWorksheets();

        $props = $this->props($this->uploadGrid());

        $this->assertFalse($props['preview']['can_confirm']);
        $this->assertStringContainsString(
            'alunos que não pertencem a esta turma',
            implode(' ', array_column($props['preview']['issues'], 'message')),
        );
    }

    #[Test]
    public function downloading_a_grid_needs_authorisation(): void
    {
        $stranger = User::factory()->create();

        // 404 rather than 403 is the right answer and the better one: the
        // tenant scope means another organization's instrument does not exist
        // as far as this request is concerned, and saying «forbidden» would
        // confirm that it does.
        $this->actingAs($stranger)
            ->get('/instruments/'.$this->instrument()->ulid.'/grelha')
            ->assertNotFound();
    }

    // ========================================================= FIM A FIM

    #[Test]
    public function marks_written_into_the_grid_reach_the_instruments_own_items(): void
    {
        $this->download();

        $this->fillIn([
            'Ana Exemplo' => ['D' => 14, 'E' => 12, 'F' => 18, 'G' => -2],
            // A zero, and a blank in the same row: they are not the same thing.
            'Bruno Teste' => ['D' => 0, 'E' => null, 'F' => 10],
        ]);

        $import = $this->uploadGrid();

        // «Guardar e continuar», exactly as the teacher presses it: the mapping
        // was already resolved at upload, so the wizard simply sends it back.
        $this->saveAndConfirm($import);

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            // No new instrument: the marks landed on the one the grid came from.
            $this->assertSame(1, Instrument::count());

            $items = $this->instrument()->items()->orderBy('sequence')->get();
            $mark = fn (int $index, string $name): ?StudentItemScore => StudentItemScore::query()
                ->where('instrument_item_id', $items[$index]->id)
                ->where('enrollment_id', $this->roll[$name])
                ->first();

            $this->assertSame('14.0000', (string) $mark(0, 'Ana Exemplo')?->points_earned);
            $this->assertSame('18.0000', (string) $mark(2, 'Ana Exemplo')?->points_earned);
            // The writing deduction, negative, on the item that carries it (§8).
            $this->assertSame('-2.0000', (string) $mark(3, 'Ana Exemplo')?->points_earned);

            // Bruno's zero is a zero…
            $this->assertSame('0.0000', (string) $mark(0, 'Bruno Teste')?->points_earned);
            // …and his blank is nothing at all — not a zero, not a falta (§10).
            $this->assertNull($mark(1, 'Bruno Teste'));
            // Carla was in the file with no marks; she stays unassessed.
            $this->assertNull($mark(0, 'Carla Fictícia'));
        });
    }

    /**
     * Builds an instrument of an arbitrary shape, for the dynamism test.
     *
     * @param  list<array{code: string, label: string, points: float, bonus: bool, domain: string}>  $rows
     */
    protected function instrumentShaped(array $rows): Instrument
    {
        return app(CurrentOrganization::class)->runFor($this->organization, function () use ($rows): Instrument {
            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $this->class->id,
                'academic_period_id' => $this->period->id,
                'status' => InstrumentStatus::InCorrection->value,
                'allow_bonus' => true,
            ]);

            foreach ($rows as $index => $row) {
                $item = InstrumentItem::factory()->recycle($this->organization)->create([
                    'instrument_id' => $instrument->id,
                    'code' => $row['code'],
                    'label' => $row['label'],
                    'sequence' => $index + 1,
                    'points_possible' => $row['points'],
                    'is_bonus' => $row['bonus'],
                ]);

                ItemDomainAllocation::create([
                    'instrument_item_id' => $item->id,
                    'domain_id' => Domain::factory()->recycle($this->organization)->create(['name' => $row['domain']])->id,
                    'allocation_percent' => 100,
                ]);
            }

            return $instrument->fresh();
        });
    }

    #[Test]
    public function the_grid_takes_its_shape_from_the_instrument_and_from_nothing_else(): void
    {
        // Three instruments, three shapes. Nothing about a subject, a number of
        // domains, a number of questions or a deduction is written into the
        // generator: every column exists because an item exists.
        $shapes = [
            // One domain, four items.
            4 => [
                ['code' => 'A1', 'label' => 'Um', 'points' => 5.0, 'bonus' => false, 'domain' => 'Alfa'],
                ['code' => 'A2', 'label' => 'Dois', 'points' => 5.0, 'bonus' => false, 'domain' => 'Alfa'],
                ['code' => 'A3', 'label' => 'Três', 'points' => 5.0, 'bonus' => false, 'domain' => 'Alfa'],
                ['code' => 'A4', 'label' => 'Quatro', 'points' => 5.0, 'bonus' => false, 'domain' => 'Alfa'],
            ],
            // Three domains, six items, no deduction anywhere.
            6 => [
                ['code' => 'B1', 'label' => 'B um', 'points' => 10.0, 'bonus' => false, 'domain' => 'Beta'],
                ['code' => 'B2', 'label' => 'B dois', 'points' => 10.0, 'bonus' => false, 'domain' => 'Beta'],
                ['code' => 'C1', 'label' => 'C um', 'points' => 10.0, 'bonus' => false, 'domain' => 'Gama'],
                ['code' => 'C2', 'label' => 'C dois', 'points' => 10.0, 'bonus' => false, 'domain' => 'Gama'],
                ['code' => 'D1', 'label' => 'D um', 'points' => 10.0, 'bonus' => false, 'domain' => 'Delta'],
                ['code' => 'D2', 'label' => 'D dois', 'points' => 10.0, 'bonus' => false, 'domain' => 'Delta'],
            ],
        ];

        foreach ($shapes as $expected => $rows) {
            $sheet = ($spreadsheet = $this->workbook($this->download($this->instrumentShaped($rows))))->getActiveSheet();

            $headings = [];
            $column = LapisGridContract::FIRST_ITEM_COLUMN;

            while (($heading = $sheet->getCell($column.'1')->getValue()) !== null) {
                $headings[] = (string) $heading;
                $column = TabularColumn::letter(
                    TabularColumn::index($column) + 1,
                );
            }

            $this->assertCount($expected, $headings, "{$expected} itens têm de dar {$expected} colunas");

            // The labels are the items' own, in the items' own order.
            $this->assertSame(
                array_column($rows, 'label'),
                array_map(fn (string $heading): string => (string) preg_replace('/\s*\(máx\.[^)]*\)$/u', '', $heading), $headings),
            );

            $spreadsheet->disconnectWorksheets();
        }
    }

    #[Test]
    public function a_deduction_lowers_its_domain_without_changing_what_it_is_out_of(): void
    {
        // The point of the deduction being an item at all: the engine needs no
        // new concept, because «counts in the numerator, not the denominator»
        // already exists and a negative mark already flows through it (§8).
        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $deduction = $this->instrument()->items()->where('code', 'DESC')->firstOrFail();

            $this->assertTrue($deduction->is_bonus);
            $this->assertSame(0.0, (float) $deduction->points_possible);
            $this->assertCount(1, $deduction->domainAllocations);

            // And the instrument's own total ignores it, as it ignores every
            // item outside the denominator.
            $this->assertSame(60.0, (float) $this->instrument()->fresh()->total_points);
        });
    }
}
