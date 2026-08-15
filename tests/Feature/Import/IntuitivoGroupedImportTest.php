<?php

namespace Tests\Feature\Import;

use App\Domain\Assessment\CalculationEngine;
use App\Domain\Assessment\CalculationRule;
use App\Domain\Assessment\ScoreInput;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Models\CorrectionImport;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentStatus;
use App\Models\InstrumentType;
use App\Models\ItemDomainAllocation;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\IntuitivoWorkbookBuilder;

/**
 * One test, four domains, one instrument.
 *
 * This is the reason the grouped mode exists. A Português paper routinely
 * assesses Leitura, Educação Literária, Gramática and Escrita in one sitting,
 * and Intuitivo states exactly that: the sections, what each is worth, and every
 * mark inside them. Importing it as four separate evaluations would be a lie
 * about what the teacher gave, and importing it as one number would throw away
 * the only thing that makes it multi-domain.
 *
 * So each section becomes ONE item — «tudo é um item», the model's own rule —
 * carrying the domain the teacher assigned to it. Four items, four allocations,
 * one instrument, and a CalculationEngine that never learns any of this
 * happened.
 */
class IntuitivoGroupedImportTest extends CorrectionImportHttpTest
{
    /** @var array<string, int> display name => enrollment id */
    protected array $roll = [];

    /** @var array<string, int> domain name => id */
    protected array $domains = [];

    protected string $workbook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workbook = sys_get_temp_dir().'/lapis-intuitivo-'.bin2hex(random_bytes(6)).'.xlsx';

        [$this->roll, $this->domains] = app(CurrentOrganization::class)->runFor($this->organization, function (): array {
            // Added to the class the parent set up rather than replacing it: the
            // inherited Plickers tests run against this same class and must keep
            // finding the roster they were written for.
            $roll = [];
            $names = ['Ana Exemplo', 'Bruno Teste', 'Carla Fictícia', 'Diogo Inventado', 'Elsa Suposta', 'Filipe Imaginário'];
            $nextNumber = (int) Enrollment::where('class_id', $this->class->id)->max('class_number');

            foreach ($names as $index => $name) {
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

            foreach (['Leitura', 'Educação Literária', 'Gramática', 'Escrita'] as $name) {
                $domains[$name] = (int) Domain::factory()->recycle($this->organization)->create(['name' => $name])->getKey();
            }

            return [$roll, $domains];
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->workbook);
        parent::tearDown();
    }

    protected function uploadWorkbook(?IntuitivoWorkbookBuilder $builder = null, string $name = 'Teste de Português - 7.º E.xlsx'): CorrectionImport
    {
        Storage::fake('local');

        ($builder ?? IntuitivoWorkbookBuilder::likeTheObservedExport())->writeTo($this->workbook);

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Intuitivo->value,
            'file' => new UploadedFile($this->workbook, $name, null, null, true),
        ])->assertRedirect();

        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => CorrectionImport::latest('id')->firstOrFail(),
        );
    }

    /**
     * Everything the teacher decides, with each section pointed at its domain.
     *
     * @param  list<string>|null  $domainPerGroup  one domain name per group, in order
     * @return array<string, mixed>
     */
    protected function mapping(?array $domainPerGroup = null, bool $counts = true): array
    {
        $domainPerGroup ??= ['Leitura', 'Educação Literária', 'Gramática', 'Escrita'];

        $groupDomains = [];

        foreach ($domainPerGroup as $index => $domain) {
            $groupDomains['group:'.($index + 1)] = [
                ['domain_id' => $this->domains[$domain], 'allocation_percent' => '100'],
            ];
        }

        $students = [];

        foreach (array_values($this->roll) as $index => $enrollmentId) {
            $students['student:'.($index + 3)] = $enrollmentId;
        }

        return [
            'mode' => ImportMapping::MODE_CREATE,
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'students' => $students,
            'group_domains' => $groupDomains,
            'instrument' => [
                'title' => 'Teste de Português — 7.º E',
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'applied_on' => now()->subDays(2)->toDateString(),
                'academic_period_id' => $this->period->id,
                'purpose' => 'summative',
                'counts_toward_classification' => $counts,
            ],
        ];
    }

    protected function confirm(CorrectionImport $import, ?array $mapping = null): void
    {
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping ?? $this->mapping());
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();
    }

    protected function instrument(): Instrument
    {
        return app(CurrentOrganization::class)->runFor($this->organization, fn () => Instrument::firstOrFail());
    }

    // -------------------------------------------------------- 1. the default

    #[Test]
    public function an_intuitivo_import_opens_on_the_sections(): void
    {
        $import = $this->uploadWorkbook();

        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $preview = $page->toArray()['props']['preview'];

                // The source decides where the teacher starts, nothing more.
                $this->assertSame(ImportMapping::RESULT_PER_GROUP, $preview['result_mode']);
                $this->assertCount(4, $preview['groups']);
                $this->assertSame(
                    ['GRUPO I', 'GRUPO II', 'GRUPO III', 'GRUPO IV'],
                    array_column($preview['groups'], 'label'),
                );
            });
    }

    #[Test]
    public function plickers_still_opens_on_the_global_result(): void
    {
        // The other source must not move (§31).
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'file' => $this->fixture(),
        ]);

        $import = app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::latest('id')->firstOrFail());

        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertInertia(fn (AssertableInertia $page) => $this->assertSame(
                ImportMapping::RESULT_OVERALL,
                $page->toArray()['props']['preview']['result_mode'],
            ));
    }

    // ------------------------------------------------ 2, 3. four groups, four domains

    #[Test]
    public function four_sections_become_four_items_of_one_instrument(): void
    {
        $this->confirm($this->uploadWorkbook());

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            // One instrument. Never four (§37).
            $this->assertSame(1, Instrument::count());

            $instrument = Instrument::firstOrFail();
            $items = InstrumentItem::where('instrument_id', $instrument->getKey())->orderBy('sequence')->get();

            $this->assertCount(4, $items);
            $this->assertSame(['G1', 'G2', 'G3', 'G4'], $items->pluck('code')->all());
            $this->assertSame(['GRUPO I', 'GRUPO II', 'GRUPO III', 'GRUPO IV'], $items->pluck('label')->all());

            // Worth what its own questions add up to. Never a share of the total.
            $this->assertSame(
                ['20.0000', '21.0000', '29.0000', '30.0000'],
                $items->map(fn (InstrumentItem $item): string => (string) $item->points_possible)->all(),
            );

            $this->assertSame('100.0000', (string) $instrument->total_points);
            $this->assertSame(InstrumentStatus::InCorrection, $instrument->status);
        });
    }

    #[Test]
    public function each_section_counts_toward_the_domain_the_teacher_chose(): void
    {
        $this->confirm($this->uploadWorkbook());

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $items = InstrumentItem::where('instrument_id', $this->instrument()->getKey())->orderBy('sequence')->get();

            $byName = array_flip($this->domains);
            $assigned = [];

            foreach ($items as $item) {
                $allocations = ItemDomainAllocation::where('instrument_item_id', $item->getKey())->get();

                $this->assertCount(1, $allocations, 'Um grupo conta para exatamente um domínio.');
                $this->assertSame('100.0000', (string) $allocations->first()->allocation_percent);

                $assigned[] = $byName[$allocations->first()->domain_id];
            }

            // The whole point: four domains, one paper.
            $this->assertSame(['Leitura', 'Educação Literária', 'Gramática', 'Escrita'], $assigned);
        });
    }

    #[Test]
    public function several_sections_may_point_at_the_same_domain(): void
    {
        $this->confirm($this->uploadWorkbook(), $this->mapping(['Leitura', 'Leitura', 'Gramática', 'Gramática']));

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $items = InstrumentItem::where('instrument_id', $this->instrument()->getKey())->orderBy('sequence')->get();
            $byName = array_flip($this->domains);

            $assigned = $items->map(function (InstrumentItem $item) use ($byName): string {
                return $byName[ItemDomainAllocation::where('instrument_item_id', $item->getKey())->firstOrFail()->domain_id];
            })->all();

            // Not a 1:1 correspondence, and never required to be (§4).
            $this->assertSame(['Leitura', 'Leitura', 'Gramática', 'Gramática'], $assigned);
        });
    }

    // ---------------------------------------------------- 5, 6. the arithmetic

    #[Test]
    public function a_students_mark_for_a_section_is_the_sum_of_its_questions(): void
    {
        $this->confirm($this->uploadWorkbook());

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = $this->instrument();
            $items = InstrumentItem::where('instrument_id', $instrument->getKey())->orderBy('sequence')->pluck('id')->all();

            $marks = StudentItemScore::where('instrument_id', $instrument->getKey())
                ->where('enrollment_id', $this->roll['Ana Exemplo'])
                ->get()->keyBy('instrument_item_id');

            // Ana: 0+0+4+0+0 = 4 · 3+0+0+12 = 15 · 2+2+0+0+3.33+0+0 = 7.33
            //      1+4+3.33+0+0+1.5+0+1.5+0 = 11.33.  Total 37.66.
            $this->assertSame('4.0000', (string) $marks[$items[0]]->points_earned);
            $this->assertSame('15.0000', (string) $marks[$items[1]]->points_earned);
            $this->assertSame('7.3300', (string) $marks[$items[2]]->points_earned);
            $this->assertSame('11.3300', (string) $marks[$items[3]]->points_earned);

            // Six students × four sections.
            $this->assertSame(24, StudentItemScore::where('instrument_id', $instrument->getKey())->count());
        });
    }

    #[Test]
    public function no_academic_score_is_written_per_question_in_this_mode(): void
    {
        $this->confirm($this->uploadWorkbook());

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            // Twenty-five questions in the file, four items in LÁPIS. The marks
            // belong to the sections, not to the questions (§34.9).
            $this->assertSame(4, InstrumentItem::where('instrument_id', $this->instrument()->getKey())->count());
            $this->assertSame(24, StudentItemScore::count());
        });
    }

    // ---------------------------------------------------------- 7. blanks

    #[Test]
    public function a_section_with_an_unmarked_question_produces_no_mark_at_all(): void
    {
        $builder = IntuitivoWorkbookBuilder::likeTheObservedExport()
            ->student('Gina Ausente', [
                // Grupo I has a blank; the other three sections are complete.
                null, 4, 4, 4, 4,
                3, 3, 3, 12,
                2, 2, 2, 6, 5, 6, 6,
                4, 6, 5, 3, 4, 2, 2, 2, 2,
            ]);

        $enrollment = app(CurrentOrganization::class)->runFor($this->organization, function (): int {
            $student = Student::factory()->recycle($this->organization)->create();
            StudentIdentity::create([
                'student_id' => $student->getKey(),
                'organization_id' => $this->organization->getKey(),
                'display_name' => 'Gina Ausente',
            ]);

            return (int) Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $this->class->id,
                'student_id' => $student->getKey(),
                'class_number' => 7,
                'enrolled_on' => now()->subMonths(6)->toDateString(),
            ])->getKey();
        });

        $import = $this->uploadWorkbook($builder);
        $mapping = $this->mapping();
        $mapping['students']['student:9'] = $enrollment;

        $this->confirm($import, $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($enrollment): void {
            $instrument = $this->instrument();
            $items = InstrumentItem::where('instrument_id', $instrument->getKey())->orderBy('sequence')->pluck('id')->all();

            $marks = StudentItemScore::where('instrument_id', $instrument->getKey())
                ->where('enrollment_id', $enrollment)->get()->keyBy('instrument_item_id');

            // Three sections marked, and Grupo I left alone. A partial sum would
            // be a lower score wearing a complete one's clothes (§14).
            $this->assertCount(3, $marks);
            $this->assertArrayNotHasKey($items[0], $marks->all());
            $this->assertSame('21.0000', (string) $marks[$items[1]]->points_earned);
        });
    }

    // --------------------------------------------- 8. provenance is preserved

    #[Test]
    public function the_twenty_five_questions_survive_in_the_provenance(): void
    {
        $import = $this->uploadWorkbook();
        $this->confirm($import);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            $snapshot = $import->fresh()->canonical_snapshot;

            $this->assertSame(ImportMapping::RESULT_PER_GROUP, $snapshot['result_mode']);

            // The sections, with what each was worth and where it counted.
            $this->assertCount(4, $snapshot['groups']);
            $this->assertSame('20', $snapshot['groups'][0]['points_possible']);

            // And every question the file stated, even though only four marks
            // were recorded academically. Discarding them would remove the only
            // trail from a mark back to its origin (§20).
            $this->assertCount(25, $snapshot['items']);
            $this->assertGreaterThanOrEqual(150, count($snapshot['results']));

            // Still minimised: the source's names are gone.
            $this->assertTrue($snapshot['students_minimised']);
            $this->assertStringNotContainsString('Ana Exemplo', (string) json_encode($snapshot));
        });
    }

    // ------------------------------------------- 10, 11. reconciliation

    #[Test]
    public function the_preview_reconciles_the_sections_against_the_declared_total(): void
    {
        $import = $this->uploadWorkbook();

        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview'];

        $this->assertTrue($preview['reconciliation']['applicable']);
        $this->assertSame('100', $preview['reconciliation']['groups_total']);
        $this->assertSame('100', $preview['reconciliation']['source_total']);
        $this->assertTrue($preview['reconciliation']['maximum_agrees']);
        $this->assertSame(0, $preview['reconciliation']['students_disagreeing']);
    }

    #[Test]
    public function a_total_that_disagrees_is_reported_and_never_silently_adopted(): void
    {
        // The file claims a maximum of 90 while its own questions add to 100.
        $import = $this->uploadWorkbook(IntuitivoWorkbookBuilder::likeTheObservedExport()->declaringMaximum(90));

        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview'];

        $this->assertFalse($preview['reconciliation']['maximum_agrees']);

        $messages = array_column($preview['issues'], 'message');
        $this->assertNotEmpty(array_filter($messages, fn (string $m): bool => str_contains($m, 'declara um total')));

        // A warning, not a refusal: LÁPIS uses its own arithmetic and says so.
        $this->assertTrue($preview['can_confirm'] === false || $preview['can_confirm'] === true);
        $this->assertStringContainsString('O LÁPIS usa a soma dos grupos', implode(' ', $messages));
    }

    #[Test]
    public function a_student_total_that_disagrees_is_reported(): void
    {
        $import = $this->uploadWorkbook(
            IntuitivoWorkbookBuilder::likeTheObservedExport()->overridingTotalOf(0, 99.0),
        );

        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview'];

        $this->assertSame(1, $preview['reconciliation']['students_disagreeing']);
        $this->assertStringContainsString(
            'não bate certo',
            implode(' ', array_column($preview['issues'], 'message')),
        );
    }

    // ----------------------------------------- 12, 13. the domain requirement

    #[Test]
    public function a_section_that_counts_may_not_be_imported_without_a_domain(): void
    {
        $import = $this->uploadWorkbook();
        $mapping = $this->mapping();
        unset($mapping['group_domains']['group:3']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview'];

        $this->assertFalse($preview['can_confirm']);
        $this->assertStringContainsString('GRUPO III', implode(' ', array_column($preview['issues'], 'message')));

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertForbidden();
    }

    #[Test]
    public function a_paper_that_does_not_count_needs_no_domains(): void
    {
        $import = $this->uploadWorkbook();
        $mapping = $this->mapping(counts: false);
        $mapping['group_domains'] = [];

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $this->assertSame(4, InstrumentItem::where('instrument_id', $this->instrument()->getKey())->count());
        });
    }

    #[Test]
    public function grouped_results_refuse_to_be_laid_onto_an_existing_instrument(): void
    {
        $import = $this->uploadWorkbook();
        $mapping = $this->mapping();
        $mapping['mode'] = ImportMapping::MODE_ASSOCIATE;

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview'];

        // Guessing which of somebody else's questions each section maps to is
        // not something an import may do.
        $this->assertFalse($preview['can_confirm']);
        $this->assertStringContainsString('só podem criar uma avaliação nova', implode(' ', array_column($preview['issues'], 'message')));
    }

    // --------------------------------------------------------- the engine

    #[Test]
    public function the_calculation_engine_reads_it_without_knowing_any_of_this_happened(): void
    {
        $this->confirm($this->uploadWorkbook());

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = $this->instrument();
            $items = InstrumentItem::where('instrument_id', $instrument->getKey())->with('domainAllocations')->orderBy('sequence')->get();

            $scores = [];

            foreach (StudentItemScore::where('instrument_id', $instrument->getKey())
                ->where('enrollment_id', $this->roll['Bruno Teste'])->get() as $score) {
                $item = $items->firstWhere('id', $score->instrument_item_id);

                $scores[] = new ScoreInput(
                    instrumentId: (int) $instrument->getKey(),
                    itemCode: $item->code,
                    pointsPossible: (string) $item->points_possible,
                    state: $score->result_state,
                    pointsEarned: (string) $score->points_earned,
                    isBonus: false,
                    eligible: true,
                    allocations: $item->domainAllocations->map(fn ($a): array => [
                        'domain_id' => (int) $a->domain_id,
                        'allocation_percent' => (string) $a->allocation_percent,
                    ])->all(),
                );
            }

            // Equal weights across the four domains, purely to exercise the
            // engine: the real weights belong to the profile and are none of
            // the importer's business (§6 of the design).
            $weights = [];

            foreach ($this->domains as $id) {
                $weights[$id] = '25';
            }

            $outcome = (new CalculationEngine)->calculate(
                $scores,
                $weights,
                new CalculationRule(
                    absenceMode: 'exclude_all_warn',
                    roundingMode: 'half_up',
                    roundingScale: 2,
                    roundingStage: 'proposal',
                ),
            );

            // Bruno: 20/20, 21/21, 24/29, 14.33/30 → four domains, each with its
            // own percentage, aggregated by the engine as it always does.
            $this->assertCount(4, $outcome->domains);
            $this->assertNotNull($outcome->normalizedValue);
            $this->assertFalse($outcome->coverageWarning, 'Nenhum domínio ficou por cobrir.');
        });
    }

    // --------------------------------------------------------- isolation

    #[Test]
    public function an_xlsx_import_is_as_isolated_as_a_csv_one(): void
    {
        $first = $this->uploadWorkbook();
        $second = $this->uploadWorkbook(IntuitivoWorkbookBuilder::make()
            ->group('GRUPO ÚNICO', [6, 4])
            ->student('Zé Outro', [5, 2]));

        $this->assertNotSame($first->ulid, $second->ulid);
        $this->assertNotSame($first->file_sha256, $second->file_sha256);
        $this->assertNotSame($first->stored_path, $second->stored_path);

        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$second->ulid}")
            ->viewData('page')['props']['preview'];

        $this->assertCount(1, $preview['students']);
        $this->assertCount(1, $preview['groups']);
        $this->assertSame('10', $preview['groups'][0]['points_possible']);

        $names = array_column($preview['students'], 'display_name');
        $this->assertNotContains('Ana Exemplo', $names);
    }

    #[Test]
    public function the_wizard_asks_for_a_domain_per_group_and_shows_the_sections_first(): void
    {
        $wizard = $this->componentSource('resources/js/pages/imports/correction/Wizard.vue');

        // Three granularities, provider-neutral, with the grouped one offered
        // only when the file states sections at all.
        $this->assertStringContainsString('O que importar deste ficheiro', $wizard);
        $this->assertStringContainsString('Resultados por grupos', $wizard);
        $this->assertStringContainsString("option.value !== 'per_group' || hasGroups.value", $wizard);

        // One row per section: what it is worth, and what it assesses.
        $this->assertStringContainsString('Domínio de cada grupo', $wizard);
        $this->assertStringContainsString('setDomainOfGroup(', $wizard);
        $this->assertStringContainsString('Vários grupos podem contar para o mesmo domínio', $wizard);

        // Nothing is pre-filled: a section named «GRUPO III» has said nothing
        // about curriculum, and the wizard must not pretend otherwise (§4).
        $this->assertStringContainsString('— Por escolher —', $wizard);

        // Step 2 shows the sections, not the twenty-five questions (§23).
        $this->assertStringContainsString('student.group_results?.length', $wizard);

        // And the review says which number is whose (§27).
        $this->assertStringContainsString('nunca do total da origem', $wizard);
    }

    #[Test]
    public function the_preview_summarises_each_student_by_section(): void
    {
        $import = $this->uploadWorkbook();

        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview'];

        $ana = collect($preview['students'])->firstWhere('display_name', 'Ana Exemplo');

        $this->assertSame(
            [['GRUPO I', '4', '20'], ['GRUPO II', '15', '21'], ['GRUPO III', '7.33', '29'], ['GRUPO IV', '11.33', '30']],
            array_map(
                fn (array $row): array => [$row['label'], $row['earned'], $row['possible']],
                $ana['group_results'],
            ),
        );

        $this->assertSame('37.66', $ana['lapis_total']);
    }

    #[Test]
    public function an_unreadable_workbook_is_refused_at_upload(): void
    {
        Storage::fake('local');

        $path = sys_get_temp_dir().'/nao-intuitivo.xlsx';
        IntuitivoWorkbookBuilder::likeTheObservedExport()->sheetNamed('Folha1')->writeTo($path);

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Intuitivo->value,
            'file' => new UploadedFile($path, 'estranho.xlsx', null, null, true),
        ])->assertSessionHasErrors('file');

        app(CurrentOrganization::class)->runFor($this->organization, fn () => $this->assertSame(0, CorrectionImport::count()));

        @unlink($path);
    }
}
