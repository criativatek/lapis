<?php

namespace Tests\Feature\Reporting;

use App\Domain\Assessment\CalculationEngine;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\Analysis\BuildResultsAnalysis;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\CompleteCorrection;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\Reporting\ClassDocuments\InstrumentGrid;
use App\Services\Reporting\ClassDocuments\InstrumentGridOptions;
use App\Services\Reporting\ClassDocuments\InstrumentGridUnavailable;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The data source of the printable grid of ONE instrument (B1).
 *
 * Built through the real product path — ProfileBuilder, InstrumentBuilder,
 * RecordScores, CompleteCorrection — never a hand-rolled fixture, with a profile
 * of two domains at unequal weights (60/40) so a global figure can never
 * coincidentally equal a domain's. What these tests hold, in order: the document
 * says what the correction grid and Resultados say, to the digit; the raw score
 * is exact and never a zero that was not given; nobody is on the sheet who should
 * not be, and nothing that identifies a row travels; the columns are cut without
 * losing one; and reading a class costs the same number of queries whether it has
 * three students or thirteen.
 */
class InstrumentGridTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();

        $this->seed(SystemScalesSeeder::class);
        $this->seed(InstrumentTypesSeeder::class);

        $this->asTenant(fn () => $this->scenario());
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    /**
     * Two domains, 60/40. Seven students, «Teste 1» left `in_correction`:
     *  #1 fully classified; #2 fully classified, lower marks; #3 absent; #4 exempt;
     *  #5 never marked (pending); #6 enrolled AFTER the test (out of scope);
     *  #7 partial — Q1 marked, Q2 pending.
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

        $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática']);

        $profile = app(ProfileBuilder::class)->create(
            [
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'name' => 'Matemática – 8.º Ano',
                'description' => 'Cenário de teste da grelha imprimível.',
            ],
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
            [
                ['name' => 'Números e Operações', 'weight' => 60],
                ['name' => 'Geometria', 'weight' => 40],
            ],
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
        foreach (range(1, 5) as $n) {
            $service->enrollNew($class, ['name' => "Aluno {$n}", 'class_number' => $n, 'enrolled_on' => '2026-09-14']);
        }
        $service->enrollNew($class, ['name' => 'Aluno 6', 'class_number' => 6, 'enrolled_on' => '2026-12-01']);
        $service->enrollNew($class, ['name' => 'Aluno 7', 'class_number' => 7, 'enrolled_on' => '2026-09-14']);

        $numeros = Domain::where('name', 'Números e Operações')->firstOrFail();
        $geometria = Domain::where('name', 'Geometria')->firstOrFail();

        $instrument = $this->makeInstrument([
            'title' => 'Teste 1',
            'total_points' => 20,
        ], [
            ['code' => 'Q1', 'label' => 'Resolução de equações', 'points_possible' => 10, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]],
            ['code' => 'Q2', 'label' => 'Áreas e perímetros', 'points_possible' => 10, 'domains' => [['domain_id' => $geometria->id, 'allocation_percent' => 100]]],
        ]);

        $this->record($instrument, [
            [1, 'Q1', ResultState::Assessed, 9.0], [1, 'Q2', ResultState::Assessed, 8.0],
            [2, 'Q1', ResultState::Assessed, 3.0], [2, 'Q2', ResultState::Assessed, 4.0],
            [3, 'Q1', ResultState::Absent, null], [3, 'Q2', ResultState::Absent, null],
            [4, 'Q1', ResultState::Exempt, null], [4, 'Q2', ResultState::Exempt, null],
            [7, 'Q1', ResultState::Assessed, 6.0],
        ]);
    }

    // ------------------------------------------------------------ fixtures

    private function class(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '8.º A')->firstOrFail());
    }

    private function domain(string $name): Domain
    {
        return $this->asTenant(fn (): Domain => Domain::where('name', $name)->firstOrFail());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $groups
     */
    private function makeInstrument(array $attributes, array $items, array $groups = []): Instrument
    {
        $class = $this->class();

        return app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $class->academicYear->periods()->firstOrFail()->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste',
            'applied_on' => '2026-10-15',
            'status' => 'in_correction',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 20,
            ...$attributes,
        ], $items, $groups);
    }

    /**
     * @param  list<array{0: int, 1: string|int, 2: ResultState, 3: float|null}>  $marks  class number, item code (or position), state, points
     */
    private function record(Instrument $instrument, array $marks): void
    {
        $items = $instrument->items()->orderBy('sequence')->get()->values();
        $enrollments = $this->class()->enrollments()->get()->keyBy('class_number');
        $cells = [];

        foreach ($marks as [$number, $code, $state, $points]) {
            $item = is_int($code) ? $items[$code] : $items->firstWhere('code', $code);

            $cells[] = [
                'enrollment_id' => $enrollments[$number]->id,
                'instrument_item_id' => $item->id,
                'result_state' => $state->value,
                ...($points === null ? [] : ['points_earned' => $points]),
            ];
        }

        app(RecordScores::class)->save($instrument, $cells, $this->teacher);
    }

    private function grid(Instrument $instrument, ?InstrumentGridOptions $options = null): array
    {
        return $this->asTenant(function () use ($instrument, $options): array {
            // A fresh model: the builder must not lean on what a fixture left loaded.
            return app(InstrumentGrid::class)->build(Instrument::findOrFail($instrument->id), $options ?? new InstrumentGridOptions);
        });
    }

    private function teste1(): Instrument
    {
        return $this->asTenant(fn (): Instrument => Instrument::where('title', 'Teste 1')->firstOrFail());
    }

    /**
     * The rows of the first block by class number: `[number => row]`.
     *
     * @param  array<string, mixed>  $grid
     * @return array<string, array<string, mixed>>
     */
    private function rowsByNumber(array $grid, int $block = 0): array
    {
        $rows = [];
        foreach ($grid['blocks'][$block]['rows'] as $row) {
            $rows[$row['number']] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $grid
     * @return list<string>
     */
    private function kinds(array $grid, int $block = 0): array
    {
        return array_map(fn (array $column): string => $column['kind'], $grid['blocks'][$block]['columns']);
    }

    /** The cell of a row under the column of this kind (first of its kind). */
    private function cellOf(array $grid, string $number, string $kind): array
    {
        $kinds = $this->kinds($grid);
        $position = array_search($kind, $kinds, true);
        $this->assertNotFalse($position, "no column of kind {$kind}");

        return $this->rowsByNumber($grid)[$number]['cells'][$position];
    }

    private function completeScenario(): void
    {
        $this->asTenant(function (): void {
            $instrument = $this->teste1();

            $this->record($instrument, [
                [5, 'Q1', ResultState::Absent, null], [5, 'Q2', ResultState::Absent, null],
                [7, 'Q2', ResultState::Assessed, 7.0],
            ]);

            app(CompleteCorrection::class)->complete($this->teste1(), $this->teacher);
        });
    }

    /** The percentage as the screen writes it, by an independent route (number_format, not the builder's). */
    private function screenPercent(array $cell): ?string
    {
        $raw = $cell['value_precise'] ?? $cell['value'];

        return $raw === null
            ? null
            : number_format((float) $raw, $cell['value_precise'] !== null ? 2 : 1, ',', '').' %';
    }

    // ============================================================== paridade

    #[Test]
    public function the_result_level_and_domains_are_exactly_what_the_correction_grid_and_results_show(): void
    {
        $this->completeScenario();
        $ulid = $this->asTenant(fn () => $this->teste1()->ulid);

        $official = null;
        $this->actingAs($this->teacher)->get("/instruments/{$ulid}")->assertInertia(function ($page) use (&$official): void {
            $official = $page->toArray()['props']['official'];
        });

        $results = null;
        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados")->assertInertia(function ($page) use (&$results): void {
            $results = collect($page->toArray()['props']['students'])->keyBy('class_number');
        });

        $grid = $this->grid($this->teste1());
        $numeros = $this->domain('Números e Operações');
        $geometria = $this->domain('Geometria');
        $enrollments = $this->asTenant(fn () => $this->class()->enrollments()->get()->keyBy('class_number'));

        $checked = 0;

        foreach ($this->rowsByNumber($grid) as $number => $row) {
            $id = $enrollments[(int) $number]->id;
            $cell = $official['students'][$id];
            $resultsStudent = $results[(int) $number];

            // The three sources agree with each other first…
            $this->assertSame($cell['global'], $resultsStudent['global']);
            $this->assertSame($cell['domains'], $resultsStudent['domains']);

            // …and the document says exactly that, written the way the screen writes it.
            $expected = $this->screenPercent($cell['global']);
            $result = $this->cellOf($grid, $number, 'result');
            $level = $this->cellOf($grid, $number, 'level');

            if ($expected === null) {
                $this->assertSame($cell['status_label'], $result['text'], "student {$number}: the reason, as the screen shows it");
                $this->assertSame('—', $level['text']);
            } else {
                $this->assertSame($expected.($cell['global']['is_partial'] ? ' *' : ''), $result['text'], "student {$number}");
                $this->assertSame($cell['global']['band']['label'] ?? '—', $level['text']);
            }

            foreach ([$numeros->id => 0, $geometria->id => 1] as $domainId => $offset) {
                $domainCell = $cell['domains']['d'.$domainId];
                $kinds = $this->kinds($grid);
                $position = array_keys(array_filter($kinds, fn (string $kind): bool => $kind === 'domain'))[$offset];
                $printed = $row['cells'][$position];

                $domainExpected = $domainCell === null ? null : $this->screenPercent($domainCell);

                $this->assertSame(
                    $domainExpected === null ? '—' : $domainExpected.($domainCell['is_partial'] ? ' *' : ''),
                    $printed['text'],
                    "student {$number}, domain {$domainId}",
                );
                $this->assertSame($domainCell['band']['label'] ?? null, $printed['detail']);
            }

            $checked++;
        }

        // Seven students, one of them out of scope and so not on the sheet.
        $this->assertSame(6, $checked);
    }

    #[Test]
    public function the_two_decimal_figure_is_printed_whenever_the_engine_says_one_decimal_would_move_the_level(): void
    {
        // 49.49 % rounds to 49.5 at one decimal — the other side of the 49.5
        // boundary of the 1–5 scale. The engine then sends the truncated 49.49 and
        // the document must print THAT, as the screen does.
        $numeros = $this->domain('Números e Operações');
        $instrument = $this->asTenant(function () use ($numeros): Instrument {
            $instrument = $this->makeInstrument(['title' => 'Fronteira', 'total_points' => 1000], [
                ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 1000, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]],
            ]);
            $this->record($instrument, [[1, 'Q1', ResultState::Assessed, 494.9]]);

            return $instrument;
        });

        $grid = $this->grid($instrument);
        $cell = $this->asTenant(fn () => app(BuildResultsAnalysis::class)->officialCells(Instrument::findOrFail($instrument->id)));
        $enrollmentId = $this->asTenant(fn () => $this->class()->enrollments()->where('class_number', 1)->firstOrFail()->id);

        $this->assertNotNull($cell['students'][$enrollmentId]['global']['value_precise'], 'the case this test is about');
        $this->assertSame('49,49 %', $this->cellOf($grid, '1', 'result')['text']);
    }

    // ============================================================ pontuação bruta

    #[Test]
    public function the_raw_score_is_the_exact_decimal_sum_including_bonus_and_deductions(): void
    {
        $numeros = $this->domain('Números e Operações');
        $instrument = $this->asTenant(function () use ($numeros): Instrument {
            $domains = [['domain_id' => $numeros->id, 'allocation_percent' => 100]];
            $instrument = $this->makeInstrument(['title' => 'Decimais', 'total_points' => 20, 'allow_bonus' => true], [
                ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 0.5, 'domains' => $domains],
                ['code' => 'Q2', 'label' => 'Q2', 'points_possible' => 0.25, 'domains' => $domains],
                ['code' => 'Q3', 'label' => 'Q3', 'points_possible' => 10, 'domains' => $domains],
                ['code' => 'B1', 'label' => 'Bónus', 'points_possible' => 2, 'is_bonus' => true, 'domains' => $domains],
                ['code' => 'D1', 'label' => 'Dedução', 'points_possible' => 0, 'is_bonus' => true, 'domains' => $domains],
                ['code' => 'Q6', 'label' => 'Q6', 'points_possible' => 0.1, 'domains' => $domains],
                ['code' => 'Q7', 'label' => 'Q7', 'points_possible' => 0.2, 'domains' => $domains],
            ]);

            $this->record($instrument, [
                [1, 'Q1', ResultState::Assessed, 0.5], [1, 'Q2', ResultState::Assessed, 0.25], [1, 'Q3', ResultState::Assessed, 7.5],
                [1, 'B1', ResultState::Assessed, 1.0], [1, 'D1', ResultState::Assessed, -0.5],
                [1, 'Q6', ResultState::Assessed, 0.0], [1, 'Q7', ResultState::Assessed, 0.0],
                // 0.1 + 0.2: 0.30000000000000004 in floating point, exactly 0.3 here.
                [2, 'Q6', ResultState::Assessed, 0.1], [2, 'Q7', ResultState::Assessed, 0.2],
            ]);

            return $instrument;
        });

        $grid = $this->grid($instrument);

        // 0.5 + 0.25 + 7.5 + 1 − 0.5 = 8.75, over the instrument's total.
        $this->assertSame('8,75/20', $this->cellOf($grid, '1', 'raw')['text']);
        // Partial (other questions still pending): marked, and the exact 0.3.
        $this->assertSame('0,3/20 *', $this->cellOf($grid, '2', 'raw')['text']);
    }

    #[Test]
    public function the_server_sum_equals_what_the_correction_grid_adds_up_from_the_same_cells(): void
    {
        $instrument = $this->teste1();
        $ulid = $instrument->ulid;
        $grid = $this->grid($instrument);

        $screen = null;
        $this->actingAs($this->teacher)->get("/instruments/{$ulid}")->assertInertia(function ($page) use (&$screen): void {
            $screen = $page->toArray()['props'];
        });

        $byEnrollment = [];
        foreach ($screen['scores'] as $score) {
            if ($score['result_state'] === 'assessed' && $score['points_earned'] !== null) {
                $byEnrollment[$score['enrollment_id']] = ($byEnrollment[$score['enrollment_id']] ?? 0.0) + $score['points_earned'];
            }
        }

        $byNumber = collect($screen['students'])->keyBy('class_number');

        foreach ($this->rowsByNumber($grid) as $number => $row) {
            $screenTotal = $byEnrollment[$byNumber[(int) $number]['enrollment_id']] ?? null;
            $printed = $this->cellOf($grid, (string) $number, 'raw')['text'];

            if ($screenTotal === null) {
                $this->assertSame('—', $printed, "student {$number} has nothing assessed");

                continue;
            }

            $value = (float) str_replace(',', '.', explode('/', rtrim($printed, ' *'))[0]);
            $this->assertEqualsWithDelta($screenTotal, $value, 0.00001, "student {$number}");
        }
    }

    #[Test]
    public function a_student_with_nothing_assessed_has_no_score_and_never_a_zero(): void
    {
        $grid = $this->grid($this->teste1());

        // #3 was absent, #4 exempt, #5 never marked: nothing assessed, so «—», not 0.
        foreach (['3', '4', '5'] as $number) {
            $this->assertSame('—', $this->cellOf($grid, $number, 'raw')['text'], "student {$number}");
        }

        $this->assertSame('Aus', $this->rowsByNumber($grid)['3']['cells'][0]['text']);
        $this->assertSame('Disp', $this->rowsByNumber($grid)['4']['cells'][0]['text']);
        $this->assertSame('—', $this->rowsByNumber($grid)['5']['cells'][0]['text']);
    }

    #[Test]
    public function an_item_without_a_domain_counts_in_the_raw_score_and_stays_out_of_the_result(): void
    {
        $numeros = $this->domain('Números e Operações');
        $instrument = $this->asTenant(function () use ($numeros): Instrument {
            $instrument = $this->makeInstrument(['title' => 'Sem domínio', 'total_points' => 20], [
                ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]],
                ['code' => 'Q2', 'label' => 'Q2', 'points_possible' => 10],
            ]);
            $this->record($instrument, [[1, 'Q1', ResultState::Assessed, 10.0], [1, 'Q2', ResultState::Assessed, 5.0]]);

            return $instrument;
        });

        $grid = $this->grid($instrument);

        $this->assertSame('15/20', $this->cellOf($grid, '1', 'raw')['text']);
        $this->assertSame('100,0 %', $this->cellOf($grid, '1', 'result')['text'], 'the engine leaves Q2 out');
        $this->assertContains(
            '1 item deste instrumento não tem domínio atribuído e não entra no cálculo.',
            $grid['notes'],
        );
    }

    // ================================================================ estados

    #[Test]
    public function every_state_has_a_compact_label_and_the_legend_lists_only_those_in_use(): void
    {
        $numeros = $this->domain('Números e Operações');
        $instrument = $this->asTenant(function () use ($numeros): Instrument {
            $domains = [['domain_id' => $numeros->id, 'allocation_percent' => 100]];
            $instrument = $this->makeInstrument(['title' => 'Estados', 'total_points' => 8], array_map(
                fn (int $n): array => ['code' => "Q{$n}", 'label' => "Q{$n}", 'points_possible' => 1, 'domains' => $domains],
                range(1, 8),
            ));

            $this->record($instrument, [
                [1, 'Q1', ResultState::Assessed, 1.0],
                // Q2: no row at all — por avaliar.
                [1, 'Q3', ResultState::Absent, null],
                [1, 'Q4', ResultState::AbsentJustified, null],
                [1, 'Q5', ResultState::Exempt, null],
                [1, 'Q6', ResultState::NotApplicable, null],
                [1, 'Q7', ResultState::Annulled, null],
                [1, 'Q8', ResultState::UnderReview, null],
            ]);

            return $instrument;
        });

        $grid = $this->grid($instrument);

        $this->assertSame(
            ['1', '—', 'Aus', 'AusJ', 'Disp', 'N/A', 'Anul', 'Rev'],
            array_map(fn (array $cell): string => $cell['text'], array_slice($this->rowsByNumber($grid)['1']['cells'], 0, 8)),
        );
        $this->assertSame(
            [
                ['short' => '—', 'label' => 'Por avaliar (não é zero)'],
                ['short' => 'Aus', 'label' => 'Ausente'],
                ['short' => 'AusJ', 'label' => 'Ausência justificada'],
                ['short' => 'Disp', 'label' => 'Dispensado'],
                ['short' => 'N/A', 'label' => 'Não aplicável'],
                ['short' => 'Anul', 'label' => 'Anulado'],
                ['short' => 'Rev', 'label' => 'Em revisão'],
            ],
            $grid['legend']['states'],
        );

        // «Teste 1» only has absent and exempt (and pending): the others do not appear.
        $teste1 = $this->grid($this->teste1());
        $this->assertSame(['—', 'Aus', 'Disp'], array_column($teste1['legend']['states'], 'short'));
    }

    #[Test]
    public function a_partly_assessed_row_is_marked_and_the_note_explains_the_mark(): void
    {
        $grid = $this->grid($this->teste1());

        // #7: Q1 marked, Q2 still pending.
        $this->assertStringEndsWith(' *', $this->cellOf($grid, '7', 'raw')['text']);
        $this->assertStringEndsWith(' *', $this->cellOf($grid, '7', 'result')['text']);
        // #1 is complete: no mark.
        $this->assertStringEndsNotWith('*', $this->cellOf($grid, '1', 'raw')['text']);
        $this->assertContains('* Avaliação parcial: há questões ainda por avaliar.', $grid['notes']);
    }

    // ================================================================ quem consta

    #[Test]
    public function a_student_the_instrument_does_not_apply_to_is_left_out_counted_and_never_named(): void
    {
        $grid = $this->grid($this->teste1());

        $numbers = array_keys($this->rowsByNumber($grid));
        $this->assertSame(['1', '2', '3', '4', '5', '7'], array_map('strval', $numbers));
        $this->assertSame(1, $grid['summary']['excluded']);
        $this->assertSame('1 aluno não abrangido por este elemento de avaliação não consta.', $grid['excluded_note']);
        $this->assertStringNotContainsString('Aluno 6', json_encode($grid, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Aluno 7', json_encode($grid, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function a_score_row_of_an_enrolment_outside_the_class_is_never_read(): void
    {
        $before = $this->grid($this->teste1());

        $this->asTenant(function (): void {
            $instrument = $this->teste1();
            $other = SchoolClass::create([
                'academic_year_id' => $this->class()->academic_year_id, 'subject_id' => $this->class()->subject_id,
                'label' => '9.º Z', 'grade_level' => '9.º', 'status' => 'active',
            ]);
            $outsider = app(StudentEnrollmentService::class)->enrollNew($other, ['name' => 'Intruso', 'class_number' => 1, 'enrolled_on' => '2026-09-14']);
            $enrollment = Enrollment::where('class_id', $other->id)->firstOrFail();

            StudentItemScore::create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $instrument->items()->orderBy('sequence')->firstOrFail()->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => ResultState::Assessed,
                'points_earned' => 10,
            ]);
            $this->assertNotNull($outsider);
        });

        $after = $this->grid($this->teste1());

        $this->assertSame($before['blocks'], $after['blocks']);
        $this->assertStringNotContainsString('Intruso', json_encode($after, JSON_UNESCAPED_UNICODE));
    }

    // ========================================================= colunas e grupos

    #[Test]
    public function the_same_code_in_two_groups_is_told_apart_by_the_group_header(): void
    {
        $numeros = $this->domain('Números e Operações');
        $instrument = $this->asTenant(function () use ($numeros): Instrument {
            $domains = [['domain_id' => $numeros->id, 'allocation_percent' => 100]];

            return $this->makeInstrument(['title' => 'Com grupos'], [
                ['group_index' => 0, 'code' => 'Q1', 'label' => 'Oral 1', 'points_possible' => 10, 'domains' => $domains],
                ['group_index' => 1, 'code' => 'Q1', 'label' => 'Gramática 1', 'points_possible' => 10, 'domains' => $domains],
            ], [['label' => 'Oralidade'], ['label' => 'Gramática']]);
        });

        $grid = $this->grid($instrument);
        $columns = $grid['blocks'][0]['columns'];

        $this->assertSame(['Q1', 'Q1'], [$columns[0]['code'], $columns[1]['code']]);
        $this->assertSame(['Oralidade', 'Gramática'], [$columns[0]['group'], $columns[1]['group']]);
        $this->assertSame(
            [['label' => 'Oralidade', 'span' => 1], ['label' => 'Gramática', 'span' => 1], ['label' => null, 'span' => 3]],
            $grid['blocks'][0]['group_spans'],
        );
        $this->assertSame(['Oralidade', 'Gramática'], array_column($grid['legend']['questions'], 'group'));
    }

    #[Test]
    public function an_instrument_without_named_groups_has_no_group_header(): void
    {
        $this->assertNull($this->grid($this->teste1())['blocks'][0]['group_spans']);
    }

    #[Test]
    public function forty_questions_are_cut_into_landscape_blocks_that_lose_no_question_and_repeat_number_and_name(): void
    {
        $numeros = $this->domain('Números e Operações');
        $instrument = $this->asTenant(function () use ($numeros): Instrument {
            $domains = [['domain_id' => $numeros->id, 'allocation_percent' => 100]];
            $instrument = $this->makeInstrument(['title' => 'Quarenta', 'total_points' => 40], array_map(
                fn (int $n): array => ['code' => "Q{$n}", 'label' => "Questão {$n}", 'points_possible' => 1, 'domains' => $domains],
                range(1, 40),
            ));
            $this->record($instrument, array_map(fn (int $i): array => [1, $i, ResultState::Assessed, 1.0], range(0, 39)));

            return $instrument;
        });

        $grid = $this->grid($instrument);

        $this->assertSame('landscape', $grid['orientation']);
        $this->assertCount(3, $grid['blocks']);
        $this->assertSame(3, $grid['summary']['blocks']);

        $codes = [];
        foreach ($grid['blocks'] as $index => $block) {
            foreach ($block['columns'] as $column) {
                if ($column['kind'] === 'item') {
                    $codes[] = $column['code'];
                }
            }

            // Every block carries every student, with the number and the name.
            $this->assertSame(['1', '2', '3', '4', '5', '7'], array_column($block['rows'], 'number'));
            $this->assertSame(array_column($grid['blocks'][0]['rows'], 'name'), array_column($block['rows'], 'name'));

            // Cells line up with the columns, one for one.
            foreach ($block['rows'] as $row) {
                $this->assertCount(count($block['columns']), $row['cells']);
            }

            // The summary rides in the last block, and only there.
            $hasSummary = in_array('raw', array_column($block['columns'], 'kind'), true);
            $this->assertSame($index === 2, $hasSummary);
        }

        $this->assertSame(array_map(fn (int $n): string => "Q{$n}", range(1, 40)), $codes);
        $this->assertSame('40/40', $this->rowsByNumber($grid, 2)['1']['cells'][13]['text'], 'the raw score of the one student marked');
    }

    #[Test]
    public function a_forced_orientation_replaces_the_automatic_one(): void
    {
        $short = $this->grid($this->teste1(), InstrumentGridOptions::fromQuery(['orientacao' => 'horizontal']));
        $auto = $this->grid($this->teste1());

        $this->assertSame('portrait', $auto['orientation']);
        $this->assertSame('landscape', $short['orientation']);
        $this->assertSame('landscape', $short['options']['orientation']);
    }

    // ================================================================== opções

    #[Test]
    public function domains_appear_only_with_two_or_more_and_only_when_asked(): void
    {
        $with = $this->grid($this->teste1());
        $without = $this->grid($this->teste1(), InstrumentGridOptions::fromQuery(['dominios' => '0']));

        $this->assertSame(['item', 'item', 'raw', 'result', 'level', 'domain', 'domain'], $this->kinds($with));
        $this->assertSame(['Números e Operações', 'Geometria'], array_column(array_slice($with['blocks'][0]['columns'], 5), 'label'));
        $this->assertSame(['item', 'item', 'raw', 'result', 'level'], $this->kinds($without));

        // One domain: the domain IS the global, so it is never printed twice.
        $numeros = $this->domain('Números e Operações');
        $single = $this->asTenant(fn (): Instrument => $this->makeInstrument(['title' => 'Um domínio', 'total_points' => 10], [
            ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]],
        ]));

        $this->assertSame(['item', 'raw', 'result', 'level'], $this->kinds($this->grid($single)));
    }

    #[Test]
    public function the_options_choose_columns_and_tables_and_nothing_else(): void
    {
        $noRaw = $this->grid($this->teste1(), InstrumentGridOptions::fromQuery(['bruta' => '0']));
        $noResult = $this->grid($this->teste1(), InstrumentGridOptions::fromQuery(['resultado' => '0']));
        $noLegend = $this->grid($this->teste1(), InstrumentGridOptions::fromQuery(['legenda' => '0']));
        $full = $this->grid($this->teste1());

        $this->assertNotContains('raw', $this->kinds($noRaw));
        $this->assertNotContains('result', $this->kinds($noResult));
        $this->assertNotContains('level', $this->kinds($noResult));
        $this->assertNotContains('O resultado é calculado pelo motor de avaliação a partir das pontuações registadas; não é a classificação do período.', $noResult['notes']);

        $this->assertNull($noLegend['legend']['questions']);
        $this->assertSame(['Q1', 'Q2'], array_column($full['legend']['questions'], 'code'));
        $this->assertSame(['Resolução de equações', 'Áreas e perímetros'], array_column($full['legend']['questions'], 'label'));
        $this->assertSame(['Números e Operações 100 %'], $full['legend']['questions'][0]['domains']);

        // The questions are always there, whatever else is switched off.
        $bare = $this->grid($this->teste1(), InstrumentGridOptions::fromQuery(['bruta' => '0', 'resultado' => '0', 'dominios' => '0', 'legenda' => '0']));
        $this->assertSame(['item', 'item'], $this->kinds($bare));
    }

    #[Test]
    public function a_scale_without_bands_has_a_result_and_no_level_column(): void
    {
        $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Físico-Química']);
        $instrument = $this->asTenant(function () use ($subject): Instrument {
            $year = AcademicYear::firstOrFail();
            $profile = app(ProfileBuilder::class)->create(
                ['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => 'FQ – 0 a 20', 'description' => 'Sem bandas.'],
                Scale::where('name', 'Escala 0 a 20')->firstOrFail()->id,
                [['name' => 'Domínio único', 'weight' => 100]],
                ['8.º'],
            );
            $version = app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $this->teacher);

            $class = SchoolClass::create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id,
                'label' => '8.º B', 'grade_level' => '8.º', 'status' => 'active',
                'assessment_profile_version_id' => $version->id,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);
            app(StudentEnrollmentService::class)->enrollNew($class, ['name' => 'Aluno B1', 'class_number' => 1, 'enrolled_on' => '2026-09-14']);

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $year->periods()->firstOrFail()->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Teste FQ', 'applied_on' => '2026-10-15', 'status' => 'in_correction',
                'counts_toward_classification' => true, 'purpose' => 'summative', 'total_points' => 10,
            ], [[
                'code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10,
                'domains' => [['domain_id' => Domain::where('name', 'Domínio único')->firstOrFail()->id, 'allocation_percent' => 100]],
            ]]);

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => Enrollment::where('class_id', $class->id)->firstOrFail()->id,
                'instrument_item_id' => $instrument->items()->firstOrFail()->id,
                'result_state' => ResultState::Assessed->value,
                'points_earned' => 7.0,
            ]], $this->teacher);

            return $instrument;
        });

        $grid = $this->grid($instrument);

        $this->assertNotContains('level', $this->kinds($grid));
        $this->assertContains('result', $this->kinds($grid));
        $this->assertSame('7/10', $this->cellOf($grid, '1', 'raw')['text']);
    }

    // ================================================================== estado

    #[Test]
    public function an_instrument_still_being_corrected_is_marked_provisional_and_a_concluded_one_is_not(): void
    {
        $provisional = $this->grid($this->teste1());

        $this->assertTrue($provisional['state']['is_provisional']);
        $this->assertSame('PROVISÓRIO', $provisional['state']['label']);

        $this->completeScenario();
        $concluded = $this->grid($this->teste1());

        $this->assertFalse($concluded['state']['is_provisional']);
        $this->assertSame('Correção concluída', $concluded['state']['label']);
    }

    #[Test]
    public function a_draft_and_an_annulled_instrument_have_no_grid_to_print(): void
    {
        $draft = $this->asTenant(function (): Instrument {
            return Instrument::findOrFail($this->makeInstrument(['title' => 'Rascunho', 'status' => 'draft'], [
                ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10],
            ])->id);
        });

        $this->expectException(InstrumentGridUnavailable::class);
        $this->grid($draft);
    }

    #[Test]
    public function an_annulled_instrument_is_refused_too(): void
    {
        $instrument = $this->asTenant(function (): Instrument {
            $instrument = Instrument::findOrFail($this->teste1()->id);
            $instrument->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Anulado'])->save();

            return $instrument;
        });

        $this->expectException(InstrumentGridUnavailable::class);
        $this->grid($instrument);
    }

    #[Test]
    public function a_diagnostic_instrument_says_so_and_carries_its_note(): void
    {
        $numeros = $this->domain('Números e Operações');
        $instrument = $this->asTenant(fn (): Instrument => $this->makeInstrument([
            'title' => 'Diagnóstico', 'purpose' => 'diagnostic', 'counts_toward_classification' => false, 'total_points' => 10,
        ], [
            ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]],
        ]));

        $grid = $this->grid($instrument);

        $this->assertTrue($grid['is_diagnostic']);
        $this->assertSame('Diagnóstica', $grid['meta']['purpose']);
        $this->assertNotEmpty(array_filter($grid['notes'], fn (string $note): bool => str_contains($note, 'Finalidade diagnóstica')));
        $this->assertFalse($this->grid($this->teste1())['is_diagnostic']);
    }

    // ============================================================== privacidade

    #[Test]
    public function nothing_that_identifies_a_row_or_a_free_text_note_travels(): void
    {
        $this->asTenant(function (): void {
            $instrument = $this->teste1();
            $instrument->forceFill(['internal_notes' => 'NOTA-INTERNA-CONFIDENCIAL'])->save();

            StudentItemScore::where('instrument_id', $instrument->id)
                ->where('result_state', ResultState::Absent->value)
                ->update(['state_reason' => 'MOTIVO-DE-SAUDE-DO-ALUNO']);
        });

        $grid = $this->grid($this->teste1());
        $json = json_encode($grid, JSON_UNESCAPED_UNICODE);

        foreach (['NOTA-INTERNA-CONFIDENCIAL', 'MOTIVO-DE-SAUDE-DO-ALUNO'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        $forbidden = ['id', 'ulid', 'enrollment_id', 'student_id', 'photo_url', 'photo', 'state_reason', 'internal_notes', 'birth_date', 'school_number', 'enrolled_on', 'left_on', 'lock_version', 'pseudonym_code'];
        $found = [];
        array_walk_recursive($grid, function ($value, $key) use ($forbidden, &$found): void {
            if (in_array($key, $forbidden, true)) {
                $found[] = $key;
            }
        });
        $this->assertSame([], $found);

        // The only keys of a row are its place in the class, its name and its cells.
        $this->assertSame(['number', 'name', 'cells'], array_keys($grid['blocks'][0]['rows'][0]));
        $this->assertSame(['name', 'header_lines', 'footer_note', 'is_configured', 'logo_url', 'logo'], array_keys($grid['identity']));
    }

    #[Test]
    public function no_ai_service_is_called(): void
    {
        // A strict mock: any call to the gateway fails the test.
        $this->mock(AiGateway::class);

        $this->grid($this->teste1());

        $this->addToAssertionCount(1);
    }

    // ============================================================== desempenho

    #[Test]
    public function the_cohort_is_calculated_once_and_the_queries_do_not_grow_with_the_number_of_students(): void
    {
        // Counts which loader the document uses: the cohort one, once per build —
        // never the per-student one, which is what cost four queries a student.
        $calculator = new class(app(CalculationEngine::class)) extends ClassResultsCalculator
        {
            public int $cohortCalls = 0;

            public int $perStudentCalls = 0;

            public function forInstrumentCohort(SchoolClass $class, Instrument $instrument, Collection $enrollments): array
            {
                $this->cohortCalls++;

                return parent::forInstrumentCohort($class, $instrument, $enrollments);
            }

            public function forInstruments(SchoolClass $class, Enrollment $enrollment, Collection $instruments): array
            {
                $this->perStudentCalls++;

                return parent::forInstruments($class, $enrollment, $instruments);
            }
        };
        $this->app->instance(ClassResultsCalculator::class, $calculator);

        $countQueries = function () use ($calculator): int {
            $calculator->cohortCalls = 0;
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->grid($this->teste1());
            DB::disableQueryLog();

            $this->assertSame(1, $calculator->cohortCalls, 'the cohort is calculated once per document');
            $this->assertSame(0, $calculator->perStudentCalls);

            return count(DB::getQueryLog());
        };

        $small = $countQueries();

        $this->asTenant(function (): void {
            $service = app(StudentEnrollmentService::class);

            foreach (range(8, 17) as $n) {
                $service->enrollNew($this->class(), ['name' => "Aluno {$n}", 'class_number' => $n, 'enrolled_on' => '2026-09-14']);
            }

            $this->record($this->teste1(), array_map(fn (int $n): array => [$n, 'Q1', ResultState::Assessed, 5.0], range(8, 17)));
        });

        $large = $countQueries();

        $this->assertSame($small, $large, 'ten more students must not add a single query');
        $this->assertLessThan(60, $large, 'a sane ceiling for the whole document');
    }
}
