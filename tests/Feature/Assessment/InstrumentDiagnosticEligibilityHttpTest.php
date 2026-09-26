<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R2 (a diagnostic instrument never counts) exercised through the real HTTP
 * routes — store, update, and update on an instrument that already has
 * scores — rather than through InstrumentBuilder directly. Scenarios 8, 11
 * and 12 of the round-2 eligibility work (JANELA AG).
 */
class InstrumentDiagnosticEligibilityHttpTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->user->personalOrganization();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    /**
     * @return array{class: SchoolClass, period: AcademicPeriod}
     */
    protected function scenario(): array
    {
        $org = $this->organization;
        $year = AcademicYear::factory()->recycle($org)->create();
        $period = AcademicPeriod::factory()->recycle($org)->for($year)->create();
        $subject = Subject::factory()->recycle($org)->create();
        $class = SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        return ['class' => $class, 'period' => $period];
    }

    /**
     * An instrument with two questions, one of them scored — the shape §11/§12
     * need: an update that must leave the score untouched.
     *
     * @return array{class: SchoolClass, instrument: Instrument, enrollment: Enrollment}
     */
    protected function scoredScenario(): array
    {
        return $this->inTenant(function (): array {
            ['class' => $class, 'period' => $period] = $this->scenario();

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Ficha regular',
                'applied_on' => '2026-10-15',
                'status' => 'in_correction',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 100,
            ], [
                ['code' => 'Q1', 'points_possible' => 60],
                ['code' => 'Q2', 'points_possible' => 40],
            ]);

            $enrollment = Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);
            $q1 = $instrument->items()->where('code', 'Q1')->firstOrFail();
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $q1->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 50,
            ]);

            return ['class' => $class, 'instrument' => $instrument, 'enrollment' => $enrollment];
        });
    }

    // --------------------------------------------------- 8. store/update via HTTP

    #[Test]
    public function post_store_with_diagnostic_and_counts_true_persists_false(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->scenario();

            $this->actingAs($this->user)
                ->post("/classes/{$class->ulid}/instruments", [
                    'title' => 'Diagnóstico via HTTP',
                    'academic_period_id' => $period->id,
                    'instrument_type_id' => 0,
                    'custom_instrument_type_name' => 'Ficha de diagnóstico',
                    'applied_on' => '2026-10-15',
                    'submission_intent' => 'prepare',
                    'purpose' => 'diagnostic',
                    'counts_toward_classification' => true,
                    'total_points' => 100,
                    'allow_bonus' => false,
                    'items' => [['code' => 'Q1', 'points_possible' => 100]],
                ])
                ->assertRedirect();

            $instrument = Instrument::where('title', 'Diagnóstico via HTTP')->firstOrFail();
            $this->assertSame('diagnostic', $instrument->purpose);
            $this->assertFalse($instrument->counts_toward_classification);
        });
    }

    #[Test]
    public function put_update_with_diagnostic_and_counts_true_persists_false(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->scenario();

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Ficha a editar',
                'applied_on' => '2026-10-15',
                'status' => 'prepared',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 100,
            ], [['code' => 'Q1', 'points_possible' => 100]]);
            $q1 = $instrument->items()->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", [
                    'title' => $instrument->title,
                    'academic_period_id' => $instrument->academic_period_id,
                    'instrument_type_id' => $instrument->instrument_type_id,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'submission_intent' => 'prepare',
                    'purpose' => 'diagnostic',
                    'counts_toward_classification' => true,
                    'total_points' => 100,
                    'allow_bonus' => false,
                    'items' => [['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 100]],
                ])
                ->assertRedirect(route('instruments.show', $instrument->ulid));

            $fresh = $instrument->fresh();
            $this->assertSame('diagnostic', $fresh->purpose);
            $this->assertFalse($fresh->counts_toward_classification);
        });
    }

    // --------------------------------------------------- 11. regular -> diagnostic with scores

    #[Test]
    public function updating_a_scored_instrument_to_diagnostic_forces_false_and_leaves_items_and_scores_untouched(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollment' => $enrollment] = $this->scoredScenario();
            $q1 = $instrument->items()->where('code', 'Q1')->firstOrFail();
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();
            $scoreBefore = StudentItemScore::where('instrument_id', $instrument->id)
                ->where('instrument_item_id', $q1->id)
                ->where('enrollment_id', $enrollment->id)
                ->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", [
                    'title' => $instrument->title,
                    'academic_period_id' => $instrument->academic_period_id,
                    'instrument_type_id' => $instrument->instrument_type_id,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'submission_intent' => 'prepare',
                    'purpose' => 'diagnostic',
                    'counts_toward_classification' => true,
                    'total_points' => 100,
                    'allow_bonus' => false,
                    'items' => [
                        ['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 60],
                        ['ulid' => $q2->ulid, 'code' => 'Q2', 'points_possible' => 40],
                    ],
                ])
                ->assertRedirect(route('instruments.edit', $instrument->ulid));

            $fresh = $instrument->fresh();
            $this->assertSame('diagnostic', $fresh->purpose);
            $this->assertFalse($fresh->counts_toward_classification);
            $this->assertSame(2, $fresh->items()->count());
            $this->assertSame($q1->id, $fresh->items()->where('code', 'Q1')->firstOrFail()->id);
            $this->assertSame($q2->id, $fresh->items()->where('code', 'Q2')->firstOrFail()->id);

            $scoreAfter = StudentItemScore::where('instrument_id', $instrument->id)
                ->where('instrument_item_id', $q1->id)
                ->where('enrollment_id', $enrollment->id)
                ->firstOrFail();
            $this->assertSame($scoreBefore->id, $scoreAfter->id);
            $this->assertSame($scoreBefore->points_earned, $scoreAfter->points_earned);
            $this->assertSame($scoreBefore->result_state, $scoreAfter->result_state);
        });
    }

    // --------------------------------------------------- 12. diagnostic -> formative, no auto-reactivation

    #[Test]
    public function updating_a_diagnostic_to_formative_sending_false_stays_false(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->scenario();

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Diagnóstico a converter',
                'applied_on' => '2026-10-15',
                'status' => 'prepared',
                'counts_toward_classification' => false,
                'purpose' => 'diagnostic',
                'total_points' => 100,
            ], [['code' => 'Q1', 'points_possible' => 100]]);
            $q1 = $instrument->items()->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", [
                    'title' => $instrument->title,
                    'academic_period_id' => $instrument->academic_period_id,
                    'instrument_type_id' => $instrument->instrument_type_id,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'submission_intent' => 'prepare',
                    'purpose' => 'formative',
                    'counts_toward_classification' => false,
                    'total_points' => 100,
                    'allow_bonus' => false,
                    'items' => [['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 100]],
                ])
                ->assertRedirect(route('instruments.show', $instrument->ulid));

            $fresh = $instrument->fresh();
            $this->assertSame('formative', $fresh->purpose);
            $this->assertFalse($fresh->counts_toward_classification, 'Leaving diagnostic must never auto-reactivate the flag.');
        });
    }

    #[Test]
    public function updating_a_diagnostic_to_formative_sending_true_is_a_conscious_choice_and_is_respected(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->scenario();

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Diagnóstico a converter e ativar',
                'applied_on' => '2026-10-15',
                'status' => 'prepared',
                'counts_toward_classification' => false,
                'purpose' => 'diagnostic',
                'total_points' => 100,
            ], [['code' => 'Q1', 'points_possible' => 100]]);
            $q1 = $instrument->items()->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", [
                    'title' => $instrument->title,
                    'academic_period_id' => $instrument->academic_period_id,
                    'instrument_type_id' => $instrument->instrument_type_id,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'submission_intent' => 'prepare',
                    'purpose' => 'formative',
                    'counts_toward_classification' => true,
                    'total_points' => 100,
                    'allow_bonus' => false,
                    'items' => [['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 100]],
                ])
                ->assertRedirect(route('instruments.show', $instrument->ulid));

            $fresh = $instrument->fresh();
            $this->assertSame('formative', $fresh->purpose);
            $this->assertTrue($fresh->counts_toward_classification, 'A deliberate tick on a non-diagnostic purpose must be respected.');
        });
    }
}
