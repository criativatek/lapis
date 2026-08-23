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

class AssessmentControllerTest extends TestCase
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
    public function index_lists_only_instruments_from_classes_the_teacher_teaches(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            $mine = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'title' => 'Teste do meu aluno',
            ]);

            // A class the acting user does not teach — must not leak into the list.
            $otherClass = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => $class->label.' (outra)',
            ]);
            Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $otherClass->id,
                'academic_period_id' => $period->id,
                'title' => 'Teste de outro professor',
            ]);

            $this->actingAs($this->user)
                ->get('/assessments')
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Index')
                    ->has('assessments', 1)
                    ->where('assessments.0.ulid', $mine->ulid)
                    ->where('assessments.0.class_label', $class->label)
                    ->where('assessments.0.purpose_label', 'Sumativa'));
        });
    }

    #[Test]
    public function the_class_picker_lists_only_classes_the_teacher_teaches(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class] = $this->classScenario();

            $otherClass = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => $class->label.' (outra)',
            ]);
            // Never attached as a teacher — must not appear in the picker.

            $this->actingAs($this->user)
                ->get('/assessments')
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Index')
                    ->has('classOptions', 1)
                    ->where('classOptions.0.ulid', $class->ulid)
                    ->where('classOptions.0.label', $class->label));
        });
    }

    #[Test]
    public function a_prepared_instrument_is_labelled_by_whether_its_date_has_arrived(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            $future = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'status' => 'prepared',
                'applied_on' => now()->addMonth()->toDateString(),
            ]);
            $today = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'status' => 'prepared',
                'applied_on' => now()->toDateString(),
            ]);
            $past = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'status' => 'prepared',
                'applied_on' => now()->subMonth()->toDateString(),
            ]);

            // orderByDesc('applied_on'): the future instrument's later date sorts first.
            $this->actingAs($this->user)
                ->get('/assessments')
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Index')
                    ->has('assessments', 3)
                    ->where('assessments.0.ulid', $future->ulid)
                    ->where('assessments.0.state_label', 'Agendada')
                    ->where('assessments.1.ulid', $today->ulid)
                    ->where('assessments.1.state_label', 'Por iniciar')
                    ->where('assessments.2.ulid', $past->ulid)
                    ->where('assessments.2.state_label', 'Por iniciar'));
        });
    }

    #[Test]
    public function every_other_status_maps_to_its_own_avaliacoes_label_regardless_of_instrumentstatus_wording(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            $expectedLabels = [
                'draft' => 'Em preparação',
                'in_correction' => 'Em correção',
                'completed' => 'Concluída',
                'published' => 'Concluída',
                'cancelled' => 'Anulada',
                'archived' => 'Arquivada',
            ];

            foreach ($expectedLabels as $status => $expectedLabel) {
                Instrument::factory()->recycle($this->organization)->create([
                    'class_id' => $class->id,
                    'academic_period_id' => $period->id,
                    'status' => $status,
                ]);

                $this->actingAs($this->user)
                    ->get("/assessments?status={$status}")
                    ->assertInertia(fn ($page) => $page
                        ->component('assessments/Index')
                        ->has('assessments', 1)
                        ->where('assessments.0.status', $status)
                        ->where('assessments.0.state_label', $expectedLabel));
            }
        });
    }

    #[Test]
    public function progress_counts_students_not_cells_and_a_partially_corrected_student_does_not_count_as_completed(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'applied_on' => '2026-06-01',
            ]);
            // Two items per student — if progress counted cells instead of
            // students, these assertions below would see 6 cells, not 3 students.
            $itemOne = InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'points_possible' => 10,
            ]);
            $itemTwo = InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'points_possible' => 10,
            ]);

            $completedStudent = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'enrolled_on' => '2026-01-01',
            ]);
            $partialStudent = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'enrolled_on' => '2026-01-01',
            ]);
            $underReviewStudent = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'enrolled_on' => '2026-01-01',
            ]);
            // Enrolled after the instrument's date — not applicable, must not
            // enter the denominator, and never counts as zero (§11.4).
            Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'enrolled_on' => '2026-07-01',
            ]);

            // Every item resolved — this student counts as completed.
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'instrument_item_id' => $itemOne->id,
                'enrollment_id' => $completedStudent->id, 'result_state' => 'assessed', 'points_earned' => 8,
            ]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'instrument_item_id' => $itemTwo->id,
                'enrollment_id' => $completedStudent->id, 'result_state' => 'assessed', 'points_earned' => 9,
            ]);

            // Only one of two items graded — must NOT count as completed.
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'instrument_item_id' => $itemOne->id,
                'enrollment_id' => $partialStudent->id, 'result_state' => 'assessed', 'points_earned' => 7,
            ]);
            // itemTwo left with no row for $partialStudent — stays pending.

            // One item under review, the other resolved — must be flagged as
            // under_review, not counted as completed even though only one
            // item is unsettled.
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'instrument_item_id' => $itemOne->id,
                'enrollment_id' => $underReviewStudent->id, 'result_state' => 'under_review', 'points_earned' => null,
            ]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id, 'instrument_item_id' => $itemTwo->id,
                'enrollment_id' => $underReviewStudent->id, 'result_state' => 'assessed', 'points_earned' => 6,
            ]);

            $this->actingAs($this->user)
                ->get('/assessments')
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Index')
                    ->where('assessments.0.progress.applicable', 3)
                    ->where('assessments.0.progress.completed', 1)
                    ->where('assessments.0.progress.under_review', 1)
                    ->where('assessments.0.progress.complete', false));
        });
    }

    #[Test]
    public function an_instrument_with_no_items_never_reports_any_applicable_student_as_completed(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id, 'enrolled_on' => '2026-01-01']);
            Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id, 'enrolled_on' => '2026-01-01']);
            Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'applied_on' => '2026-06-01',
            ]);

            // Two applicable students, zero items: must read 0/2, never a
            // vacuous "both done" just because there is nothing to grade.
            $this->actingAs($this->user)
                ->get('/assessments')
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Index')
                    ->where('assessments.0.progress.applicable', 2)
                    ->where('assessments.0.progress.completed', 0)
                    ->where('assessments.0.progress.complete', false));
        });
    }

    #[Test]
    public function the_index_action_verb_depends_on_the_derived_state_and_always_points_at_assessments_show(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            $expectedActionLabels = [
                'draft' => 'Continuar preparação',
                'in_correction' => 'Continuar',
                'completed' => 'Ver',
                'published' => 'Ver',
                'cancelled' => 'Ver',
                'archived' => 'Ver',
            ];

            foreach ($expectedActionLabels as $status => $expectedActionLabel) {
                $instrument = Instrument::factory()->recycle($this->organization)->create([
                    'class_id' => $class->id,
                    'academic_period_id' => $period->id,
                    'status' => $status,
                ]);

                $this->actingAs($this->user)
                    ->get("/assessments?status={$status}")
                    ->assertInertia(fn ($page) => $page
                        ->component('assessments/Index')
                        ->has('assessments', 1)
                        ->where('assessments.0.action_label', $expectedActionLabel));

                // The row's action is a real, working route — not a dead
                // link. A draft's own row instead sends the teacher to keep
                // preparing the grid, never to the assessment detail its
                // structure may not be ready to show.
                if ($status === 'draft') {
                    $this->actingAs($this->user)
                        ->get("/assessments/{$instrument->ulid}")
                        ->assertRedirect(route('instruments.edit', $instrument->ulid));

                    continue;
                }

                $this->actingAs($this->user)->get("/assessments/{$instrument->ulid}")->assertOk();
            }
        });
    }

    #[Test]
    public function both_prepared_sub_states_read_abrir_even_though_their_state_label_differs(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();

            $future = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'status' => 'prepared',
                'applied_on' => now()->addMonth()->toDateString(),
            ]);
            $past = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'status' => 'prepared',
                'applied_on' => now()->subMonth()->toDateString(),
            ]);

            $this->actingAs($this->user)
                ->get('/assessments')
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Index')
                    ->where('assessments.0.ulid', $future->ulid)
                    ->where('assessments.0.state_label', 'Agendada')
                    ->where('assessments.0.action_label', 'Abrir')
                    ->where('assessments.1.ulid', $past->ulid)
                    ->where('assessments.1.state_label', 'Por iniciar')
                    ->where('assessments.1.action_label', 'Abrir'));
        });
    }

    #[Test]
    public function the_list_can_be_filtered_by_status_purpose_and_period(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->classScenario();
            $otherPeriod = AcademicPeriod::factory()->recycle($this->organization)->for($class->academicYear)->create(['sequence' => 2]);

            $match = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'status' => 'published',
                'purpose' => 'diagnostic',
            ]);
            Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'status' => 'in_correction',
                'purpose' => 'summative',
            ]);
            Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $otherPeriod->id,
                'status' => 'published',
                'purpose' => 'diagnostic',
            ]);

            $this->actingAs($this->user)
                ->get('/assessments?status=published&purpose=diagnostic&period='.$period->id)
                ->assertInertia(fn ($page) => $page
                    ->component('assessments/Index')
                    ->has('assessments', 1)
                    ->where('assessments.0.ulid', $match->ulid));
        });
    }
}
