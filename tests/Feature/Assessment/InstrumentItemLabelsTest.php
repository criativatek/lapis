<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
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
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentItemLabelsTest extends TestCase
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
        ], [
            ['code' => 'Q1', 'points_possible' => 50],
            ['code' => 'Q2', 'points_possible' => 50],
        ]);
    }

    #[Test]
    public function it_saves_trimmed_labels_and_turns_blank_ones_into_null(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            [$q1, $q2] = $instrument->items()->orderBy('id')->get()->all();
            $q2->update(['label' => 'Antigo']);

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/labels", [
                    'labels' => [$q1->id => '  Texto narrativo  ', $q2->id => '   '],
                ])
                ->assertRedirect();

            $this->assertSame('Texto narrativo', $q1->fresh()->label);
            $this->assertNull($q2->fresh()->label);
            $this->assertSame(1, AuditEvent::where('event', 'instrument.item_labels_updated')->count());
        });
    }

    #[Test]
    public function an_unchanged_label_is_not_audited(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $q1 = $instrument->items()->orderBy('id')->firstOrFail();
            $q1->update(['label' => 'Inferências']);

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/labels", ['labels' => [$q1->id => 'Inferências']])
                ->assertRedirect();

            $this->assertSame(0, AuditEvent::where('event', 'instrument.item_labels_updated')->count());
        });
    }

    #[Test]
    public function an_item_of_another_instrument_is_refused(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $foreign = $this->scenario()->items()->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/labels", ['labels' => [$foreign->id => 'Intruso']])
                ->assertStatus(422);

            $this->assertNull($foreign->fresh()->label);
        });
    }

    #[Test]
    public function an_overlong_label_is_a_validation_error(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $q1 = $instrument->items()->orderBy('id')->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/labels", ['labels' => [$q1->id => str_repeat('a', 501)]])
                ->assertSessionHasErrors();
        });
    }

    #[Test]
    public function it_works_on_a_completed_correction_without_touching_the_scores(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $instrument->forceFill(['status' => 'completed'])->save();
            $q1 = $instrument->items()->orderBy('id')->firstOrFail();
            $scoresBefore = StudentItemScore::withoutGlobalScopes()->count();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/labels", ['labels' => [$q1->id => 'Texto narrativo']])
                ->assertRedirect();

            $this->assertSame('Texto narrativo', $q1->fresh()->label);
            $this->assertSame($scoresBefore, StudentItemScore::withoutGlobalScopes()->count());
            $this->assertSame('completed', $instrument->fresh()->status->value);
        });
    }

    #[Test]
    public function a_cancelled_instrument_refuses_it(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $q1 = $instrument->items()->orderBy('id')->firstOrFail();
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Anulado.']);

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/labels", ['labels' => [$q1->id => 'x']])
                ->assertForbidden();

            $this->assertNull($q1->fresh()->label);
        });
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_is_forbidden(): void
    {
        $instrument = $this->inTenant(fn () => $this->scenario());
        $q1 = $this->inTenant(fn () => $instrument->items()->orderBy('id')->firstOrFail());
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $this->organization->id])
            ->actingAs($colleague)
            ->put("/instruments/{$instrument->ulid}/items/labels", ['labels' => [$q1->id => 'x']])
            ->assertForbidden();

        $this->assertNull($this->inTenant(fn () => $q1->fresh()->label));
    }

    #[Test]
    public function the_grid_returns_the_saved_label(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $q1 = $instrument->items()->orderBy('id')->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/labels", ['labels' => [$q1->id => 'Inferências']]);

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('items.0.label', 'Inferências')
                    ->where('items.1.label', null));
        });
    }
}
