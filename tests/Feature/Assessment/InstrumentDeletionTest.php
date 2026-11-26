<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentDeletionTest extends TestCase
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

    protected function scenario(string $status = 'prepared'): Instrument
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
            'status' => $status,
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], [['code' => 'Q1', 'points_possible' => 100]]);
    }

    protected function recordResult(Instrument $instrument, string $state = 'assessed'): void
    {
        $org = $this->organization;
        $student = Student::factory()->recycle($org)->create();
        StudentIdentity::create(['student_id' => $student->getKey(), 'organization_id' => $org->getKey(), 'display_name' => 'Aluno']);
        $enrollment = Enrollment::factory()->recycle($org)->create([
            'class_id' => $instrument->class_id, 'student_id' => $student->getKey(), 'class_number' => 1, 'enrolled_on' => '2026-09-14',
        ]);

        StudentItemScore::forceCreate([
            'organization_id' => $org->getKey(),
            'instrument_id' => $instrument->getKey(),
            'instrument_item_id' => $instrument->items()->firstOrFail()->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'result_state' => $state,
            'points_earned' => $state === 'assessed' ? 50 : null,
        ]);
    }

    #[Test]
    public function the_owner_deletes_a_draft_without_results(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario('draft');

            $this->actingAs($this->user)
                ->delete("/instruments/{$instrument->ulid}")
                ->assertRedirect(route('instruments.index'))
                ->assertSessionHasNoErrors();

            $this->assertSoftDeleted('instruments', ['id' => $instrument->id]);
            $this->assertTrue(AuditEvent::where('event', 'instrument.deleted')->exists());
            $this->assertNull(Instrument::where('ulid', $instrument->ulid)->first());

            $this->actingAs($this->user)
                ->get(route('instruments.index'))
                ->assertOk()
                ->assertDontSee($instrument->ulid);
        });
    }

    #[Test]
    public function an_instrument_with_a_recorded_result_cannot_be_deleted(): void
    {
        foreach (['assessed', 'absent'] as $state) {
            $this->inTenant(function () use ($state): void {
                $instrument = $this->scenario();
                $this->recordResult($instrument, $state);
                $before = AuditEvent::where('event', 'instrument.deleted')->count();

                $this->actingAs($this->user)
                    ->from("/instruments/{$instrument->ulid}")
                    ->delete("/instruments/{$instrument->ulid}")
                    ->assertRedirect("/instruments/{$instrument->ulid}");

                $this->assertNotSoftDeleted('instruments', ['id' => $instrument->id]);
                $this->assertSame($before, AuditEvent::where('event', 'instrument.deleted')->count());
            });
        }
    }

    #[Test]
    public function another_teacher_cannot_delete(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $stranger = User::factory()->create();
            $this->organization->members()->attach($stranger, ['joined_at' => now()]);

            $this->actingAs($stranger)->withSession(['organization_id' => $this->organization->id])
                ->delete("/instruments/{$instrument->ulid}")
                ->assertForbidden();

            $this->assertNotSoftDeleted('instruments', ['id' => $instrument->id]);
        });
    }

    #[Test]
    public function can_delete_follows_the_recorded_results_on_the_grid_and_edit_pages(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn (AssertableInertia $page) => $page->component('instruments/Grid')->where('can_delete', true));
            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}/edit")
                ->assertInertia(fn (AssertableInertia $page) => $page->component('instruments/Edit')->where('can_delete', true));

            $this->recordResult($instrument);

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn (AssertableInertia $page) => $page->where('can_delete', false));
            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}/edit")
                ->assertInertia(fn (AssertableInertia $page) => $page->where('can_delete', false));
        });
    }
}
