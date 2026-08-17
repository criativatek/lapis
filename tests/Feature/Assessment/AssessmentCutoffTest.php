<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\Instrument;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\ClassResultsCalculator;
use App\Support\Assessment\AssessmentCutoff;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Dados até 15/11/2026» — how the class was doing back then.
 *
 * The whole feature rests on one decision: WHICH DATE COUNTS. An element
 * belongs to the day it was applied, not to the day somebody typed it into a
 * computer — a test sat in October and imported in December belongs to October,
 * and a cutoff keyed on `created_at` would quietly say otherwise.
 *
 * The other thing worth defending is that an open cutoff is genuinely free: a
 * school that never asks this question must get byte-identical results.
 */
class AssessmentCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
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

    /** The first period's only instrument was applied on 2026-10-15. */
    private function firstInstrument(): Instrument
    {
        return $this->asTenant(fn (): Instrument => Instrument::where('title', 'Teste de Compreensão Leitora')->firstOrFail());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function results(?string $until): array
    {
        return $this->asTenant(fn (): array => app(ClassResultsCalculator::class)->forPeriod(
            $this->schoolClass(),
            $this->period(1),
            AssessmentCutoff::on($until),
        ));
    }

    /** How many students have a computable result under this cutoff. */
    private function withResult(?string $until): int
    {
        return count(array_filter(
            $this->results($until),
            fn (array $row): bool => $row['outcome']->normalizedValue !== null,
        ));
    }

    // ------------------------------------------------- 1. o que entra

    #[Test]
    public function an_element_applied_before_the_cutoff_counts(): void
    {
        $this->assertGreaterThan(0, $this->withResult('2026-10-16'));
    }

    #[Test]
    public function an_element_applied_after_the_cutoff_does_not(): void
    {
        // The day before the test was given: nothing had happened yet.
        $this->assertSame(0, $this->withResult('2026-10-14'));
    }

    #[Test]
    public function an_element_applied_on_the_cutoff_day_itself_counts(): void
    {
        // INCLUSIVE. A teacher who says «até 15 de outubro» means the test they
        // gave that morning is in.
        $this->assertSame(
            $this->withResult('2026-10-16'),
            $this->withResult('2026-10-15'),
        );
        $this->assertGreaterThan(0, $this->withResult('2026-10-15'));
    }

    #[Test]
    public function the_date_that_counts_is_when_it_was_applied_and_not_when_it_was_recorded(): void
    {
        $instrument = $this->firstInstrument();

        // Recorded much later — imported in December, say. The element still
        // belongs to the day the class sat it.
        $this->asTenant(fn () => $instrument->forceFill([
            'created_at' => '2026-12-20 10:00:00',
            'updated_at' => '2026-12-20 10:00:00',
        ])->save());

        $this->assertGreaterThan(
            0,
            $this->withResult('2026-10-15'),
            'o cutoff tem de olhar para applied_on, nunca para created_at',
        );
    }

    // -------------------------------------- 2. o que não muda sem cutoff

    #[Test]
    public function an_open_cutoff_reproduces_todays_results_exactly(): void
    {
        $withNone = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1), AssessmentCutoff::none()));

        $withoutArgument = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1)));

        // Byte for byte: a school that never asks this question pays nothing
        // for it (§43).
        $this->assertEquals($withoutArgument, $withNone);
    }

    #[Test]
    public function a_cutoff_after_everything_reproduces_todays_results_exactly(): void
    {
        $open = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1)));

        $late = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1), AssessmentCutoff::on('2027-12-31')));

        $this->assertEquals($open, $late);
    }

    #[Test]
    public function two_different_cutoffs_produce_different_readings(): void
    {
        $before = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1), AssessmentCutoff::on('2026-10-14')));

        $after = $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1), AssessmentCutoff::on('2026-10-15')));

        $this->assertNull($before['summary']['class_average']);
        $this->assertNotNull($after['summary']['class_average']);
    }

    #[Test]
    public function reading_at_a_cutoff_writes_nothing(): void
    {
        $before = $this->asTenant(fn (): array => [
            'instruments' => Instrument::query()->count(),
            'scores' => StudentItemScore::query()->count(),
            'classifications' => Classification::query()->count(),
        ]);

        $this->asTenant(fn (): array => app(BuildClassStatistics::class)
            ->for($this->schoolClass(), $this->period(1), AssessmentCutoff::on('2026-10-20')));

        $after = $this->asTenant(fn (): array => [
            'instruments' => Instrument::query()->count(),
            'scores' => StudentItemScore::query()->count(),
            'classifications' => Classification::query()->count(),
        ]);

        // A free query records absolutely nothing. Keeping a moment is a
        // separate, deliberate act (§3).
        $this->assertSame($before, $after);
    }

    // ---------------------------------------------- 3. o objeto em si

    #[Test]
    public function an_open_cutoff_covers_everything_and_a_closed_one_does_not(): void
    {
        $open = AssessmentCutoff::none();

        $this->assertTrue($open->isOpen());
        $this->assertTrue($open->covers('2099-01-01'));
        $this->assertNull($open->label());

        $closed = AssessmentCutoff::on('2026-11-15');

        $this->assertFalse($closed->isOpen());
        $this->assertTrue($closed->covers('2026-11-15 23:30:00'));
        $this->assertFalse($closed->covers('2026-11-16 00:00:01'));
        $this->assertSame('15/11/2026', $closed->label());
        $this->assertSame('2026-11-15', $closed->toIso());
    }

    #[Test]
    public function an_empty_date_is_the_same_as_no_cutoff_at_all(): void
    {
        $this->assertTrue(AssessmentCutoff::on(null)->isOpen());
        $this->assertTrue(AssessmentCutoff::on('')->isOpen());
    }

    // ------------------------------------------------------ 4. a página

    #[Test]
    public function the_page_reports_the_slice_of_time_it_is_showing(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica?ate=2026-10-20")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cutoff.date', '2026-10-20')
                ->where('cutoff.label', '20/10/2026')
                ->where('cutoff.is_open', false));
    }

    #[Test]
    public function without_a_date_the_page_says_it_is_showing_today(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('cutoff.is_open', true)->where('cutoff.date', null));
    }

    #[Test]
    public function a_malformed_date_is_refused_rather_than_guessed(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica?ate=15-11-2026")
            ->assertSessionHasErrors('ate');
    }
}
