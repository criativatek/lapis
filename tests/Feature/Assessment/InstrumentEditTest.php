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

class InstrumentEditTest extends TestCase
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
     * @return array{class: SchoolClass, instrument: Instrument}
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

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste',
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], [
            ['code' => 'Q1', 'points_possible' => 60],
            ['code' => 'Q2', 'points_possible' => 40],
        ]);

        return ['class' => $class, 'instrument' => $instrument];
    }

    #[Test]
    public function the_edit_page_flags_which_items_already_have_scores(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'instrument' => $instrument] = $this->scenario();
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();
            $enrollment = Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $q2->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 30,
            ]);

            $this->actingAs($this->user)
                ->get("/instruments/{$instrument->ulid}/edit")
                ->assertInertia(fn ($page) => $page
                    ->where('instrument.items.0.has_scores', false)
                    ->where('instrument.items.1.has_scores', true));
        });
    }

    #[Test]
    public function updating_adds_a_question_and_keeps_the_others_identity(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument] = $this->scenario();
            $q1 = $instrument->items()->where('code', 'Q1')->firstOrFail();
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", [
                    'title' => $instrument->title,
                    'academic_period_id' => $instrument->academic_period_id,
                    'instrument_type_id' => $instrument->instrument_type_id,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'status' => $instrument->status->value,
                    'purpose' => $instrument->purpose,
                    'counts_toward_classification' => true,
                    'total_points' => 130,
                    'allow_bonus' => false,
                    'items' => [
                        ['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 60],
                        ['ulid' => $q2->ulid, 'code' => 'Q2', 'points_possible' => 40],
                        ['code' => 'Q3', 'points_possible' => 30],
                    ],
                ])
                ->assertRedirect(route('instruments.show', $instrument->ulid));

            $instrument->refresh();
            $this->assertSame(3, $instrument->items()->count());
            $this->assertSame($q1->id, $instrument->items()->where('code', 'Q1')->firstOrFail()->id);
            $this->assertSame($q2->id, $instrument->items()->where('code', 'Q2')->firstOrFail()->id);
        });
    }

    #[Test]
    public function updating_cannot_remove_a_scored_question(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'instrument' => $instrument] = $this->scenario();
            $q1 = $instrument->items()->where('code', 'Q1')->firstOrFail();
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();
            $enrollment = Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $q2->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 30,
            ]);

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", [
                    'title' => $instrument->title,
                    'academic_period_id' => $instrument->academic_period_id,
                    'instrument_type_id' => $instrument->instrument_type_id,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'status' => $instrument->status->value,
                    'purpose' => $instrument->purpose,
                    'counts_toward_classification' => true,
                    'total_points' => 60,
                    'allow_bonus' => false,
                    'items' => [
                        ['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 60],
                        // Q2 dropped — it has a score.
                    ],
                ])
                ->assertSessionHasErrors('items');

            $this->assertSame(2, $instrument->refresh()->items()->count());
        });
    }

    #[Test]
    public function updating_cannot_set_status_to_cancelled_through_the_generic_form(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument] = $this->scenario();
            $q1 = $instrument->items()->where('code', 'Q1')->firstOrFail();
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", [
                    'title' => $instrument->title,
                    'academic_period_id' => $instrument->academic_period_id,
                    'instrument_type_id' => $instrument->instrument_type_id,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'status' => 'cancelled',
                    'purpose' => $instrument->purpose,
                    'counts_toward_classification' => true,
                    'total_points' => 100,
                    'allow_bonus' => false,
                    'items' => [
                        ['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 60],
                        ['ulid' => $q2->ulid, 'code' => 'Q2', 'points_possible' => 40],
                    ],
                ])
                ->assertSessionHasErrors('status');

            $this->assertSame('prepared', $instrument->refresh()->status->value);
        });
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_cannot_edit_or_update(): void
    {
        ['instrument' => $instrument] = $this->inTenant(fn () => $this->scenario());
        $organization = $this->organization;
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->get("/instruments/{$instrument->ulid}/edit")
            ->assertForbidden();

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->put("/instruments/{$instrument->ulid}", ['items' => []])
            ->assertForbidden();
    }
}
