<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\OverallResultItem;
use App\Models\CorrectionImport;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\ItemDomainAllocation;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\IntuitivoWorkbookBuilder;

/**
 * The same Intuitivo file at the other two granularities.
 *
 * The modes are provider-neutral: a source decides only which one the wizard
 * opens on, and a teacher who wants something else changes it in one click. So
 * an Intuitivo export must import correctly as a single global result and as
 * twenty-five separate questions, not merely as four sections.
 *
 * The detailed mode is where the source's own sections stop being optional.
 * Intuitivo restarts its numbering in every one of them, so a paper carries
 * «Item 1» four times, and UNIQUE(instrument_group_id, code) would refuse them
 * inside one section — correctly. Keeping the sections is both the honest
 * reading of the paper and what makes the codes legal.
 */
class IntuitivoOtherModesTest extends CorrectionImportHttpTest
{
    /** @var array<string, int> */
    protected array $roll = [];

    protected int $domainId;

    protected string $workbook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workbook = sys_get_temp_dir().'/lapis-intuitivo-modos-'.bin2hex(random_bytes(6)).'.xlsx';

        [$this->roll, $this->domainId] = app(CurrentOrganization::class)->runFor($this->organization, function (): array {
            $roll = [];
            $next = (int) Enrollment::where('class_id', $this->class->id)->max('class_number');

            foreach (['Ana Exemplo', 'Bruno Teste', 'Carla Fictícia', 'Diogo Inventado', 'Elsa Suposta', 'Filipe Imaginário'] as $index => $name) {
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

            return [$roll, (int) Domain::query()->firstOrFail()->getKey()];
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->workbook);
        parent::tearDown();
    }

    protected function uploadWorkbook(): CorrectionImport
    {
        Storage::fake('local');
        IntuitivoWorkbookBuilder::likeTheObservedExport()->writeTo($this->workbook);

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Intuitivo->value,
            'file' => new UploadedFile($this->workbook, 'Teste.xlsx', null, null, true),
        ])->assertRedirect();

        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => CorrectionImport::latest('id')->firstOrFail(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseMapping(string $resultMode): array
    {
        $students = [];

        foreach (array_values($this->roll) as $index => $enrollmentId) {
            $students['student:'.($index + 3)] = $enrollmentId;
        }

        return [
            'mode' => ImportMapping::MODE_CREATE,
            'result_mode' => $resultMode,
            'students' => $students,
            'instrument' => [
                'title' => 'Teste de Português',
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'applied_on' => now()->subDays(2)->toDateString(),
                'academic_period_id' => $this->period->id,
                'purpose' => 'summative',
                'counts_toward_classification' => true,
            ],
        ];
    }

    // ------------------------------------------------------------- overall

    #[Test]
    public function the_same_file_can_be_imported_as_a_single_global_result(): void
    {
        $import = $this->uploadWorkbook();

        $mapping = $this->baseMapping(ImportMapping::RESULT_OVERALL);
        $mapping['overall_domains'] = [['domain_id' => $this->domainId, 'allocation_percent' => '100']];

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = Instrument::firstOrFail();
            $items = InstrumentItem::where('instrument_id', $instrument->getKey())->get();

            // The RG mechanism the Plickers path already uses, unchanged.
            $this->assertCount(1, $items);
            $this->assertSame(OverallResultItem::CODE, $items->first()->code);
            $this->assertSame('100.0000', (string) $items->first()->points_possible);

            // Intuitivo's total is points out of 100, so 37.66 lands as 37.66.
            $ana = StudentItemScore::where('instrument_id', $instrument->getKey())
                ->where('enrollment_id', $this->roll['Ana Exemplo'])->firstOrFail();

            $this->assertSame('37.6600', (string) $ana->points_earned);
            $this->assertSame(6, StudentItemScore::where('instrument_id', $instrument->getKey())->count());
        });
    }

    #[Test]
    public function a_student_without_a_total_gets_no_global_result(): void
    {
        Storage::fake('local');

        // A blank anywhere leaves the Total column empty, so the source states
        // no classification for that student — and null never becomes zero.
        IntuitivoWorkbookBuilder::likeTheObservedExport()
            ->student('Gina Ausente', array_merge([null], array_fill(0, 24, 2)))
            ->writeTo($this->workbook);

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
                'class_number' => 99,
                'enrolled_on' => now()->subMonths(6)->toDateString(),
            ])->getKey();
        });

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Intuitivo->value,
            'file' => new UploadedFile($this->workbook, 'Teste.xlsx', null, null, true),
        ]);

        $import = app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::latest('id')->firstOrFail());

        $mapping = $this->baseMapping(ImportMapping::RESULT_OVERALL);
        $mapping['students']['student:9'] = $enrollment;
        $mapping['overall_domains'] = [['domain_id' => $this->domainId, 'allocation_percent' => '100']];

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($enrollment): void {
            $instrument = Instrument::firstOrFail();

            $this->assertFalse(
                StudentItemScore::where('instrument_id', $instrument->getKey())
                    ->where('enrollment_id', $enrollment)->exists(),
                'Sem total na origem, não há resultado — e nunca um zero.',
            );

            $this->assertSame(6, StudentItemScore::where('instrument_id', $instrument->getKey())->count());
        });
    }

    // ------------------------------------------------------------- per item

    #[Test]
    public function the_detailed_mode_keeps_the_sources_own_sections(): void
    {
        $import = $this->uploadWorkbook();

        $mapping = $this->baseMapping(ImportMapping::RESULT_PER_QUESTION);
        $mapping['points'] = [];
        $mapping['domains'] = [];

        // Cotações come from the file; the teacher applies one domain per
        // section and the wizard writes it onto that section's questions (§22).
        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview'];

        foreach ($preview['items'] as $item) {
            $mapping['points'][$item['source_key']] = $item['points'];
            $mapping['domains'][$item['source_key']] = [
                ['domain_id' => $this->domainId, 'allocation_percent' => '100'],
            ];
        }

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = Instrument::firstOrFail();

            // Four real sections, twenty-five questions.
            $groups = InstrumentGroup::where('instrument_id', $instrument->getKey())->orderBy('sequence')->get();
            $this->assertCount(4, $groups);
            $this->assertSame(['GRUPO I', 'GRUPO II', 'GRUPO III', 'GRUPO IV'], $groups->pluck('label')->all());

            $items = InstrumentItem::where('instrument_id', $instrument->getKey())->get();
            $this->assertCount(25, $items);

            // «Item 1» four times, one per section — legal precisely because the
            // unique key is per group and not per instrument.
            $itemOne = $items->where('code', 'Item 1');
            $this->assertCount(4, $itemOne);
            $this->assertCount(4, $itemOne->pluck('instrument_group_id')->unique());

            // The cotações are the file's own.
            $grupoIV = $groups->last();
            $this->assertSame(
                ['4.0000', '6.0000', '5.0000', '3.0000', '4.0000', '2.0000', '2.0000', '2.0000', '2.0000'],
                $items->where('instrument_group_id', $grupoIV->getKey())
                    ->sortBy('sequence')->map(fn (InstrumentItem $i): string => (string) $i->points_possible)->values()->all(),
            );
        });
    }

    #[Test]
    public function the_detailed_mode_writes_a_mark_per_question(): void
    {
        $import = $this->uploadWorkbook();

        $mapping = $this->baseMapping(ImportMapping::RESULT_PER_QUESTION);
        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview'];

        $mapping['points'] = [];
        $mapping['domains'] = [];

        foreach ($preview['items'] as $item) {
            $mapping['points'][$item['source_key']] = $item['points'];
            $mapping['domains'][$item['source_key']] = [
                ['domain_id' => $this->domainId, 'allocation_percent' => '100'],
            ];
        }

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = Instrument::firstOrFail();

            // Six students × twenty-five questions.
            $this->assertSame(150, StudentItemScore::where('instrument_id', $instrument->getKey())->count());

            // And every question carries its section's domain.
            $items = InstrumentItem::where('instrument_id', $instrument->getKey())->pluck('id');
            $this->assertSame(25, ItemDomainAllocation::whereIn('instrument_item_id', $items)->count());
        });
    }

    #[Test]
    public function the_three_modes_of_one_file_never_blend(): void
    {
        // The same export, three granularities, three shapes — and each one
        // decided by the stored mode rather than guessed at write time (§8).
        $shapes = [];

        foreach ([ImportMapping::RESULT_OVERALL, ImportMapping::RESULT_PER_GROUP] as $mode) {
            $import = $this->uploadWorkbook();
            $mapping = $this->baseMapping($mode);

            if ($mode === ImportMapping::RESULT_OVERALL) {
                $mapping['overall_domains'] = [['domain_id' => $this->domainId, 'allocation_percent' => '100']];
            } else {
                $mapping['group_domains'] = [];

                foreach (['group:1', 'group:2', 'group:3', 'group:4'] as $key) {
                    $mapping['group_domains'][$key] = [['domain_id' => $this->domainId, 'allocation_percent' => '100']];
                }
            }

            $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
            $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

            $shapes[$mode] = app(CurrentOrganization::class)->runFor($this->organization, function (): array {
                $instrument = Instrument::latest('id')->firstOrFail();

                return [
                    InstrumentItem::where('instrument_id', $instrument->getKey())->count(),
                    StudentItemScore::where('instrument_id', $instrument->getKey())->count(),
                ];
            });
        }

        $this->assertSame([1, 6], $shapes[ImportMapping::RESULT_OVERALL]);
        $this->assertSame([4, 24], $shapes[ImportMapping::RESULT_PER_GROUP]);
    }
}
