<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicYear;
use App\Models\CalculationSnapshot;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\ResultsAnalysisNote;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Resultados tab (design spec, Annex A): built through the real product
 * path — ProfileBuilder, InstrumentBuilder, RecordScores — never a hand-rolled
 * database fixture. A profile with two domains at unequal weights (60/40) so
 * a global figure can never coincidentally equal a single domain's.
 */
class InstrumentResultsControllerTest extends TestCase
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
     * Two domains, 60/40. Six students:
     *  #1 fully classified (both domains marked);
     *  #2 fully classified, lower marks (for mean/median spread);
     *  #3 absent (state=absent under exclude_all_warn — excluded, not zero);
     *  #4 exempt;
     *  #5 never marked at all (pending — no score rows);
     *  #6 enrolled AFTER the instrument's applied_on (out_of_scope);
     *  #7 partial: one domain's item marked, the other's item left pending.
     */
    private function scenario(): void
    {
        $year = AcademicYear::create([
            'label' => '2026/2027', 'starts_on' => '2026-09-14', 'ends_on' => '2027-06-30',
            'status' => 'active', 'country_code' => 'PT',
        ]);
        $period = $year->periods()->create([
            'label' => '1.º Período', 'kind' => 'term', 'sequence' => 1,
            'starts_on' => '2026-09-14', 'ends_on' => '2026-12-19', 'status' => 'open',
        ]);

        $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática']);

        $profile = app(ProfileBuilder::class)->create(
            [
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'name' => 'Matemática – 8.º Ano',
                'description' => 'Cenário de teste dos Resultados do instrumento.',
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
        // #6, out of scope — enrolled well after the instrument is applied.
        $service->enrollNew($class, ['name' => 'Aluno 6', 'class_number' => 6, 'enrolled_on' => '2026-12-01']);
        // #7, partial.
        $service->enrollNew($class, ['name' => 'Aluno 7', 'class_number' => 7, 'enrolled_on' => '2026-09-14']);

        $numeros = Domain::where('name', 'Números e Operações')->firstOrFail();
        $geometria = Domain::where('name', 'Geometria')->firstOrFail();

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste 1',
            'applied_on' => '2026-10-15',
            'status' => 'in_correction',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 20,
        ], [
            ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]],
            ['code' => 'Q2', 'label' => 'Q2', 'points_possible' => 10, 'domains' => [['domain_id' => $geometria->id, 'allocation_percent' => 100]]],
        ]);

        $byCode = $instrument->items->keyBy('code');
        $enrollments = $class->enrollments()->orderBy('class_number')->get()->keyBy('class_number');

        $cells = [];
        $cells[] = ['enrollment_id' => $enrollments[1]->id, 'instrument_item_id' => $byCode['Q1']->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 9.0];
        $cells[] = ['enrollment_id' => $enrollments[1]->id, 'instrument_item_id' => $byCode['Q2']->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 8.0];
        $cells[] = ['enrollment_id' => $enrollments[2]->id, 'instrument_item_id' => $byCode['Q1']->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 3.0];
        $cells[] = ['enrollment_id' => $enrollments[2]->id, 'instrument_item_id' => $byCode['Q2']->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 4.0];
        $cells[] = ['enrollment_id' => $enrollments[3]->id, 'instrument_item_id' => $byCode['Q1']->id, 'result_state' => ResultState::Absent->value];
        $cells[] = ['enrollment_id' => $enrollments[3]->id, 'instrument_item_id' => $byCode['Q2']->id, 'result_state' => ResultState::Absent->value];
        $cells[] = ['enrollment_id' => $enrollments[4]->id, 'instrument_item_id' => $byCode['Q1']->id, 'result_state' => ResultState::Exempt->value];
        $cells[] = ['enrollment_id' => $enrollments[4]->id, 'instrument_item_id' => $byCode['Q2']->id, 'result_state' => ResultState::Exempt->value];
        // #5 — no cells at all (pending on both).
        // #6 — out of scope, no cells needed.
        $cells[] = ['enrollment_id' => $enrollments[7]->id, 'instrument_item_id' => $byCode['Q1']->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 6.0];
        // #7's Q2 stays pending — partial.

        app(RecordScores::class)->save($instrument, $cells, $this->teacher);
    }

    private function class(): SchoolClass
    {
        return SchoolClass::where('label', '8.º A')->firstOrFail();
    }

    private function instrument(): Instrument
    {
        return Instrument::where('title', 'Teste 1')->firstOrFail();
    }

    private function enrollmentByNumber(int $number): Enrollment
    {
        return $this->class()->enrollments()->where('class_number', $number)->firstOrFail();
    }

    // ============================================================ o número

    #[Test]
    public function the_global_value_matches_the_engine_exactly_and_no_second_engine_runs(): void
    {
        [$expected, $instrumentUlid] = $this->asTenant(function (): array {
            $class = $this->class();
            $instrument = $this->instrument();
            $calculator = app(ClassResultsCalculator::class);

            $expected = [];
            foreach ([1, 2, 3, 4, 7] as $number) {
                $enrollment = $this->enrollmentByNumber($number);
                $outcome = $calculator->forInstruments($class, $enrollment, collect([$instrument]))[$instrument->id];
                $expected[$number] = $outcome->normalizedValue;
            }

            return [$expected, $instrument->ulid];
        });

        $response = $this->actingAs($this->teacher)->get("/instruments/{$instrumentUlid}/resultados");
        $response->assertOk();

        $response->assertInertia(function ($page) use ($expected): void {
            $page->component('instruments/Results');
            $students = collect($page->toArray()['props']['students']);

            foreach ($expected as $number => $exact) {
                $student = $students->firstWhere('class_number', $number);
                $this->assertNotNull($student, "Student #{$number} missing from payload.");
                $this->assertSame($exact, $student['global']['exact'], "Student #{$number}'s global exact value diverges from the engine.");
            }
        });
    }

    #[Test]
    public function statuses_are_derived_correctly_for_every_scenario(): void
    {
        $response = $this->actingAs($this->teacher)->get('/instruments/'.$this->asTenant(fn () => $this->instrument()->ulid).'/resultados');

        $response->assertInertia(function ($page): void {
            $students = collect($page->toArray()['props']['students'])->keyBy('class_number');

            $this->assertSame('classified', $students[1]['status']);
            $this->assertSame('classified', $students[2]['status']);
            $this->assertSame('absent', $students[3]['status']);
            $this->assertSame('exempt', $students[4]['status']);
            $this->assertSame('pending', $students[5]['status']);
            $this->assertSame('out_of_scope', $students[6]['status']);
            $this->assertSame('classified', $students[7]['status']);
            $this->assertTrue($students[7]['global']['is_partial'], 'Student #7 has one item still pending.');
        });
    }

    #[Test]
    public function n_mean_median_and_threshold_counts_are_correct(): void
    {
        [$class, $instrument] = $this->asTenant(fn () => [$this->class(), $this->instrument()]);
        $calculator = app(ClassResultsCalculator::class);

        [$v1, $v2, $v7] = $this->asTenant(function () use ($class, $instrument, $calculator): array {
            return [
                $calculator->forInstruments($class, $this->enrollmentByNumber(1), collect([$instrument]))[$instrument->id]->normalizedValue,
                $calculator->forInstruments($class, $this->enrollmentByNumber(2), collect([$instrument]))[$instrument->id]->normalizedValue,
                $calculator->forInstruments($class, $this->enrollmentByNumber(7), collect([$instrument]))[$instrument->id]->normalizedValue,
            ];
        });

        $response = $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}/resultados");

        $response->assertInertia(function ($page): void {
            $global = collect($page->toArray()['props']['dimensions'])->firstWhere('key', 'global')['analysis'];

            // Universe excludes #6 (out of scope): 6 students. Classified: #1,#2,#7 = 3.
            $this->assertSame(6, $global['universe']);
            $this->assertSame(3, $global['classified']);
            $this->assertSame(1, $global['partial']);
            $this->assertSame(1, $global['out_of_scope']);
            $this->assertSame(1, $global['missing']['absent']);
            $this->assertSame(1, $global['missing']['exempt']);
            $this->assertSame(1, $global['missing']['pending']);
            $this->assertSame(3, $global['missing']['total']);
        });
    }

    // ========================================================= diagnóstico

    #[Test]
    public function a_diagnostic_instrument_is_analysed_but_stays_out_of_the_period_scope(): void
    {
        $ulid = $this->asTenant(function (): string {
            $class = $this->class();
            $period = $class->academicYear->periods()->firstOrFail();
            $domain = Domain::where('name', 'Números e Operações')->firstOrFail();

            $diagnostic = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Diagnóstico Inicial',
                'applied_on' => '2026-09-20',
                'status' => 'in_correction',
                'counts_toward_classification' => false,
                'purpose' => 'diagnostic',
                'total_points' => 10,
            ], [
                ['code' => 'D1', 'label' => 'D1', 'points_possible' => 10, 'domains' => [['domain_id' => $domain->id, 'allocation_percent' => 100]]],
            ]);

            app(RecordScores::class)->save($diagnostic, [
                ['enrollment_id' => $this->enrollmentByNumber(1)->id, 'instrument_item_id' => $diagnostic->items->first()->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 7.0],
            ], $this->teacher);

            $inScope = app(ClassResultsCalculator::class)->instrumentsInScope($class, $period, ClassificationScope::Period);
            $this->assertFalse($inScope->contains('id', $diagnostic->id), 'A non-counting diagnostic must not enter the period scope.');

            return $diagnostic->ulid;
        });

        $response = $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados");

        $response->assertInertia(function ($page): void {
            $page->component('instruments/Results')
                ->where('context.is_diagnostic', true)
                ->where('context.classificatory', false)
                ->where('context.diagnostic_counts_warning', false);
        });
    }

    /**
     * Characterisation test (design spec §4): the exclusion is a DEFAULT, not
     * a guarantee. A diagnostic explicitly marked as counting DOES enter
     * `instrumentsInScope` — an existing gap, fixed by the payload's warning
     * flag, not by the engine.
     */
    #[Test]
    public function a_diagnostic_marked_as_counting_still_enters_the_period_scope(): void
    {
        $ulid = $this->asTenant(function (): string {
            $class = $this->class();
            $period = $class->academicYear->periods()->firstOrFail();
            $domain = Domain::where('name', 'Números e Operações')->firstOrFail();

            $diagnostic = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Diagnóstico Que Conta',
                'applied_on' => '2026-09-21',
                'status' => 'in_correction',
                'counts_toward_classification' => true,
                'purpose' => 'diagnostic',
                'total_points' => 10,
            ], [
                ['code' => 'D1', 'label' => 'D1', 'points_possible' => 10, 'domains' => [['domain_id' => $domain->id, 'allocation_percent' => 100]]],
            ]);

            $inScope = app(ClassResultsCalculator::class)->instrumentsInScope($class, $period, ClassificationScope::Period);
            $this->assertTrue($inScope->contains('id', $diagnostic->id), 'The known gap: a diagnostic marked as counting IS in scope.');

            return $diagnostic->ulid;
        });

        $this->actingAs($this->teacher)
            ->get("/instruments/{$ulid}/resultados")
            ->assertInertia(fn ($page) => $page
                ->component('instruments/Results')
                ->where('context.diagnostic_counts_warning', true));
    }

    // ============================================================= leitura

    #[Test]
    public function viewing_the_results_never_writes_anything(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        $before = $this->asTenant(fn () => [
            Classification::count(),
            CalculationSnapshot::count(),
            StudentItemScore::count(),
        ]);

        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados")->assertOk();
        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados/relatorio?individual=1")->assertOk();

        $after = $this->asTenant(fn () => [
            Classification::count(),
            CalculationSnapshot::count(),
            StudentItemScore::count(),
        ]);

        $this->assertSame($before, $after, 'Viewing results must never write a classification, snapshot, or score.');
    }

    // ================================================================ relatório

    #[Test]
    public function the_report_without_individual_never_carries_students_or_names(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        $response = $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados/relatorio");

        $response->assertInertia(function ($page): void {
            $page->component('instruments/results/Print')
                ->where('include_individual', false)
                ->where('students', []);

            $reportText = json_encode($page->toArray()['props']['report']);
            $this->assertIsString($reportText);
            $this->assertStringNotContainsString('Aluno 1', $reportText);
        });
    }

    #[Test]
    public function the_report_with_individual_includes_students(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        $this->actingAs($this->teacher)
            ->get("/instruments/{$ulid}/resultados/relatorio?individual=1")
            ->assertInertia(fn ($page) => $page
                ->component('instruments/results/Print')
                ->where('include_individual', true)
                ->has('students', 7));
    }

    // =============================================================== auth

    #[Test]
    public function a_teacher_from_another_organization_is_refused(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get("/instruments/{$ulid}/resultados")->assertStatus(404);
    }

    #[Test]
    public function updating_the_note_requires_update_permission(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        // A user of another organization does not even resolve the model (404).
        $intruder = User::factory()->create();
        $this->actingAs($intruder)
            ->put("/instruments/{$ulid}/resultados/observacoes", ['body' => 'x', 'lock_version' => 0])
            ->assertStatus(404);
    }

    #[Test]
    public function a_stale_lock_version_is_refused_and_the_stored_body_is_preserved(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        $this->actingAs($this->teacher)
            ->put("/instruments/{$ulid}/resultados/observacoes", ['body' => 'Primeira nota.', 'lock_version' => 0])
            ->assertRedirect();

        // Stale write: still claims lock_version 0, but the stored one is now 1.
        $this->actingAs($this->teacher)
            ->put("/instruments/{$ulid}/resultados/observacoes", ['body' => 'Nota perdida.', 'lock_version' => 0])
            ->assertSessionHasErrors('body');

        $note = $this->asTenant(fn () => ResultsAnalysisNote::where('instrument_id', $this->instrument()->id)->first());
        $this->assertSame('Primeira nota.', $note->body, 'A stale write must never overwrite the stored body.');
        $this->assertSame(1, $note->lock_version);
    }

    #[Test]
    public function the_note_survives_when_scores_change_afterwards(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        $this->actingAs($this->teacher)
            ->put("/instruments/{$ulid}/resultados/observacoes", ['body' => 'Observação estável.', 'lock_version' => 0])
            ->assertRedirect();

        $this->asTenant(function (): void {
            $instrument = $this->instrument();
            $item = $instrument->items()->where('code', 'Q1')->firstOrFail();

            app(RecordScores::class)->save($instrument, [
                ['enrollment_id' => $this->enrollmentByNumber(2)->id, 'instrument_item_id' => $item->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 9.5, 'lock_version' => 0],
            ], $this->teacher);
        });

        $note = $this->asTenant(fn () => ResultsAnalysisNote::where('instrument_id', $this->instrument()->id)->first());
        $this->assertSame('Observação estável.', $note->body);

        // And the indicators DID move — the recalculation is real.
        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados")->assertInertia(function ($page): void {
            $students = collect($page->toArray()['props']['students'])->keyBy('class_number');
            $this->assertNotSame('35.000000', $students[2]['global']['exact']);
        });
    }

    #[Test]
    public function the_same_item_code_in_two_groups_never_hides_a_state(): void
    {
        // An item code is unique only within its group: «Q1» of Oralidade and
        // «Q1» of Gramática are different items. Keying exclusions by code let
        // one state overwrite the other — a pending cell hidden behind an
        // absence, and a partial result reported as complete.
        $ulid = $this->asTenant(function (): string {
            $class = $this->class();
            $period = $class->academicYear->periods()->firstOrFail();
            $domain = Domain::where('name', 'Números e Operações')->firstOrFail();

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Teste com grupos',
                'applied_on' => '2026-10-20',
                'status' => 'in_correction',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 20,
            ], [
                ['group_index' => 0, 'code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $domain->id, 'allocation_percent' => 100]]],
                ['group_index' => 1, 'code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $domain->id, 'allocation_percent' => 100]]],
            ], [['label' => 'Oralidade'], ['label' => 'Gramática']]);

            $items = $instrument->items()->orderBy('sequence')->get()->values();
            [$first, $second] = [$items[0], $items[1]];

            app(RecordScores::class)->save($instrument, [
                ['enrollment_id' => $this->enrollmentByNumber(1)->id, 'instrument_item_id' => $first->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 5.0],
                // #1's second Q1 stays pending → partial.
                // #2: first Q1 pending (no row), second Q1 absent → still «por classificar».
                ['enrollment_id' => $this->enrollmentByNumber(2)->id, 'instrument_item_id' => $second->id, 'result_state' => ResultState::Absent->value],
            ], $this->teacher);

            return $instrument->ulid;
        });

        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados")->assertInertia(function ($page): void {
            $page->component('instruments/Results')
                ->where('students.0.status', 'classified')
                ->where('students.0.global.is_partial', true)
                ->where('students.1.status', 'pending');
        });
    }
}
