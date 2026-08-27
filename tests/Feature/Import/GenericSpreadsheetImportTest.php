<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\TabularMapping;
use App\Models\CorrectionImport;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\InstrumentType;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\GenericSpreadsheetBuilder;

/**
 * The teacher's own spreadsheet, all the way through.
 *
 * The whole journey, because the interesting claim is not that a CSV can be
 * parsed — it is that a file Lapispro has never seen ends up in the same place as a
 * Plickers export: one Instrument, ordinary items, ordinary domain allocations,
 * ordinary scores, and a CalculationEngine that never learns where any of it
 * came from (§41).
 *
 * The extra step is the only difference. Between «read the file» and «show the
 * students» there is now a screen that asks what the sheet MEANS, and until it
 * is answered the import knows nothing and says so.
 */
class GenericSpreadsheetImportTest extends CorrectionImportHttpTest
{
    /** @var array<string, int> display name => enrollment id */
    protected array $roll = [];

    /** @var array<string, int> domain name => id */
    protected array $domains = [];

    protected string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().'/lapis-generic-'.bin2hex(random_bytes(6));

        [$this->roll, $this->domains] = app(CurrentOrganization::class)->runFor($this->organization, function (): array {
            $roll = [];
            $nextNumber = (int) Enrollment::where('class_id', $this->class->id)->max('class_number');

            foreach (['Ana Exemplo', 'Bruno Teste', 'Carla Fictícia', 'Diogo Inventado'] as $index => $name) {
                $student = Student::factory()->recycle($this->organization)->create();

                StudentIdentity::create([
                    'student_id' => $student->getKey(),
                    'organization_id' => $this->organization->getKey(),
                    'display_name' => $name,
                ]);

                $roll[$name] = (int) Enrollment::factory()->recycle($this->organization)->create([
                    'class_id' => $this->class->id,
                    'student_id' => $student->getKey(),
                    'class_number' => $nextNumber + $index + 1,
                    'enrolled_on' => now()->subMonths(6)->toDateString(),
                ])->getKey();
            }

            $domains = [];

            foreach (['Leitura', 'Gramática', 'Escrita'] as $name) {
                $domains[$name] = (int) Domain::factory()->recycle($this->organization)->create(['name' => $name])->getKey();
            }

            return [$roll, $domains];
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    protected function uploadSheet(GenericSpreadsheetBuilder $builder, string $name = 'Notas da turma.csv'): CorrectionImport
    {
        Storage::fake('local');

        str_ends_with($name, '.xlsx')
            ? $builder->writeXlsx($this->file)
            : $builder->writeCsv($this->file);

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Generic->value,
            'file' => new UploadedFile($this->file, $name, null, null, true),
        ])->assertRedirect();

        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => CorrectionImport::latest('id')->firstOrFail(),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function decisions(array $overrides = []): array
    {
        $students = [];

        foreach (array_values($this->roll) as $index => $enrollmentId) {
            $students['row:'.($index + 2)] = $enrollmentId;
        }

        return array_replace([
            'mode' => ImportMapping::MODE_CREATE,
            'students' => $students,
            'instrument' => [
                'title' => 'Ficha de avaliação',
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'applied_on' => now()->subDays(2)->toDateString(),
                'academic_period_id' => $this->period->id,
                'purpose' => 'summative',
                'counts_toward_classification' => true,
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $decisions
     */
    protected function save(CorrectionImport $import, array $decisions): void
    {
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $decisions);
    }

    protected function instrument(): Instrument
    {
        return app(CurrentOrganization::class)->runFor($this->organization, fn () => Instrument::firstOrFail());
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
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    protected function table(array $columns, string $studentColumn = 'A'): array
    {
        return [
            'sheet' => 'Folha 1',
            'header_row' => 1,
            'student_column' => $studentColumn,
            'result_columns' => $columns,
        ];
    }

    // ------------------------------------------- 1. the source is offered at all

    #[Test]
    public function the_third_source_is_offered_and_says_which_files_it_takes(): void
    {
        $this->actingAs($this->teacher)
            ->get('/imports/correction/create')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $sources = $page->toArray()['props']['sources'];
                $keys = array_column($sources, 'key');

                $this->assertSame(['plickers', 'intuitivo', 'generic'], $keys);

                $generic = $sources[2];
                $this->assertSame(['csv', 'xlsx'], $generic['extensions']);
                $this->assertSame('.csv,.xlsx', $generic['accept']);
            });
    }

    // -------------------------------------- 2. nothing is known until it is said

    #[Test]
    public function a_freshly_uploaded_sheet_knows_nothing_about_itself(): void
    {
        $props = $this->props($this->uploadSheet(GenericSpreadsheetBuilder::multiDomain()));

        // No students, no items, and a refusal — not a preview built on «probably
        // column A» (§3).
        $this->assertSame([], $props['preview']['students']);
        $this->assertSame([], $props['preview']['items']);
        $this->assertFalse($props['preview']['can_confirm']);

        // But the sheet itself is on screen, so the teacher can see what they
        // are describing (§10).
        $this->assertTrue($props['tabular']['readable']);
        $this->assertSame(['Nome', 'Leitura', 'Gramática', 'Escrita'], array_column($props['tabular']['columns'], 'heading'));
        $this->assertCount(5, $props['tabular']['sample']);
    }

    #[Test]
    public function plickers_and_intuitivo_are_never_given_a_mapping_screen(): void
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'file' => $this->fixture(),
        ]);

        $import = app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::latest('id')->firstOrFail());

        // The capability is the parser's, and Plickers does not have it (§7).
        $this->assertNull($this->props($import)['tabular']);
    }

    // ---------------------------------------------------- 3. overall, end to end

    #[Test]
    public function a_column_of_marks_becomes_one_global_result_out_of_a_stated_maximum(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::make()->rows([
            ['Nome', 'Nota'],
            ['Ana Exemplo', 14],
            ['Bruno Teste', 10],
            ['Carla Fictícia', 20],
            ['Diogo Inventado', 0],
        ]));

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_OVERALL,
            'table' => [...$this->table(['B']), 'value_kind' => TabularMapping::VALUE_POINTS, 'overall_maximum' => '20'],
            'overall_domains' => [['domain_id' => $this->domains['Leitura'], 'allocation_percent' => '100']],
        ]));

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = Instrument::firstOrFail();

            $this->assertSame(1, $instrument->items()->count());
            $this->assertSame('RG', $instrument->items()->firstOrFail()->code);
            $this->assertSame(InstrumentStatus::InCorrection, $instrument->status);

            $item = $instrument->items()->firstOrFail();

            // 14 out of 20 is 70%, which out of 100 is 70. Never «14», and never
            // 14/100 — the maximum was stated, not guessed (§16, §23).
            $this->assertSame('70.0000', (string) StudentItemScore::where('instrument_item_id', $item->id)
                ->where('enrollment_id', $this->roll['Ana Exemplo'])->firstOrFail()->points_earned);

            $this->assertSame('0.0000', (string) StudentItemScore::where('instrument_item_id', $item->id)
                ->where('enrollment_id', $this->roll['Diogo Inventado'])->firstOrFail()->points_earned);
        });
    }

    #[Test]
    public function a_maximum_is_required_and_never_taken_from_the_best_mark(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::overall());

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_OVERALL,
            // No maximum, and the column is not declared to be a percentage.
            'table' => $this->table(['B']),
        ]));

        $preview = $this->props($import)['preview'];

        $this->assertFalse($preview['can_confirm']);
        $this->assertStringContainsString(
            'não deduz que 14 é 14 em 20',
            implode(' ', array_column($preview['issues'], 'message')),
        );
    }

    // -------------------------------------------------- 4. per group, end to end

    #[Test]
    public function three_columns_become_one_instrument_with_three_domains(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::multiDomain());

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => $this->table(['B', 'C', 'D']),
            'points' => ['col:B' => '20', 'col:C' => '20', 'col:D' => '20'],
            'group_domains' => [
                'group:B' => [['domain_id' => $this->domains['Leitura'], 'allocation_percent' => '100']],
                'group:C' => [['domain_id' => $this->domains['Gramática'], 'allocation_percent' => '100']],
                'group:D' => [['domain_id' => $this->domains['Escrita'], 'allocation_percent' => '100']],
            ],
        ]));

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            // ONE instrument. Never three (§47).
            $this->assertSame(1, Instrument::count());

            $instrument = Instrument::firstOrFail();
            $items = $instrument->items()->orderBy('id')->get();

            $this->assertCount(3, $items);
            $this->assertSame(['Leitura', 'Gramática', 'Escrita'], $items->pluck('label')->all());
            $this->assertSame(['G1', 'G2', 'G3'], $items->pluck('code')->all());
            $this->assertSame([20.0, 20.0, 20.0], $items->pluck('points_possible')->map(fn ($points): float => (float) $points)->all());
            $this->assertSame(60.0, (float) $instrument->total_points);

            // Three allocations, one per item, each pointing where the teacher
            // said — and not where the heading happened to say (§18).
            foreach ($items as $item) {
                $this->assertCount(1, $item->domainAllocations);
            }

            $this->assertSame(
                [$this->domains['Leitura'], $this->domains['Gramática'], $this->domains['Escrita']],
                $items->map(fn ($item): int => (int) $item->domainAllocations->first()->domain_id)->all(),
            );

            $this->assertSame('14.0000', (string) StudentItemScore::where('instrument_item_id', $items[0]->id)
                ->where('enrollment_id', $this->roll['Ana Exemplo'])->firstOrFail()->points_earned);
        });
    }

    #[Test]
    public function two_columns_may_point_at_the_same_domain(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::multiDomain());

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => $this->table(['B', 'C']),
            'points' => ['col:B' => '20', 'col:C' => '20'],
            'group_domains' => [
                'group:B' => [['domain_id' => $this->domains['Leitura'], 'allocation_percent' => '100']],
                'group:C' => [['domain_id' => $this->domains['Leitura'], 'allocation_percent' => '100']],
            ],
        ]));

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $items = Instrument::firstOrFail()->items()->orderBy('id')->get();

            $this->assertCount(2, $items);
            $this->assertSame(
                [$this->domains['Leitura'], $this->domains['Leitura']],
                $items->map(fn ($item): int => (int) $item->domainAllocations->first()->domain_id)->all(),
            );
        });
    }

    #[Test]
    public function a_heading_that_names_a_domain_is_still_not_a_domain(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::multiDomain());

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => $this->table(['B', 'C', 'D']),
            'points' => ['col:B' => '20', 'col:C' => '20', 'col:D' => '20'],
            // Columns headed «Leitura», «Gramática» and «Escrita», and no domain
            // chosen for any of them.
        ]));

        $preview = $this->props($import)['preview'];

        $this->assertFalse($preview['can_confirm']);

        foreach ($preview['groups'] as $group) {
            $this->assertSame([], $group['domains'], 'nenhum domínio pode vir pré-escolhido pelo cabeçalho');
        }
    }

    // --------------------------------------------------- 5. per item, end to end

    #[Test]
    public function columns_sharing_a_heading_become_separate_questions(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::perItem());

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_QUESTION,
            'table' => $this->table(['B', 'C', 'D']),
            'points' => ['col:B' => '3', 'col:C' => '3', 'col:D' => '4'],
            'domains' => [
                'col:B' => [['domain_id' => $this->domains['Leitura'], 'allocation_percent' => '100']],
                'col:C' => [['domain_id' => $this->domains['Leitura'], 'allocation_percent' => '100']],
                'col:D' => [['domain_id' => $this->domains['Gramática'], 'allocation_percent' => '100']],
            ],
            'instrument' => [...$this->decisions()['instrument'], 'total_points' => '10'],
        ]));

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $items = Instrument::firstOrFail()->items()->orderBy('id')->get();

            $this->assertCount(3, $items);

            // Two of them are both called «Item 1». The codes keep them apart,
            // because identity was never the heading (§20, §48).
            $this->assertSame(['Item 1', 'Item 1', 'Q3'], $items->pluck('label')->all());
            $this->assertSame(3, $items->pluck('code')->unique()->count());

            $ana = $this->roll['Ana Exemplo'];
            $this->assertSame('2.0000', (string) StudentItemScore::where('instrument_item_id', $items[0]->id)->where('enrollment_id', $ana)->firstOrFail()->points_earned);
            $this->assertSame('3.0000', (string) StudentItemScore::where('instrument_item_id', $items[1]->id)->where('enrollment_id', $ana)->firstOrFail()->points_earned);
        });
    }

    // -------------------------------------------------------- 6. blank vs zero

    #[Test]
    public function a_blank_leaves_a_student_unassessed_and_a_zero_does_not(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::blankAgainstZero());

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => $this->table(['B', 'C']),
            'points' => ['col:B' => '20', 'col:C' => '20'],
            'group_domains' => [
                'group:B' => [['domain_id' => $this->domains['Leitura'], 'allocation_percent' => '100']],
                'group:C' => [['domain_id' => $this->domains['Escrita'], 'allocation_percent' => '100']],
            ],
            'students' => [
                'row:2' => $this->roll['Ana Exemplo'],
                'row:3' => $this->roll['Bruno Teste'],
                'row:4' => $this->roll['Carla Fictícia'],
            ],
        ]));

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $items = Instrument::firstOrFail()->items()->orderBy('id')->get();

            // Ana wrote a zero in Leitura and it is a zero.
            $this->assertSame('0.0000', (string) StudentItemScore::where('instrument_item_id', $items[0]->id)
                ->where('enrollment_id', $this->roll['Ana Exemplo'])->firstOrFail()->points_earned);

            // Bruno's Leitura cell was empty. No score row at all — not a zero,
            // not a falta, not a dispensa (§29).
            $this->assertNull(StudentItemScore::where('instrument_item_id', $items[0]->id)
                ->where('enrollment_id', $this->roll['Bruno Teste'])->first());

            // And he still has his Escrita mark, so «empty» stayed local to the
            // cell it was in.
            $this->assertSame('14.0000', (string) StudentItemScore::where('instrument_item_id', $items[1]->id)
                ->where('enrollment_id', $this->roll['Bruno Teste'])->firstOrFail()->points_earned);
        });
    }

    // ------------------------------------------------------ 7. reconciliation

    #[Test]
    public function a_total_column_is_shown_and_never_substituted(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::make()->rows([
            ['Nome', 'Leitura', 'Escrita', 'Total'],
            ['Ana Exemplo', 14, 16, 30],
            // A total that disagrees with its own parts, on purpose.
            ['Bruno Teste', 10, 10, 99],
        ]));

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => [...$this->table(['B', 'C']), 'total_column' => 'D'],
            'points' => ['col:B' => '20', 'col:C' => '20'],
            'group_domains' => [
                'group:B' => [['domain_id' => $this->domains['Leitura'], 'allocation_percent' => '100']],
                'group:C' => [['domain_id' => $this->domains['Escrita'], 'allocation_percent' => '100']],
            ],
            'students' => [
                'row:2' => $this->roll['Ana Exemplo'],
                'row:3' => $this->roll['Bruno Teste'],
            ],
        ]));

        $students = collect($this->props($import)['preview']['students'])->keyBy('source_key');

        // The source's own total travels for comparison…
        $this->assertSame('30', $students['row:2']['source_score']);
        $this->assertSame('99', $students['row:3']['source_score']);

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $items = Instrument::firstOrFail()->items()->orderBy('id')->get();

            // …and Bruno's marks are still 10 and 10. The file's 99 changed
            // nothing: Lapispro computes, the source reconciles (§31).
            $this->assertSame('10.0000', (string) StudentItemScore::where('instrument_item_id', $items[0]->id)
                ->where('enrollment_id', $this->roll['Bruno Teste'])->firstOrFail()->points_earned);
            $this->assertSame('10.0000', (string) StudentItemScore::where('instrument_item_id', $items[1]->id)
                ->where('enrollment_id', $this->roll['Bruno Teste'])->firstOrFail()->points_earned);
        });
    }

    // -------------------------------------------------------- 8. xlsx, end to end

    #[Test]
    public function the_same_journey_works_from_a_workbook(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::multiDomain(), 'Notas da turma.xlsx');

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => $this->table(['B', 'C', 'D']),
            'points' => ['col:B' => '20', 'col:C' => '20', 'col:D' => '20'],
            'group_domains' => [
                'group:B' => [['domain_id' => $this->domains['Leitura'], 'allocation_percent' => '100']],
                'group:C' => [['domain_id' => $this->domains['Gramática'], 'allocation_percent' => '100']],
                'group:D' => [['domain_id' => $this->domains['Escrita'], 'allocation_percent' => '100']],
            ],
        ]));

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $this->assertSame(1, Instrument::count());
            $this->assertSame(3, Instrument::firstOrFail()->items()->count());
        });
    }

    // ------------------------------------------- 9. changing an answer re-reads

    #[Test]
    public function changing_the_student_column_re_reads_the_sheet(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::make()->rows([
            ['Turma', 'Nome', 'Nota'],
            ['7.º Z', 'Ana Exemplo', 14],
            ['7.º Z', 'Bruno Teste', 10],
        ]));

        // First answer: the wrong column. Everyone is called «7.º Z».
        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => $this->table(['C'], studentColumn: 'A'),
            'points' => ['col:C' => '20'],
        ]));

        $names = array_column($this->props($import)['preview']['students'], 'display_name');
        $this->assertSame(['7.º Z', '7.º Z'], $names);

        // Corrected: the grid is rebuilt from the file, not patched.
        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => $this->table(['C'], studentColumn: 'B'),
            'points' => ['col:C' => '20'],
        ]));

        $this->assertSame(
            ['Ana Exemplo', 'Bruno Teste'],
            array_column($this->props($import)['preview']['students'], 'display_name'),
        );
    }

    // ------------------------------------------------ 10. matching is conservative

    #[Test]
    public function names_are_matched_exactly_and_never_approximately(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::make()->rows([
            ['Nome', 'Nota'],
            ['Ana Exemplo', 14],
            // Same first name, different person. Never matched to Ana (§13).
            ['Ana Outra Pessoa', 12],
            ['ana exemplo ', 11],
        ]));

        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => $this->table(['B']),
            'points' => ['col:B' => '20'],
            'students' => [],
        ]));

        $students = collect($this->props($import)['preview']['students'])->keyBy('source_key');

        $this->assertSame('matched', $students['row:2']['status']);

        // Sharing a first name is not being the same person, and nothing in the
        // ladder is allowed to think otherwise (§13).
        $this->assertSame('unmatched', $students['row:3']['status'], 'o primeiro nome nunca associa');

        // Trailing space and case are the same name written carelessly, which
        // normalised matching does resolve. Two rows therefore PROPOSE the same
        // student — and the save is where that is settled: whichever row claimed
        // them first keeps them, the other returns to undecided rather than
        // silently overwriting.
        $this->save($import, $this->decisions([
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => $this->table(['B']),
            'points' => ['col:B' => '20'],
            'students' => [
                'row:2' => $this->roll['Ana Exemplo'],
                'row:4' => $this->roll['Ana Exemplo'],
            ],
        ]));

        $saved = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): array => (array) (CorrectionImport::findOrFail($import->getKey())->mapping_snapshot['students'] ?? []),
        );

        $this->assertSame(['row:2' => $this->roll['Ana Exemplo']], $saved);
    }
}
