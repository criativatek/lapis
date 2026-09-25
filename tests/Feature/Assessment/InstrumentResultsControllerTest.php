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
use App\Services\Assessment\CompleteCorrection;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Resultados tab (design spec, Annex A + addendum): built through the
 * real product path — ProfileBuilder, InstrumentBuilder, RecordScores,
 * CompleteCorrection — never a hand-rolled database fixture. A profile with
 * two domains at unequal weights (60/40) so a global figure can never
 * coincidentally equal a single domain's.
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
     * Two domains, 60/40. Six students, instrument left `in_correction`:
     *  #1 fully classified (both domains marked);
     *  #2 fully classified, lower marks (for mean/median spread);
     *  #3 absent (state=absent under exclude_all_warn — excluded, not zero);
     *  #4 exempt;
     *  #5 never marked at all (pending — no score rows);
     *  #6 enrolled AFTER the instrument's applied_on (out_of_scope);
     *  #7 partial: one domain's item marked, the other's item left pending.
     *
     * `official`-statistics tests resolve #5/#7's remaining pending cells and
     * call `CompleteCorrection` themselves — the correction cannot close
     * while any applicable student is still `pending` (`InstrumentCompleteness`),
     * and this base scenario is deliberately left that way so an
     * availability test can assert "not concluded" against it as-is.
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

    /**
     * Resolves #5's and #7's remaining pending cells (the only two blocking
     * `InstrumentCompleteness`) and completes the correction — the scenario
     * this file's "official statistics" tests build on.
     */
    private function completeScenario(): void
    {
        $this->asTenant(function (): void {
            $instrument = $this->instrument();
            $byCode = $instrument->items->keyBy('code');

            app(RecordScores::class)->save($instrument, [
                ['enrollment_id' => $this->enrollmentByNumber(5)->id, 'instrument_item_id' => $byCode['Q1']->id, 'result_state' => ResultState::Absent->value],
                ['enrollment_id' => $this->enrollmentByNumber(5)->id, 'instrument_item_id' => $byCode['Q2']->id, 'result_state' => ResultState::Absent->value],
                ['enrollment_id' => $this->enrollmentByNumber(7)->id, 'instrument_item_id' => $byCode['Q2']->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 7.0],
            ], $this->teacher);

            app(CompleteCorrection::class)->complete($this->instrument(), $this->teacher);
        });
    }

    // ======================================================= disponibilidade

    #[Test]
    public function results_are_unavailable_and_nothing_is_computed_while_in_correction(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        $response = $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados");
        $response->assertOk();

        $response->assertInertia(function ($page): void {
            $page->component('instruments/Results')
                ->where('availability.official', false)
                ->where('availability.status', 'in_correction')
                ->where('dimensions', [])
                ->where('students', [])
                ->where('report', null);

            $message = $page->toArray()['props']['availability']['message'];
            $this->assertIsString($message);
            $this->assertStringContainsString('correção deste instrumento estiver concluída', $message);
        });
    }

    #[Test]
    public function the_print_route_mirrors_availability_while_in_correction(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        $this->actingAs($this->teacher)
            ->get("/instruments/{$ulid}/resultados/relatorio?individual=1")
            ->assertInertia(fn ($page) => $page
                ->component('instruments/results/Print')
                ->where('availability.official', false)
                ->where('students', [])
                ->where('report', null));
    }

    #[Test]
    public function a_cancelled_instrument_gets_the_cancellation_message(): void
    {
        $ulid = $this->asTenant(function (): string {
            $instrument = $this->instrument();
            $instrument->update(['status' => 'cancelled', 'cancellation_reason' => 'Erro no enunciado.']);

            return $instrument->ulid;
        });

        $this->actingAs($this->teacher)
            ->get("/instruments/{$ulid}/resultados")
            ->assertInertia(fn ($page) => $page
                ->where('availability.official', false)
                ->where('availability.message', 'Este instrumento foi anulado: não tem resultados oficiais.'));
    }

    // ============================================================ o número

    #[Test]
    public function the_grid_computes_official_cells_for_any_non_draft_instrument_matching_the_engine(): void
    {
        [$expected, $instrumentUlid] = $this->asTenant(function (): array {
            $class = $this->class();
            $instrument = $this->instrument();
            $calculator = app(ClassResultsCalculator::class);

            $expected = [];
            foreach ([1, 2, 3, 4, 7] as $number) {
                $enrollment = $this->enrollmentByNumber($number);
                $outcome = $calculator->forInstruments($class, $enrollment, collect([$instrument]))[$instrument->id];
                $expected[$enrollment->id] = $outcome->normalizedValue;
            }

            return [$expected, $instrument->ulid];
        });

        $response = $this->actingAs($this->teacher)->get("/instruments/{$instrumentUlid}");
        $response->assertOk();

        $response->assertInertia(function ($page) use ($expected): void {
            $page->component('instruments/Grid')
                ->where('official.status', 'provisional')
                ->where('official.label', 'Classificação provisória');

            $students = $page->toArray()['props']['official']['students'];

            foreach ($expected as $enrollmentId => $exact) {
                $this->assertArrayHasKey($enrollmentId, $students, "Enrollment {$enrollmentId} missing from official.students.");
                $this->assertSame($exact, $students[$enrollmentId]['global']['exact'], "Enrollment {$enrollmentId}'s official value diverges from the engine.");
            }
        });
    }

    #[Test]
    public function statuses_are_derived_correctly_in_the_official_grid_cells(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);
        $ids = $this->asTenant(fn () => [
            1 => $this->enrollmentByNumber(1)->id, 2 => $this->enrollmentByNumber(2)->id,
            3 => $this->enrollmentByNumber(3)->id, 4 => $this->enrollmentByNumber(4)->id,
            5 => $this->enrollmentByNumber(5)->id, 6 => $this->enrollmentByNumber(6)->id,
            7 => $this->enrollmentByNumber(7)->id,
        ]);

        $this->actingAs($this->teacher)->get("/instruments/{$ulid}")->assertInertia(function ($page) use ($ids): void {
            $students = $page->toArray()['props']['official']['students'];

            $this->assertSame('classified', $students[$ids[1]]['status']);
            $this->assertSame('classified', $students[$ids[2]]['status']);
            $this->assertSame('absent', $students[$ids[3]]['status']);
            $this->assertSame('exempt', $students[$ids[4]]['status']);
            $this->assertSame('pending', $students[$ids[5]]['status']);
            $this->assertSame('out_of_scope', $students[$ids[6]]['status']);
            $this->assertSame('classified', $students[$ids[7]]['status']);
            $this->assertTrue($students[$ids[7]]['global']['is_partial'], 'Student #7 has one item still pending.');
        });
    }

    // ==================================================== conclusão/reabertura

    #[Test]
    public function official_statistics_appear_on_completion_and_disappear_on_reopen(): void
    {
        $this->completeScenario();
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        // Baseline captured right AFTER completion (which itself legitimately
        // records the 3 resolving cells): the invariant under test is that
        // COMPLETING/REOPENING the correction — as opposed to marking cells —
        // never itself writes a classification, snapshot, or score.
        $afterComplete = $this->asTenant(fn () => [
            Classification::count(), CalculationSnapshot::count(), StudentItemScore::count(),
        ]);

        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados")->assertInertia(function ($page): void {
            $page->component('instruments/Results')
                ->where('availability.official', true)
                ->where('availability.status', 'completed');

            $props = $page->toArray()['props'];
            $this->assertNotSame([], $props['dimensions']);
            $this->assertNotSame([], $props['students']);
            $this->assertNotNull($props['report']);

            $global = collect($props['dimensions'])->firstWhere('key', 'global')['analysis'];
            // Universe excludes #6: 6 students. Classified: #1, #2, #7 (now fully resolved).
            $this->assertSame(6, $global['universe']);
            $this->assertSame(3, $global['classified']);
            $this->assertSame(0, $global['partial'], '#7 is fully resolved once completed.');
            $this->assertSame(1, $global['out_of_scope']);
            $this->assertSame(2, $global['missing']['absent'], '#3 and now #5.');
            $this->assertSame(1, $global['missing']['exempt']);
            $this->assertSame(0, $global['missing']['pending']);
        });

        // Reopen: back to provisional/unavailable, and the note is untouched
        // (§ notes live in their own table, never touched by recalculation).
        $this->asTenant(function (): void {
            $this->actingAs($this->teacher)
                ->put('/instruments/'.$this->instrument()->ulid.'/resultados/observacoes', ['body' => 'Nota antes de reabrir.', 'lock_version' => 0])
                ->assertRedirect();
        });

        $this->asTenant(fn () => app(CompleteCorrection::class)->reopen($this->instrument(), $this->teacher));

        $afterReopen = $this->asTenant(fn () => [
            Classification::count(), CalculationSnapshot::count(), StudentItemScore::count(),
        ]);

        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados")->assertInertia(fn ($page) => $page
            ->where('availability.official', false)
            ->where('availability.status', 'in_correction')
            ->where('dimensions', [])
            ->where('students', [])
            ->where('note.body', 'Nota antes de reabrir.'));

        // Complete again: the numbers come back, byte for byte.
        $this->asTenant(fn () => app(CompleteCorrection::class)->complete($this->instrument(), $this->teacher));

        $afterRecomplete = $this->asTenant(fn () => [
            Classification::count(), CalculationSnapshot::count(), StudentItemScore::count(),
        ]);

        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados")->assertInertia(fn ($page) => $page
            ->where('availability.official', true)
            ->where('note.body', 'Nota antes de reabrir.'));

        $this->assertSame($afterComplete, $afterReopen, 'Reopening must never write a classification, snapshot, or score.');
        $this->assertSame($afterComplete, $afterRecomplete, 'Re-completing must never write a classification, snapshot, or score.');
    }

    #[Test]
    public function the_official_grid_cells_are_identical_to_results_students_once_concluded(): void
    {
        $this->completeScenario();
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);

        $gridStudents = null;
        $this->actingAs($this->teacher)->get("/instruments/{$ulid}")->assertInertia(function ($page) use (&$gridStudents): void {
            $page->where('official.status', 'official')->where('official.label', 'Classificação oficial');
            $gridStudents = $page->toArray()['props']['official']['students'];
        });

        $resultsStudents = null;
        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados")->assertInertia(function ($page) use (&$resultsStudents): void {
            $resultsStudents = collect($page->toArray()['props']['students'])->keyBy('enrollment_id');
        });

        $this->assertNotNull($gridStudents);
        $this->assertNotNull($resultsStudents);

        foreach ($resultsStudents as $enrollmentId => $student) {
            $this->assertArrayHasKey($enrollmentId, $gridStudents);
            $this->assertSame($student['status'], $gridStudents[$enrollmentId]['status']);
            $this->assertSame($student['global'], $gridStudents[$enrollmentId]['global']);
            $this->assertSame($student['domains'], $gridStudents[$enrollmentId]['domains']);
        }
    }

    // ========================================================= diagnóstico

    #[Test]
    public function a_diagnostic_instrument_reports_its_context_even_before_being_concluded(): void
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
                ->where('context.diagnostic_counts_warning', false)
                ->where('availability.official', false);
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

    #[Test]
    public function a_concluded_diagnostic_shows_official_statistics_and_a_diagnostic_report_title(): void
    {
        $ulid = $this->asTenant(function (): string {
            $class = $this->class();
            $period = $class->academicYear->periods()->firstOrFail();
            $domain = Domain::where('name', 'Números e Operações')->firstOrFail();

            // A dedicated early-enrolled student and an instrument applied
            // before every other scenario student enrolled: it is the ONLY
            // applicable enrollment, so one cell is enough to complete it.
            $enrollment = app(StudentEnrollmentService::class)->enrollNew($class, [
                'name' => 'Aluno Diagnóstico', 'class_number' => 8, 'enrolled_on' => '2026-09-01',
            ]);

            $diagnostic = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Diagnóstico Concluído',
                'applied_on' => '2026-09-10',
                'status' => 'in_correction',
                'counts_toward_classification' => false,
                'purpose' => 'diagnostic',
                'total_points' => 10,
            ], [
                ['code' => 'D1', 'label' => 'D1', 'points_possible' => 10, 'domains' => [['domain_id' => $domain->id, 'allocation_percent' => 100]]],
            ]);

            app(RecordScores::class)->save($diagnostic, [
                ['enrollment_id' => $enrollment->id, 'instrument_item_id' => $diagnostic->items->first()->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 6.0],
            ], $this->teacher);

            $inScope = app(ClassResultsCalculator::class)->instrumentsInScope($class, $period, ClassificationScope::Period);
            $this->assertFalse($inScope->contains('id', $diagnostic->id));

            app(CompleteCorrection::class)->complete($diagnostic, $this->teacher);

            return $diagnostic->ulid;
        });

        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/resultados")->assertInertia(function ($page): void {
            $page->component('instruments/Results')
                ->where('availability.official', true)
                ->where('report.title', 'Relatório da avaliação diagnóstica');

            $this->assertNotSame([], $page->toArray()['props']['dimensions']);
        });
    }

    // ============================================================= leitura

    #[Test]
    public function viewing_the_results_never_writes_anything(): void
    {
        $this->completeScenario();
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
        $this->completeScenario();
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
        $this->completeScenario();
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
    public function concurrent_first_note_creation_is_refused_without_data_loss(): void
    {
        $ulid = $this->asTenant(fn () => $this->instrument()->ulid);
        $instrumentId = $this->asTenant(fn () => $this->instrument()->id);

        // Simulate two tabs racing to create the FIRST note at once: a
        // `creating` listener inserts the competing row — as another
        // request's transaction would have just committed — immediately
        // before this request's own INSERT, reproducing the interleaving a
        // literal concurrent HTTP request would produce.
        $raced = false;
        ResultsAnalysisNote::creating(function () use (&$raced, $instrumentId): void {
            if ($raced) {
                return;
            }
            $raced = true;

            DB::table('results_analysis_notes')->insert([
                'ulid' => (string) Str::ulid(),
                'organization_id' => $this->organization->id,
                'context_kind' => 'instrument',
                'instrument_id' => $instrumentId,
                'body' => 'Nota da outra janela.',
                'lock_version' => 1,
                'created_by' => $this->teacher->id,
                'updated_by' => $this->teacher->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($this->teacher)
            ->put("/instruments/{$ulid}/resultados/observacoes", ['body' => 'A minha nota.', 'lock_version' => 0])
            ->assertSessionHasErrors('body');

        $note = $this->asTenant(fn () => ResultsAnalysisNote::where('instrument_id', $instrumentId)->first());
        $this->assertNotNull($note);
        $this->assertSame('Nota da outra janela.', $note->body, 'The competing row must survive untouched — no 500, no silent overwrite.');
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

        // And the indicators DID move — the recalculation is real (read via
        // the grid's official cells, which are computed regardless of
        // whether the correction is concluded).
        $enrollmentId = $this->asTenant(fn () => $this->enrollmentByNumber(2)->id);
        $this->actingAs($this->teacher)->get("/instruments/{$ulid}")->assertInertia(function ($page) use ($enrollmentId): void {
            $students = $page->toArray()['props']['official']['students'];
            $this->assertNotSame('35.000000', $students[$enrollmentId]['global']['exact']);
        });
    }

    #[Test]
    public function the_same_item_code_in_two_groups_never_hides_a_state(): void
    {
        // An item code is unique only within its group: «Q1» of Oralidade and
        // «Q1» of Gramática are different items. Keying exclusions by code let
        // one state overwrite the other — a pending cell hidden behind an
        // absence, and a partial result reported as complete.
        [$ulid, $eid1, $eid2] = $this->asTenant(function (): array {
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

            return [$instrument->ulid, $this->enrollmentByNumber(1)->id, $this->enrollmentByNumber(2)->id];
        });

        $this->actingAs($this->teacher)->get("/instruments/{$ulid}")->assertInertia(function ($page) use ($eid1, $eid2): void {
            $students = $page->toArray()['props']['official']['students'];

            $this->assertSame('classified', $students[$eid1]['status']);
            $this->assertTrue($students[$eid1]['global']['is_partial']);
            $this->assertSame('pending', $students[$eid2]['status']);
        });
    }
}
