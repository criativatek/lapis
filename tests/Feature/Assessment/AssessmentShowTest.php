<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssessmentShowTest extends TestCase
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
    protected function classScenario(): array
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

    #[Test]
    public function an_authorized_teacher_can_open_the_assessment_show_page(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();
            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
            ]);

            $this->actingAs($this->user)
                ->get("/assessments/{$instrument->ulid}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Show')
                    ->where('instrument.ulid', $instrument->ulid)
                    ->where('instrument.class_label', $class->label)
                    ->where('instrument.period', $period->label));
        });
    }

    #[Test]
    public function a_colleague_in_the_same_organization_who_does_not_teach_the_class_is_forbidden(): void
    {
        $ulid = $this->inTenant(function (): string {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            return Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
            ])->ulid;
        });

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $this->organization->id])
            ->actingAs($colleague)
            ->get("/assessments/{$ulid}")
            ->assertForbidden();
    }

    #[Test]
    public function a_stranger_in_a_different_organization_cannot_open_it(): void
    {
        $ulid = $this->inTenant(function (): string {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            return Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
            ])->ulid;
        });

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/assessments/{$ulid}")->assertNotFound();
    }

    #[Test]
    public function it_derives_one_state_per_applicable_student_and_keeps_non_applicable_ones_separate(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'applied_on' => '2026-06-01',
            ]);
            $itemOne = InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'points_possible' => 10,
            ]);
            $itemTwo = InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'points_possible' => 10,
            ]);

            $corrected = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id, 'enrolled_on' => '2026-01-01', 'class_number' => 1,
            ]);
            $partial = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id, 'enrolled_on' => '2026-01-01', 'class_number' => 2,
            ]);
            $underReview = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id, 'enrolled_on' => '2026-01-01', 'class_number' => 3,
            ]);
            $absent = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id, 'enrolled_on' => '2026-01-01', 'class_number' => 4,
            ]);
            $absentJustified = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id, 'enrolled_on' => '2026-01-01', 'class_number' => 5,
            ]);
            // Enrolled after the instrument's date — not applicable (§11.4).
            $nonApplicable = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id, 'enrolled_on' => '2026-07-01', 'class_number' => 6,
            ]);

            $score = fn (Enrollment $enrollment, InstrumentItem $item, string $state, ?float $points = null) => StudentItemScore::factory()
                ->recycle($this->organization)
                ->create([
                    'instrument_id' => $instrument->id,
                    'instrument_item_id' => $item->id,
                    'enrollment_id' => $enrollment->id,
                    'result_state' => $state,
                    'points_earned' => $points,
                ]);

            // Fully resolved with real marks — Corrigida.
            $score($corrected, $itemOne, 'assessed', 8);
            $score($corrected, $itemTwo, 'assessed', 9);

            // One item graded, the other left untouched — Por corrigir.
            $score($partial, $itemOne, 'assessed', 7);

            // One item still under review — Em revisão, even though the other is assessed.
            $score($underReview, $itemOne, 'under_review');
            $score($underReview, $itemTwo, 'assessed', 6);

            // Uniformly absent — Faltou, no detail.
            $score($absent, $itemOne, 'absent');
            $score($absent, $itemTwo, 'absent');

            // Uniformly absent_justified — Faltou, with a "justificada" detail.
            $score($absentJustified, $itemOne, 'absent_justified');
            $score($absentJustified, $itemTwo, 'absent_justified');

            $this->actingAs($this->user)
                ->get("/assessments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Show')
                    ->where('summary.applicable', 5)
                    ->where('summary.completed', 1)
                    ->where('summary.pending', 1)
                    ->where('summary.absent', 2)
                    ->where('summary.under_review', 1)
                    ->has('students', 5)
                    ->where('students.0.state_key', 'assessed')
                    ->where('students.0.state_label', 'Corrigida')
                    ->where('students.1.state_key', 'pending')
                    ->where('students.1.state_label', 'Por corrigir')
                    ->where('students.2.state_key', 'under_review')
                    ->where('students.2.state_label', 'Em revisão')
                    ->where('students.3.state_key', 'absent')
                    ->where('students.3.state_label', 'Faltou')
                    ->where('students.3.state_detail', null)
                    ->where('students.4.state_key', 'absent')
                    ->where('students.4.state_detail', 'justificada')
                    ->has('nonApplicableStudents', 1)
                    ->where('nonApplicableStudents.0.enrollment_id', $nonApplicable->id));
        });
    }

    #[Test]
    public function a_mix_of_special_states_with_no_assessed_item_is_labelled_situacao_especial_and_still_counts_as_completed(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'applied_on' => '2026-06-01',
            ]);
            $itemOne = InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'points_possible' => 10,
            ]);
            $itemTwo = InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'points_possible' => 10,
            ]);

            $student = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id, 'enrolled_on' => '2026-01-01',
            ]);

            // Every item resolved, but with no assessed mark anywhere — a
            // genuine mix of special states (dispensado + anulado).
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'instrument_item_id' => $itemOne->id,
                'enrollment_id' => $student->id, 'result_state' => 'exempt', 'points_earned' => null,
            ]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'instrument_item_id' => $itemTwo->id,
                'enrollment_id' => $student->id, 'result_state' => 'annulled', 'points_earned' => null,
            ]);

            $this->actingAs($this->user)
                ->get("/assessments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Show')
                    // Neither pending, under_review nor absent — it must fold
                    // into "completed", not vanish or double-count elsewhere.
                    ->where('summary.applicable', 1)
                    ->where('summary.completed', 1)
                    ->where('summary.pending', 0)
                    ->where('summary.absent', 0)
                    ->where('summary.under_review', 0)
                    ->where('students.0.state_key', 'special')
                    ->where('students.0.state_label', 'Situação especial')
                    ->where('students.0.state_detail', 'Dispensado, Anulado'));
        });
    }

    #[Test]
    public function an_instrument_with_no_items_shows_every_applicable_student_as_por_corrigir_not_corrigida(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id, 'enrolled_on' => '2026-01-01',
            ]);
            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'applied_on' => '2026-06-01',
            ]);

            $this->actingAs($this->user)
                ->get("/assessments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Show')
                    ->where('summary.applicable', 1)
                    ->where('summary.completed', 0)
                    ->where('summary.pending', 1)
                    ->where('students.0.state_key', 'pending')
                    ->where('students.0.state_label', 'Por corrigir'));
        });
    }
}
