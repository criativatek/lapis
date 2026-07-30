<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentCancellationTest extends TestCase
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

    protected function scenario(): Instrument
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

        return app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste',
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], [['code' => 'Q1', 'points_possible' => 100]]);
    }

    #[Test]
    public function cancelling_requires_a_reason_and_remembers_the_prior_status(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Teste anulado por engano.'])
                ->assertRedirect();

            $instrument->refresh();
            $this->assertSame('cancelled', $instrument->status->value);
            $this->assertSame('prepared', $instrument->status_before_cancellation);
            $this->assertSame('Teste anulado por engano.', $instrument->cancellation_reason);
            $this->assertNotNull($instrument->cancelled_at);
            $this->assertSame($this->user->id, $instrument->cancelled_by);
        });
    }

    #[Test]
    public function cancelling_without_a_reason_is_rejected(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/cancel", ['reason' => ''])
                ->assertSessionHasErrors('reason');

            $this->assertSame('prepared', $instrument->refresh()->status->value);
        });
    }

    #[Test]
    public function a_cancelled_instrument_cannot_be_edited_or_scored(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Anulado.']);

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}/edit")->assertForbidden();

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/scores", ['cells' => []])
                ->assertForbidden();
        });
    }

    #[Test]
    public function reverting_restores_the_exact_prior_status_and_clears_the_cancellation_fields(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Anulado.']);

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/revert-cancellation")
                ->assertRedirect();

            $instrument->refresh();
            $this->assertSame('prepared', $instrument->status->value);
            $this->assertNull($instrument->status_before_cancellation);
            $this->assertNull($instrument->cancelled_at);
            $this->assertNull($instrument->cancelled_by);
            $this->assertNull($instrument->cancellation_reason);
        });
    }

    #[Test]
    public function the_cancel_revert_cycle_can_repeat(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Primeira.']);
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/revert-cancellation");
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Segunda.']);

            $instrument->refresh();
            $this->assertSame('cancelled', $instrument->status->value);
            $this->assertSame('Segunda.', $instrument->cancellation_reason);
        });
    }

    #[Test]
    public function reverting_an_instrument_that_is_not_cancelled_is_rejected(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/revert-cancellation")
                ->assertSessionHasErrors('status');
        });
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_cannot_cancel_or_revert(): void
    {
        $instrument = $this->inTenant(fn () => $this->scenario());
        $organization = $this->organization;
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'x'])
            ->assertForbidden();
    }
}
