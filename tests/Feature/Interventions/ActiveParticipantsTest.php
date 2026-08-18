<?php

namespace Tests\Feature\Interventions;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusReason;
use App\Models\Intervention;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A NEW record is about the class as it stands; an OLD one is about the class
 * as it was.
 *
 * The distinction has to hold in both directions. A student who moved to
 * another class in February must not be offered for an intervention recorded
 * today — and an intervention recorded in November that names them must still
 * be editable, or correcting its date would silently drop a participant nobody
 * asked to remove.
 */
class ActiveParticipantsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function createClass(): SchoolClass
    {
        $context = app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn (): array => [
            'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
        ]);

        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')->firstOrFail();
    }

    private function enrol(SchoolClass $class, string $name, EnrollmentStatus $status): Enrollment
    {
        return app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): Enrollment => app(StudentEnrollmentService::class)->enrollNew($class, [
                'name' => $name,
                'status' => $status->value,
                'status_reason' => $status === EnrollmentStatus::Left
                    ? EnrollmentStatusReason::MovedClass->value
                    : null,
            ]),
        );
    }

    /**
     * @param  list<int>  $participants
     */
    private function payload(array $participants): array
    {
        return [
            'target_type' => count($participants) === 1 ? 'student' : 'group',
            'enrollment_ids' => $participants,
            'intervention_type' => 'learning_reinforcement',
            'domain_relation' => 'none',
            'started_on' => '2026-11-10',
            'description' => 'Apoio à leitura.',
        ];
    }

    // ------------------------------------------- 1. um registo novo

    #[Test]
    public function a_new_intervention_may_not_name_a_student_who_left_the_class(): void
    {
        $class = $this->createClass();
        $gone = $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left);

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/interventions", $this->payload([$gone->id]))
            ->assertSessionHasErrors('enrollment_ids');

        $this->assertSame(0, Intervention::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function a_new_intervention_names_a_student_who_is_in_the_class(): void
    {
        $class = $this->createClass();
        $here = $this->enrol($class, 'Ana Ativa', EnrollmentStatus::Active);

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/interventions", $this->payload([$here->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Intervention::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function a_group_is_refused_whole_when_one_of_its_students_has_left(): void
    {
        $class = $this->createClass();
        $here = $this->enrol($class, 'Ana Ativa', EnrollmentStatus::Active);
        $gone = $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left);

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/interventions", $this->payload([$here->id, $gone->id]))
            ->assertSessionHasErrors('enrollment_ids');
    }

    // ------------------------------------- 2. um registo que já existe

    #[Test]
    public function an_intervention_stays_editable_after_its_student_leaves(): void
    {
        $class = $this->createClass();
        $student = $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Active);

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/interventions", $this->payload([$student->id]))
            ->assertSessionHasNoErrors();

        $intervention = Intervention::withoutGlobalScope('organization')->firstOrFail();

        // February: the roll says they moved class.
        app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($student): void {
            $student->update([
                'status' => EnrollmentStatus::Left,
                'status_reason' => EnrollmentStatusReason::MovedClass,
            ]);
        });

        // Correcting the description must still work, and must not drop them.
        $this->actingAs($this->user)
            ->put("/interventions/{$intervention->ulid}", array_merge(
                $this->payload([$student->id]),
                ['description' => 'Apoio à leitura, revisto.'],
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [$student->id],
            Intervention::withoutGlobalScope('organization')->firstOrFail()->participants->pluck('id')->all(),
        );
    }

    #[Test]
    public function the_record_itself_is_never_deleted_or_reassigned(): void
    {
        $class = $this->createClass();
        $student = $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Active);

        $this->actingAs($this->user)->post("/classes/{$class->ulid}/interventions", $this->payload([$student->id]));

        app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($student): void {
            $student->update(['status' => EnrollmentStatus::Left, 'status_reason' => EnrollmentStatusReason::MovedClass]);
        });

        $intervention = Intervention::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame([$student->id], $intervention->participants->pluck('id')->all());
    }

    // ------------------------------------------------ 3. a reativação

    #[Test]
    public function coming_back_makes_a_student_choosable_again(): void
    {
        $class = $this->createClass();
        $student = $this->enrol($class, 'Eva Voltou', EnrollmentStatus::Left);

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/interventions", $this->payload([$student->id]))
            ->assertSessionHasErrors('enrollment_ids');

        app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($student): void {
            $student->update(['status' => EnrollmentStatus::Active, 'status_reason' => null]);
        });

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/interventions", $this->payload([$student->id]))
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------- 4. as listas

    #[Test]
    public function the_page_offers_the_whole_roll_and_says_who_is_still_in_it(): void
    {
        $class = $this->createClass();
        $this->enrol($class, 'Ana Ativa', EnrollmentStatus::Active);
        $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left);

        // Everyone, so the filter and the edit form work; and the ids of those
        // still here, so the picker can offer only them.
        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}/interventions")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('enrollments', 2)
                ->has('activeEnrollmentIds', 1));
    }

    #[Test]
    public function the_records_page_does_the_same(): void
    {
        $class = $this->createClass();
        $this->enrol($class, 'Ana Ativa', EnrollmentStatus::Active);
        $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left);

        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}/records")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('enrollments', 2)
                ->has('activeEnrollmentIds', 1));
    }

    #[Test]
    public function a_new_record_may_not_name_a_student_who_left_either(): void
    {
        $class = $this->createClass();
        $gone = $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left);

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/records", [
                'kind' => 'participation',
                'enrollment_ids' => [$gone->id],
                'occurred_at' => '2026-11-10T10:00',
                'participation_level' => 'positive',
            ])
            ->assertSessionHasErrors('enrollment_ids');
    }
}
