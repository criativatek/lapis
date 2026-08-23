<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Instrument;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The HTTP boundary for the diagnostic-purpose default (§Passo 4): confirms
 * InstrumentRequest's conditional rule genuinely lets a create request omit
 * counts_toward_classification (rather than always requiring it), while an
 * update request still requires it explicit either way.
 */
class InstrumentDiagnosticDefaultRequestTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(AcademicPeriod $period, array $overrides = [], bool $omitCounts = false): array
    {
        $payload = array_merge([
            'title' => 'Ficha diagnóstica',
            'academic_period_id' => $period->id,
            'instrument_type_id' => 0,
            'custom_instrument_type_name' => 'Ficha de diagnóstico',
            'applied_on' => '2026-10-15',
            'submission_intent' => 'prepare',
            'purpose' => 'diagnostic',
            'counts_toward_classification' => true,
            'total_points' => 100,
            'allow_bonus' => false,
            'items' => [
                ['code' => 'Q1', 'points_possible' => 100],
            ],
        ], $overrides);

        if ($omitCounts) {
            unset($payload['counts_toward_classification']);
        }

        return $payload;
    }

    #[Test]
    public function creating_without_the_field_is_accepted_and_the_instrument_defaults_to_not_counting(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->scenario();

            $this->actingAs($this->user)
                ->post("/classes/{$class->ulid}/instruments", $this->payload($period, [], omitCounts: true))
                ->assertRedirect();

            $instrument = Instrument::where('title', 'Ficha diagnóstica')->firstOrFail();
            $this->assertFalse($instrument->counts_toward_classification);
        });
    }

    #[Test]
    public function creating_with_the_field_explicitly_false_is_respected(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->scenario();

            $this->actingAs($this->user)
                ->post("/classes/{$class->ulid}/instruments", $this->payload($period, ['counts_toward_classification' => false]))
                ->assertRedirect();

            $instrument = Instrument::where('title', 'Ficha diagnóstica')->firstOrFail();
            $this->assertFalse($instrument->counts_toward_classification);
        });
    }

    #[Test]
    public function creating_with_the_field_explicitly_true_is_respected(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->scenario();

            $this->actingAs($this->user)
                ->post("/classes/{$class->ulid}/instruments", $this->payload($period, ['counts_toward_classification' => true]))
                ->assertRedirect();

            $instrument = Instrument::where('title', 'Ficha diagnóstica')->firstOrFail();
            $this->assertTrue($instrument->counts_toward_classification);
        });
    }

    #[Test]
    public function updating_without_the_field_is_still_rejected(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->scenario();

            $this->actingAs($this->user)
                ->post("/classes/{$class->ulid}/instruments", $this->payload($period))
                ->assertRedirect();
            $instrument = Instrument::where('title', 'Ficha diagnóstica')->firstOrFail();

            $updatePayload = $this->payload($period, [], omitCounts: true);

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", $updatePayload)
                ->assertSessionHasErrors('counts_toward_classification');
        });
    }
}
