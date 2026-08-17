<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\InterimAssessment;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Support\Assessment\AssessmentCutoff;
use App\Support\Assessment\InterimAssessmentException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A photograph that keeps showing what was true when it was taken.
 *
 * The whole value of an interim assessment is that it does NOT move. A teacher
 * who corrects a score in January must still be able to open November's
 * assessment and see November — otherwise every comparison ever drawn from it,
 * and every grid ever exported from it, quietly changes meaning behind their
 * back.
 *
 * So most of what is defended here is absence of change: after the live class
 * has moved on, the snapshot has not.
 */
class InterimAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // The demo year runs 2026/2027, which has not started in real time.
        // Standing at the end of it makes every date these tests use a date
        // that has actually happened — which is what the guard against future
        // photographs requires, and which the guard's own test undoes.
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

    /**
     * The demo's first period runs 14/09/2026 → 29/01/2027 and its only
     * instrument was applied on 15/10/2026.
     */
    private function capture(string $date = '2026-11-15', int $sequence = 1, array $attributes = []): InterimAssessment
    {
        return $this->asTenant(fn (): InterimAssessment => app(CaptureInterimAssessment::class)->capture(
            $this->schoolClass(),
            $this->period($sequence),
            Carbon::parse($date),
            $this->teacher,
            $attributes,
        ));
    }

    // -------------------------------------------------- 1. tirar a fotografia

    #[Test]
    public function capturing_keeps_the_state_the_screen_was_showing(): void
    {
        $interim = $this->capture('2026-11-15');

        $live = $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for(
            $this->schoolClass(),
            $this->period(1),
            AssessmentCutoff::on('2026-11-15'),
        ));

        // The same numbers, not a second opinion about them.
        $this->assertSame($live['summary'], $interim->snapshot['summary']);
        $this->assertSame($live['distribution'], $interim->snapshot['distribution']);
        $this->assertSame($live['domain_statistics'], $interim->snapshot['domain_statistics']);
        $this->assertCount(count($live['students']), $interim->snapshot['students']);
    }

    #[Test]
    public function it_is_stamped_with_a_version_and_a_hash(): void
    {
        $interim = $this->capture();

        $this->assertSame(InterimAssessment::CURRENT_VERSION, $interim->snapshot_version);
        $this->assertSame(1, $interim->snapshot['version']);
        $this->assertTrue($interim->isIntact());
        $this->assertSame(64, strlen($interim->snapshot_hash));
    }

    #[Test]
    public function the_name_is_suggested_but_the_teachers_own_wins(): void
    {
        $suggested = $this->capture('2026-11-15');
        $this->assertStringContainsString('Avaliação intercalar', $suggested->name);
        $this->assertStringContainsString('1.º Semestre', $suggested->name);

        $chosen = $this->capture('2026-11-16', attributes: ['name' => 'Antes das férias']);
        $this->assertSame('Antes das férias', $chosen->name);
    }

    // ------------------------------------------------------ 2. imutabilidade

    #[Test]
    public function correcting_a_score_afterwards_does_not_move_the_photograph(): void
    {
        $interim = $this->capture('2026-11-15');
        $before = $interim->snapshot;

        // January: the teacher corrects a mark.
        $this->asTenant(function (): void {
            StudentItemScore::query()
                ->where('result_state', 'assessed')
                ->orderBy('id')
                ->first()
                ?->update(['points_earned' => 1]);
        });

        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));

        $this->assertSame($before, $again->snapshot, 'a fotografia não pode mexer-se');
        $this->assertTrue($again->isIntact());

        // And the live reading HAS moved — otherwise this proves nothing.
        $live = $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for(
            $this->schoolClass(),
            $this->period(1),
            AssessmentCutoff::on('2026-11-15'),
        ));

        $this->assertNotSame($before['summary']['class_average'], $live['summary']['class_average']);
    }

    #[Test]
    public function a_snapshot_refuses_to_be_written_over(): void
    {
        $interim = $this->capture();

        // Not merely discouraged: correcting one means creating a new one
        // beside it, because everything already drawn from this one depends on
        // it not moving.
        $this->expectException(LogicException::class);
        $interim->update(['name' => 'Outro nome']);
    }

    #[Test]
    public function a_renamed_domain_does_not_destroy_the_history(): void
    {
        $interim = $this->capture('2026-11-15');
        $labels = array_column($interim->snapshot['domains'], 'label_snapshot');

        $this->assertContains('Leitura', $labels);

        $this->asTenant(fn () => Domain::where('code', 'LEITURA')->firstOrFail()->update(['name' => 'Compreensão Leitora']));

        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));

        // The words that were on screen travel with the photograph; the ids
        // travel too, so a later export still matches something real (§15).
        $this->assertContains('Leitura', array_column($again->snapshot['domains'], 'label_snapshot'));
        $this->assertNotContains(null, array_column($again->snapshot['domains'], 'domain_id'));
    }

    #[Test]
    public function a_reconfigured_scale_does_not_destroy_the_history(): void
    {
        $interim = $this->capture('2026-11-15');
        $bands = $interim->snapshot['scale']['bands'];

        $this->assertContains('Bom', array_column($bands, 'label_snapshot'));
        $this->assertContains('B', array_column($bands, 'inovar_code_snapshot'));

        $this->asTenant(function (): void {
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()
                ->levels()->where('code', '4')
                ->update(['label' => 'Desempenho Bom', 'inovar_code' => 'XX']);
        });

        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));
        $stored = $again->snapshot['scale']['bands'];

        // Both the words AND the INOVAR code are historical: a grid produced
        // from this photograph in January must carry November's code (§34).
        $this->assertContains('Bom', array_column($stored, 'label_snapshot'));
        $this->assertContains('B', array_column($stored, 'inovar_code_snapshot'));
        $this->assertNotContains('XX', array_column($stored, 'inovar_code_snapshot'));
    }

    // -------------------------------------------------- 3. o que fica guardado

    #[Test]
    public function each_student_keeps_their_reading_and_the_reason_behind_any_warning(): void
    {
        $interim = $this->capture('2026-11-15');
        $students = $interim->snapshot['students'];

        $this->assertNotEmpty($students);

        foreach ($students as $student) {
            $this->assertArrayHasKey('enrollment_id', $student);
            $this->assertArrayHasKey('name_snapshot', $student);
            $this->assertArrayHasKey('weighted_average', $student);
            $this->assertArrayHasKey('accumulated_average', $student);
            $this->assertArrayHasKey('band', $student);
            $this->assertArrayHasKey('self_assessment', $student);
            $this->assertArrayHasKey('classification', $student);

            foreach ($student['domains'] as $cell) {
                $this->assertArrayHasKey('label_snapshot', $cell);
                $this->assertArrayHasKey('mention', $cell);
                $this->assertArrayHasKey('coverage_elements', $cell);
            }
        }
    }

    #[Test]
    public function a_band_is_kept_by_its_identity_and_not_only_by_its_words(): void
    {
        $interim = $this->capture('2026-11-15');

        $withBand = array_values(array_filter(
            $interim->snapshot['students'],
            fn (array $student): bool => $student['band'] !== null,
        ));

        $this->assertNotEmpty($withBand);

        foreach ($withBand as $student) {
            // «Bom» alone could never be mapped back to anything (§16).
            $this->assertNotNull($student['band']['scale_level_id']);
            $this->assertNotNull($student['band']['code_snapshot']);
            $this->assertNotNull($student['band']['label_snapshot']);
        }
    }

    // ------------------------------------------------------------ 4. a data

    #[Test]
    public function the_date_must_fall_inside_the_period_it_belongs_to(): void
    {
        // The first semester runs 14/09/2026 → 29/01/2027.
        $this->expectException(InterimAssessmentException::class);
        $this->capture('2026-09-01');
    }

    #[Test]
    public function a_date_after_the_period_ends_is_refused(): void
    {
        $this->expectException(InterimAssessmentException::class);
        $this->capture('2027-02-15', sequence: 1);
    }

    #[Test]
    public function the_boundaries_of_the_period_are_read_from_its_own_dates(): void
    {
        $period = $this->period(1);

        // Its own starts_on/ends_on, never inferred from the words «1.º
        // Semestre» — a school that names its periods differently is validated
        // exactly the same way (§6).
        $first = $this->capture($period->starts_on->toDateString());
        $this->assertNotNull($first->id);

        $this->assertSame(
            $period->starts_on->toDateString(),
            $first->reference_date->toDateString(),
        );
    }

    #[Test]
    public function a_date_that_has_not_arrived_yet_is_refused(): void
    {
        // Mid-November, looking at the end of January. A photograph of a moment
        // that has not happened would be a photograph of today wearing the
        // wrong date — which is worse than none at all.
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));

        $this->expectException(InterimAssessmentException::class);
        $this->expectExceptionMessage('data futura');

        $this->capture('2027-01-20');
    }

    #[Test]
    public function a_period_from_another_year_is_refused(): void
    {
        $stranger = User::factory()->create();

        $this->expectException(InterimAssessmentException::class);

        $this->asTenant(function () use ($stranger): void {
            $otherYear = AcademicYear::create([
                'label' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-30',
                'status' => 'active', 'country_code' => 'PT',
            ]);
            $foreign = $otherYear->periods()->create([
                'label' => 'Único', 'kind' => 'semester', 'sequence' => 1,
                'starts_on' => '2030-09-01', 'ends_on' => '2031-06-30', 'status' => 'open',
            ]);

            app(CaptureInterimAssessment::class)->capture(
                $this->schoolClass(),
                $foreign,
                Carbon::parse('2030-10-01'),
                $stranger,
            );
        });
    }

    // ------------------------------------------------ 5. várias intercalares

    #[Test]
    public function a_class_can_have_none_one_or_several(): void
    {
        $this->assertSame(0, $this->asTenant(fn (): int => InterimAssessment::query()->count()));

        $this->capture('2026-10-31');
        $this->capture('2026-12-12');
        $this->capture('2027-03-10', sequence: 2);

        $all = $this->asTenant(fn () => InterimAssessment::query()->orderBy('reference_date')->get());

        // Nothing anywhere says «one per period», and nothing hardcodes «the
        // middle of the semester» (§20).
        $this->assertCount(3, $all);
        $this->assertSame(
            ['2026-10-31', '2026-12-12', '2027-03-10'],
            $all->map(fn ($row): string => $row->reference_date->toDateString())->all(),
        );

        $firstPeriod = $this->period(1)->id;
        $this->assertSame(2, $all->where('academic_period_id', $firstPeriod)->count());
    }

    #[Test]
    public function two_interims_on_different_dates_hold_different_states(): void
    {
        // Before the only instrument of the period, and after it.
        $early = $this->capture('2026-10-01');
        $late = $this->capture('2026-11-15');

        $this->assertNull($early->snapshot['summary']['class_average']);
        $this->assertNotNull($late->snapshot['summary']['class_average']);
    }

    // ------------------------------------------------------ 6. isolamento

    #[Test]
    public function an_interim_belongs_to_the_organization_that_took_it(): void
    {
        $interim = $this->capture();

        $this->assertSame(
            $this->teacher->personalOrganization()->id,
            $interim->organization_id,
        );

        // Another organization sees nothing at all.
        $stranger = User::factory()->create();

        $count = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn (): int => InterimAssessment::query()->count(),
        );

        $this->assertSame(0, $count);
    }
}
