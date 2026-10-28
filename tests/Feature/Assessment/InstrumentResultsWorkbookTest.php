<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\CompleteCorrection;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\StudentEnrollmentService;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * «Exportar resultados (Excel)»: the corrected grid as a spreadsheet.
 *
 * Built through the real product path (ProfileBuilder → InstrumentBuilder →
 * RecordScores → CompleteCorrection) with two domains at unequal weights, items
 * of different maxima, one item split across both domains, a bonus, a zero that
 * WAS given and a question nobody has corrected. The workbook is then read back
 * from a real .xlsx and compared with what the screen is sent.
 */
class InstrumentResultsWorkbookTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->file = sys_get_temp_dir().'/lapis-results-'.bin2hex(random_bytes(6)).'.xlsx';

        $this->seed(SystemScalesSeeder::class);
        $this->seed(InstrumentTypesSeeder::class);

        $this->asTenant(fn () => $this->scenario());
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    /**
     * Leitura 60% / Gramática 40%. Q1 (10, Leitura), Q2 (5, Gramática),
     * Q3 (10, split 50/50 across both), Q4 (bonus 2, Leitura).
     *  #1 everything corrected; #2 a ZERO on Q1 and Q3 left uncorrected;
     *  #3 absent on everything; #4 never marked.
     */
    private function scenario(): void
    {
        $year = AcademicYear::create([
            'label' => '2026/2027', 'starts_on' => '2026-09-14', 'ends_on' => '2027-06-30',
            'status' => 'active', 'country_code' => 'PT',
        ]);
        $year->periods()->create([
            'label' => '1.º Período', 'kind' => 'term', 'sequence' => 1,
            'starts_on' => '2026-09-14', 'ends_on' => '2026-12-19', 'status' => 'open',
        ]);

        $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Português']);

        $profile = app(ProfileBuilder::class)->create(
            ['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => 'Português – 8.º Ano', 'description' => 'Teste'],
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
            [['name' => 'Leitura', 'weight' => 60], ['name' => 'Gramática', 'weight' => 40]],
            ['8.º'],
        );
        $version = app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $this->teacher);

        $class = SchoolClass::create([
            'academic_year_id' => $year->id, 'subject_id' => $subject->id,
            'label' => '8.º A', 'grade_level' => '8.º', 'status' => 'active',
            'assessment_profile_version_id' => $version->id,
        ]);
        $class->teachers()->attach($this->teacher, ['role' => 'owner']);

        $service = app(StudentEnrollmentService::class);
        foreach ([3, 7, 12, 19] as $number) {
            $service->enrollNew($class, ['name' => "Aluno {$number}", 'class_number' => $number, 'enrolled_on' => '2026-09-14']);
        }

        $leitura = Domain::where('name', 'Leitura')->firstOrFail();
        $gramatica = Domain::where('name', 'Gramática')->firstOrFail();

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $year->periods()->firstOrFail()->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Ficha 1',
            'applied_on' => '2026-10-15',
            'status' => 'in_correction',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 25,
            'allow_bonus' => true,
        ], [
            ['code' => 'Q1', 'label' => 'Compreensão', 'points_possible' => 10, 'domains' => [['domain_id' => $leitura->id, 'allocation_percent' => 100]]],
            ['code' => 'Q2', 'label' => 'Classes de palavras', 'points_possible' => 5, 'domains' => [['domain_id' => $gramatica->id, 'allocation_percent' => 100]]],
            ['code' => 'Q3', 'label' => 'Texto misto', 'points_possible' => 10, 'domains' => [
                ['domain_id' => $leitura->id, 'allocation_percent' => 50],
                ['domain_id' => $gramatica->id, 'allocation_percent' => 50],
            ]],
            ['code' => 'Q4', 'label' => 'Bónus', 'points_possible' => 2, 'is_bonus' => true, 'domains' => [['domain_id' => $leitura->id, 'allocation_percent' => 100]]],
        ]);

        $this->record($instrument, [
            [3, 'Q1', ResultState::Assessed, 8.0], [3, 'Q2', ResultState::Assessed, 4.0],
            [3, 'Q3', ResultState::Assessed, 6.0], [3, 'Q4', ResultState::Assessed, 1.0],
            [7, 'Q1', ResultState::Assessed, 0.0], [7, 'Q2', ResultState::Assessed, 5.0],
            [12, 'Q1', ResultState::Absent, null], [12, 'Q2', ResultState::Absent, null],
            [12, 'Q3', ResultState::Absent, null], [12, 'Q4', ResultState::Absent, null],
        ]);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: ResultState, 3: float|null}>  $marks
     */
    private function record(Instrument $instrument, array $marks): void
    {
        $items = $instrument->items()->orderBy('sequence')->get();
        $enrollments = $instrument->schoolClass->enrollments()->get()->keyBy('class_number');
        $cells = [];

        foreach ($marks as [$number, $code, $state, $points]) {
            $cells[] = [
                'enrollment_id' => $enrollments[$number]->id,
                'instrument_item_id' => $items->firstWhere('code', $code)->id,
                'result_state' => $state->value,
                ...($points === null ? [] : ['points_earned' => $points]),
            ];
        }

        app(RecordScores::class)->save($instrument, $cells, $this->teacher);
    }

    private function instrument(): Instrument
    {
        return $this->asTenant(fn (): Instrument => Instrument::where('title', 'Ficha 1')->firstOrFail());
    }

    private function workbook(?Instrument $instrument = null): Spreadsheet
    {
        $response = $this->actingAs($this->teacher)
            ->get('/instruments/'.($instrument ?? $this->instrument())->ulid.'/grelha/resultados');

        $response->assertOk();

        /** @var BinaryFileResponse $base */
        $base = $response->baseResponse;
        copy($base->getFile()->getPathname(), $this->file);

        return IOFactory::load($this->file);
    }

    /**
     * @return array<string, mixed>
     */
    private function officialProps(Instrument $instrument): array
    {
        $official = [];
        $this->actingAs($this->teacher)->get('/instruments/'.$instrument->ulid)
            ->assertInertia(function ($page) use (&$official): void {
                $official = $page->toArray()['props']['official'];
            });

        return $official;
    }

    /**
     * The sheet as `[heading => cell]` for one student row, found by class number.
     *
     * @return array<string, mixed>
     */
    private function row(Spreadsheet $book, int $number): array
    {
        $sheet = $book->getActiveSheet();
        $headings = [];
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($column = 1; $column <= $highestColumn; $column++) {
            $headings[$column] = (string) $sheet->getCell([$column, 1])->getValue();
        }

        for ($row = 3; $row <= $sheet->getHighestRow(); $row++) {
            if ((int) $sheet->getCell([1, $row])->getValue() === $number) {
                $values = [];
                foreach ($headings as $column => $heading) {
                    $values[$heading] = $sheet->getCell([$column, $row])->getValue();
                }

                return $values;
            }
        }

        $this->fail("no row for student number {$number}");
    }

    private function key(array $row, string $needle): string
    {
        foreach (array_keys($row) as $heading) {
            if (str_contains($heading, $needle)) {
                return $heading;
            }
        }

        $this->fail("no column containing «{$needle}»");
    }

    #[Test]
    public function it_carries_the_marks_the_students_were_given_and_the_maximum_of_each_question(): void
    {
        $book = $this->workbook();
        $sheet = $book->getActiveSheet();

        $this->assertSame('Resultados', $sheet->getTitle());
        $this->assertSame('Pontuação máxima', $sheet->getCell('B2')->getValue());
        // Item columns start at C: the maximum of each question sits under its heading.
        $this->assertEqualsWithDelta(10.0, (float) $sheet->getCell('C2')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $sheet->getCell('D2')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $sheet->getCell('E2')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $sheet->getCell('F2')->getValue(), 0.0001);

        $first = $this->row($book, 3);
        $this->assertEqualsWithDelta(8.0, (float) $first[$this->key($first, 'Q1')], 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $first[$this->key($first, 'Q2')], 0.0001);
        $this->assertEqualsWithDelta(6.0, (float) $first[$this->key($first, 'Q3')], 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $first[$this->key($first, 'Q4')], 0.0001);
        $this->assertSame('Aluno 3', $first['Nome']);
    }

    #[Test]
    public function a_zero_that_was_given_is_a_zero_and_an_uncorrected_question_is_empty(): void
    {
        $book = $this->workbook();
        $second = $this->row($book, 7);

        $zero = $second[$this->key($second, 'Q1')];
        $this->assertNotNull($zero);
        $this->assertSame(0.0, (float) $zero);

        // Q3 for #7 was never marked: empty, not 0.
        $this->assertNull($second[$this->key($second, 'Q3')]);

        // The student nobody marked has nothing at all on any question.
        $fourth = $this->row($book, 19);
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $code) {
            $this->assertNull($fourth[$this->key($fourth, $code)], "{$code} must stay empty");
        }
    }

    #[Test]
    public function a_state_without_a_value_is_written_as_its_label_and_not_as_zero(): void
    {
        $third = $this->row($this->workbook(), 12);

        $this->assertSame(ResultState::Absent->label(), $third[$this->key($third, 'Q1')]);
        $this->assertSame(ResultState::Absent->label(), $third[$this->key($third, 'Q4')]);
    }

    #[Test]
    public function the_domain_points_percentages_and_classification_are_exactly_what_the_grid_sends_the_screen(): void
    {
        $instrument = $this->instrument();
        $official = $this->officialProps($instrument);
        $book = $this->workbook($instrument);

        $enrollments = $instrument->schoolClass->enrollments()->get()->keyBy('class_number');

        foreach ([3, 7, 12, 19] as $number) {
            $row = $this->row($book, $number);
            $cells = $official['students'][$enrollments[$number]->id];

            foreach ($official['domains'] as $domain) {
                $cell = $cells['domains'][$domain['key']] ?? null;
                $earned = $row[$domain['name'].' — pontos obtidos'];
                $possible = $row[$domain['name'].' — pontos possíveis'];
                $percent = $row[$domain['name'].' — %'];

                if ($cell === null || $cell['exact'] === null) {
                    $this->assertNull($earned);
                    $this->assertNull($possible);
                    $this->assertNull($percent);

                    continue;
                }

                $this->assertEqualsWithDelta((float) $cell['points_earned'], (float) $earned, 0.00001);
                $this->assertEqualsWithDelta((float) $cell['points_possible'], (float) $possible, 0.00001);
                $this->assertEqualsWithDelta((float) $cell['exact'] / 100, (float) $percent, 0.0000001);
            }

            $global = $cells['global'];
            $classification = $row[$this->key($row, 'Classificação')];

            if ($global['exact'] === null) {
                $this->assertNull($row['Global — %']);
                $this->assertSame($cells['status_label'], $classification);
            } else {
                $this->assertEqualsWithDelta((float) $global['exact'] / 100, (float) $row['Global — %'], 0.0000001);
                $this->assertSame($global['band']['label'] ?? '—', $classification);
            }
        }
    }

    #[Test]
    public function every_mark_in_the_workbook_is_the_one_stored_for_that_student_and_question(): void
    {
        $instrument = $this->instrument();
        $book = $this->workbook($instrument);

        $stored = $this->asTenant(fn () => StudentItemScore::query()
            ->whereIn('instrument_item_id', $instrument->items()->pluck('id'))
            ->get());
        $enrollments = $instrument->schoolClass->enrollments()->get();
        $items = $instrument->items()->orderBy('sequence')->get();

        foreach ($enrollments as $enrollment) {
            $row = $this->row($book, (int) $enrollment->class_number);

            foreach ($items as $item) {
                $cell = $row[$this->key($row, $item->code)];
                $score = $stored->first(fn ($s): bool => $s->enrollment_id === $enrollment->id && $s->instrument_item_id === $item->id);

                if ($score === null) {
                    $this->assertNull($cell, "{$item->code} #{$enrollment->class_number}: no stored row, so the cell stays empty");
                } elseif ($score->result_state === ResultState::Assessed) {
                    $this->assertEqualsWithDelta((float) $score->points_earned, (float) $cell, 0.00001);
                } else {
                    $this->assertSame($score->result_state->label(), $cell);
                }
            }
        }
    }

    #[Test]
    public function a_base_account_reaches_the_grid_with_domain_points_and_the_same_export_as_pro(): void
    {
        $instrument = $this->instrument();
        $pro = $this->workbook($instrument)->getActiveSheet()->toArray(null, true, false, false);

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['plan_id' => Plan::where('key', 'base')->firstOrFail()->id, 'status' => SubscriptionStatus::Active, 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => null],
        );
        app(Entitlements::class)->flush();

        $official = $this->officialProps($instrument);
        $enrollment = $instrument->schoolClass->enrollments()->where('class_number', 3)->firstOrFail();
        $leitura = collect($official['domains'])->firstWhere('name', 'Leitura');
        $cell = $official['students'][$enrollment->id]['domains'][$leitura['key']];

        $this->assertNotNull($cell['points_earned']);
        $this->assertNotNull($cell['points_possible']);

        $base = $this->workbook($instrument)->getActiveSheet()->toArray(null, true, false, false);
        $this->assertSame($pro, $base, 'the plan changes nothing about the results export');
    }

    #[Test]
    public function the_domain_totals_follow_the_allocation_weights_and_leave_the_bonus_out_of_the_maximum(): void
    {
        $first = $this->row($this->workbook(), 3);

        // Leitura: Q1 8/10 + 50% of Q3 (3 of 5 possible) + bonus 1 on top, no
        // bonus in the denominator → 12/15 points.
        $this->assertEqualsWithDelta(12.0, (float) $first['Leitura — pontos obtidos'], 0.0001);
        $this->assertEqualsWithDelta(15.0, (float) $first['Leitura — pontos possíveis'], 0.0001);
        $this->assertEqualsWithDelta(0.8, (float) $first['Leitura — %'], 0.0000001);

        // Gramática: Q2 4/5 + 50% of Q3 (3 of 5) → 7/10.
        $this->assertEqualsWithDelta(7.0, (float) $first['Gramática — pontos obtidos'], 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $first['Gramática — pontos possíveis'], 0.0001);
        $this->assertEqualsWithDelta(0.7, (float) $first['Gramática — %'], 0.0000001);
    }

    #[Test]
    public function the_figures_are_values_and_never_formulas(): void
    {
        $sheet = $this->workbook()->getActiveSheet();

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $this->assertFalse($cell->isFormula(), $cell->getCoordinate().' must not be a formula');
            }
        }
    }

    #[Test]
    public function a_concluded_correction_is_labelled_official_and_an_open_one_provisional(): void
    {
        $open = $this->workbook()->getActiveSheet();
        $this->assertStringContainsString('provisória', $this->headingOf($open, 'Classificação'));

        $this->asTenant(fn () => app(CompleteCorrection::class)->complete(
            $this->completable(),
            $this->teacher,
        ));

        $done = $this->workbook()->getActiveSheet();
        $this->assertStringContainsString('oficial', $this->headingOf($done, 'Classificação'));
    }

    private function headingOf($sheet, string $needle): string
    {
        $columns = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($column = 1; $column <= $columns; $column++) {
            $value = (string) $sheet->getCell([$column, 1])->getValue();
            if (str_contains($value, $needle)) {
                return $value;
            }
        }

        $this->fail("no heading containing «{$needle}»");
    }

    /** Resolves every outstanding cell so the correction can be concluded. */
    private function completable(): Instrument
    {
        $instrument = $this->instrument();
        $items = $instrument->items()->orderBy('sequence')->get();
        $enrollments = $instrument->schoolClass->enrollments()->get()->keyBy('class_number');
        $cells = [];

        foreach ([7 => ['Q3', 'Q4'], 19 => ['Q1', 'Q2', 'Q3', 'Q4']] as $number => $codes) {
            foreach ($codes as $code) {
                $cells[] = [
                    'enrollment_id' => $enrollments[$number]->id,
                    'instrument_item_id' => $items->firstWhere('code', $code)->id,
                    'result_state' => ResultState::Absent->value,
                ];
            }
        }

        app(RecordScores::class)->save($instrument, $cells, $this->teacher);

        return $this->instrument();
    }

    #[Test]
    public function another_teachers_instrument_cannot_be_exported(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get('/instruments/'.$this->instrument()->ulid.'/grelha/resultados')
            ->assertNotFound();
    }
}
