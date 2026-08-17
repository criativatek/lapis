<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\InstrumentType;
use App\Models\InterimAssessment;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Services\Assessment\CompareInterimToPeriodFinal;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\RecordScores;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The photograph against where the period ended up.
 *
 * TWO SOURCES, AND THE ASYMMETRY IS THE POINT. The interim is frozen and read
 * out of its document; the final is the live canonical read model. So a
 * comparison run tomorrow may differ — because the period moved, never because
 * the photograph did, and that is exactly what these assert.
 */
class InterimComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function capture(string $date, string $name = 'Intercalar de novembro'): InterimAssessment
    {
        return $this->asTenant(fn (): InterimAssessment => app(CaptureInterimAssessment::class)->capture(
            $this->schoolClass(),
            $this->period(1),
            Carbon::parse($date),
            $this->teacher,
            ['name' => $name],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(InterimAssessment $interim): array
    {
        return $this->asTenant(fn (): array => app(CompareInterimToPeriodFinal::class)
            ->compare($this->schoolClass(), $interim));
    }

    /**
     * Adds a second instrument to the first period, after the photograph, so
     * the two ends genuinely differ.
     */
    private function addLaterInstrument(int $points = 18): void
    {
        $this->asTenant(function () use ($points): void {
            $class = $this->schoolClass();
            $leitura = Domain::where('code', 'LEITURA')->firstOrFail();

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $this->period(1)->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Ficha de dezembro',
                'applied_on' => '2026-12-10',
                'status' => 'in_correction',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 20,
            ], [
                ['code' => 'D1', 'label' => 'Leitura', 'points_possible' => 20, 'domains' => [
                    ['domain_id' => $leitura->id, 'allocation_percent' => 100],
                ]],
            ]);

            $item = $instrument->items->firstOrFail();
            $cells = [];

            foreach ($class->enrollments()->orderBy('class_number')->get() as $enrollment) {
                $cells[] = [
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $item->id,
                    'result_state' => ResultState::Assessed->value,
                    'points_earned' => $points,
                ];
            }

            app(RecordScores::class)->save($instrument, $cells, $this->teacher);
        });
    }

    // ------------------------------------------------------- 1. a turma

    #[Test]
    public function it_reads_the_photograph_and_the_live_period_without_recomputing_either(): void
    {
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument();

        $comparison = $this->compare($interim);

        // The left side is exactly what was stored.
        $this->assertSame(
            $interim->snapshot['summary']['class_average'],
            $comparison['summary']['interim_average'],
        );

        // And the photograph did not move on the way.
        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));
        $this->assertSame($interim->snapshot, $again->snapshot);
        $this->assertTrue($again->isIntact());
    }

    #[Test]
    public function it_uses_the_name_the_teacher_chose(): void
    {
        $interim = $this->capture('2026-11-15', 'Avaliação intercalar de novembro');

        $comparison = $this->compare($interim);

        // Never a label rebuilt from the date (§9 of the naming decision).
        $this->assertSame('Avaliação intercalar de novembro', $comparison['interim']['name']);
        $this->assertSame('15/11/2026', $comparison['interim']['reference_date_label']);
        $this->assertSame('1.º Semestre', $comparison['period']['label']);
    }

    #[Test]
    public function the_difference_is_in_percentage_points(): void
    {
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument(20);

        $comparison = $this->compare($interim);
        $summary = $comparison['summary'];

        $this->assertNotNull($summary['interim_average']);
        $this->assertNotNull($summary['final_average']);
        $this->assertSame(
            round((float) $summary['final_average'] - (float) $summary['interim_average'], 1),
            (float) $summary['change'],
        );
    }

    #[Test]
    public function nothing_to_compare_gives_nothing_rather_than_zero(): void
    {
        // Before any instrument of the period: the photograph has no averages.
        $interim = $this->capture('2026-10-01');

        $comparison = $this->compare($interim);

        $this->assertNull($comparison['summary']['interim_average']);
        // A missing end means no difference — never «0,0 p.p.», which would
        // read as «não mudou nada» (§37).
        $this->assertNull($comparison['summary']['change']);
        $this->assertNull($comparison['movement']['average_change']);
        $this->assertSame(0, $comparison['movement']['comparable']);
    }

    // --------------------------------------------------- 2. o movimento

    #[Test]
    public function students_are_counted_by_the_direction_they_moved(): void
    {
        $interim = $this->capture('2026-11-15');
        // A high mark for everyone afterwards: those who had a result go up.
        $this->addLaterInstrument(20);

        $movement = $this->compare($interim)['movement'];
        $total = $movement['progressed'] + $movement['stable'] + $movement['regressed'] + $movement['no_comparison'];

        $this->assertSame(count($this->compare($interim)['students']), $total);
        $this->assertGreaterThan(0, $movement['progressed']);
    }

    #[Test]
    public function a_fall_is_counted_as_a_fall(): void
    {
        $interim = $this->capture('2026-11-15');
        // A poor mark for everyone afterwards.
        $this->addLaterInstrument(2);

        $movement = $this->compare($interim)['movement'];

        $this->assertGreaterThan(0, $movement['regressed']);
        $this->assertLessThan(0, (float) $movement['average_change']);
    }

    #[Test]
    public function standing_still_is_counted_as_standing_still(): void
    {
        // Nothing happens between the photograph and now.
        $interim = $this->capture('2026-11-15');

        $movement = $this->compare($interim)['movement'];

        $this->assertGreaterThan(0, $movement['stable']);
        $this->assertSame(0, $movement['progressed']);
        $this->assertSame(0, $movement['regressed']);
        $this->assertSame('0.0', $movement['average_change']);
    }

    #[Test]
    public function a_student_missing_from_one_end_is_not_counted_as_standing_still(): void
    {
        $interim = $this->capture('2026-11-15');

        $movement = $this->compare($interim)['movement'];

        // Diogo was absent and Filipe enrolled late: neither has a first-period
        // result at either end. They have nothing to stand against (§37).
        $this->assertGreaterThanOrEqual(2, $movement['no_comparison']);

        $withoutComparison = array_values(array_filter(
            $this->compare($interim)['students'],
            fn (array $student): bool => $student['direction'] === null,
        ));

        $this->assertSame($movement['no_comparison'], count($withoutComparison));
    }

    // --------------------------------------------------- 3. os domínios

    #[Test]
    public function each_domain_is_matched_by_its_canonical_id(): void
    {
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument();

        $domains = $this->compare($interim)['domains'];
        $ids = array_column($domains, 'domain_id');

        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertNotEmpty($domains);

        foreach ($domains as $domain) {
            $this->assertArrayHasKey('interim_average', $domain);
            $this->assertArrayHasKey('final_average', $domain);
            $this->assertArrayHasKey('interim_mention', $domain);
            $this->assertArrayHasKey('final_mention', $domain);
            $this->assertArrayHasKey('interim_partial_coverage', $domain);
            $this->assertArrayHasKey('final_partial_coverage', $domain);
        }
    }

    #[Test]
    public function a_domain_renamed_after_the_photograph_keeps_its_historical_name(): void
    {
        $interim = $this->capture('2026-11-15');

        $this->asTenant(fn () => Domain::where('code', 'LEITURA')->firstOrFail()
            ->update(['name' => 'Compreensão Leitora']));

        $labels = array_column($this->compare($interim)['domains'], 'label');

        // The left-hand side is a reading of the past, and reads in the words
        // of the past (§4).
        $this->assertContains('Leitura', $labels);
        $this->assertNotContains('Compreensão Leitora', $labels);
    }

    // ----------------------------------------------------- 4. os alunos

    #[Test]
    public function each_student_carries_both_ends_and_what_lies_between(): void
    {
        $interim = $this->capture('2026-11-15');
        $this->addLaterInstrument();

        $students = $this->compare($interim)['students'];

        $this->assertNotEmpty($students);

        foreach ($students as $student) {
            $this->assertArrayHasKey('name', $student);
            $this->assertArrayHasKey('interim_average', $student);
            $this->assertArrayHasKey('final_average', $student);
            $this->assertArrayHasKey('change', $student);
            $this->assertArrayHasKey('interim_band', $student);
            $this->assertArrayHasKey('final_band', $student);
            $this->assertArrayHasKey('interim_classification', $student);
            $this->assertArrayHasKey('final_classification', $student);
            $this->assertArrayHasKey('interim_self_assessment', $student);
            $this->assertArrayHasKey('domains', $student);
            // Coverage on both sides, each true of its own moment (§10).
            $this->assertArrayHasKey('interim_coverage_warning', $student);
            $this->assertArrayHasKey('final_coverage_warning', $student);
        }
    }

    #[Test]
    public function the_student_names_are_the_ones_the_photograph_holds(): void
    {
        $interim = $this->capture('2026-11-15');

        $names = array_column($this->compare($interim)['students'], 'name');
        $stored = array_column($interim->snapshot['students'], 'name_snapshot');

        $this->assertSame($stored, $names);
    }

    // --------------------------------------------- 5. correr não altera

    #[Test]
    public function comparing_writes_nothing_at_all(): void
    {
        $interim = $this->capture('2026-11-15');

        $before = $this->asTenant(fn (): array => [
            'interims' => InterimAssessment::query()->count(),
            'scores' => StudentItemScore::query()->count(),
        ]);

        $this->compare($interim);
        $this->compare($interim);

        $after = $this->asTenant(fn (): array => [
            'interims' => InterimAssessment::query()->count(),
            'scores' => StudentItemScore::query()->count(),
        ]);

        $this->assertSame($before, $after);
    }

    // ------------------------------------------------------ 6. a página

    #[Test]
    public function the_comparison_page_renders(): void
    {
        $interim = $this->capture('2026-11-15', 'Avaliação intercalar de novembro');
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}/comparar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('results/InterimComparison')
                ->where('comparison.interim.name', 'Avaliação intercalar de novembro')
                ->has('comparison.summary')
                ->has('comparison.movement')
                ->has('comparison.domains')
                ->has('comparison.students'));
    }

    #[Test]
    public function another_organization_cannot_compare_this_moment(): void
    {
        $interim = $this->capture('2026-11-15');
        $class = $this->schoolClass();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}/comparar")
            ->assertNotFound();
    }
}
