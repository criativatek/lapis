<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusReason;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Já não está na turma» and «nunca esteve» are different sentences.
 *
 * The SIT import gave enrolments four ways of ending, but every screen still
 * read `enrollments()` — so a student the school had moved to another class
 * went on appearing as though nothing had happened.
 *
 * The fix is deliberately NOT a global scope. `enrollments()` still answers
 * «who was ever on this roll», because that is what results, classifications
 * and interim snapshots need: a student who transferred out in February was in
 * the class in November, and every reading of November is still true. The
 * choice is made per screen, by what the screen is for.
 */
class ActiveEnrollmentTest extends TestCase
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

    private function enrol(SchoolClass $class, string $name, EnrollmentStatus $status, ?EnrollmentStatusReason $reason = null): Enrollment
    {
        return app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): Enrollment => app(StudentEnrollmentService::class)->enrollNew($class, [
                'name' => $name,
                'status' => $status->value,
                'status_reason' => $reason?->value,
            ]),
        );
    }

    // ------------------------------------------------ 1. a regra canónica

    #[Test]
    public function only_an_active_enrolment_is_part_of_the_class_today(): void
    {
        $class = $this->createClass();

        $this->enrol($class, 'Ana Ativa', EnrollmentStatus::Active);
        $this->enrol($class, 'Bruno Transferido', EnrollmentStatus::TransferredOut, EnrollmentStatusReason::Transfer);
        $this->enrol($class, 'Carla Mudou', EnrollmentStatus::Left, EnrollmentStatusReason::MovedClass);
        $this->enrol($class, 'Diogo Concluiu', EnrollmentStatus::Concluded);

        app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($class): void {
            $this->assertSame(1, $class->activeEnrollments()->count());
            $this->assertSame(4, $class->enrollments()->count(), 'ninguém foi apagado');
            $this->assertSame(1, Enrollment::query()->active()->count());
        });
    }

    #[Test]
    public function the_rule_is_stated_positively_so_a_new_status_is_not_current_by_default(): void
    {
        // «not left» would have counted a transfer and a conclusion as current.
        $this->assertTrue(EnrollmentStatus::Active->isCurrent());

        foreach ([EnrollmentStatus::TransferredOut, EnrollmentStatus::Left, EnrollmentStatus::Concluded] as $status) {
            $this->assertFalse($status->isCurrent(), "«{$status->value}» não é a turma de hoje");
        }
    }

    // -------------------------------------------- 2. a lista corrente

    #[Test]
    public function the_class_page_shows_only_the_students_who_are_in_it(): void
    {
        $class = $this->createClass();

        $this->enrol($class, 'Ana Ativa', EnrollmentStatus::Active);
        $this->enrol($class, 'Bruno Ativo', EnrollmentStatus::Active);
        $this->enrol($class, 'Carla Ativa', EnrollmentStatus::Active);
        $this->enrol($class, 'Duarte Transferido', EnrollmentStatus::TransferredOut, EnrollmentStatusReason::Transfer);
        $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left, EnrollmentStatusReason::MovedClass);
        $this->enrol($class, 'Filipe Anulou', EnrollmentStatus::Left, EnrollmentStatusReason::CancelledEnrolment);
        $this->enrol($class, 'Gabriela Excluida', EnrollmentStatus::Left, EnrollmentStatusReason::ExcludedForAbsences);

        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('students', 3)
                // And the other four are listed apart rather than lost.
                ->has('former_students', 4));
    }

    #[Test]
    public function a_former_student_is_named_by_why_they_left_and_never_by_a_code(): void
    {
        $class = $this->createClass();

        $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left, EnrollmentStatusReason::MovedClass);

        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('former_students.0.state_label', 'Mudou de turma'));
    }

    #[Test]
    public function a_class_whose_students_all_left_still_lists_them(): void
    {
        $class = $this->createClass();

        $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left, EnrollmentStatusReason::MovedClass);

        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('students', 0)->has('former_students', 1));
    }

    // ------------------------------------ 3. os fluxos que pedem algo novo

    #[Test]
    public function self_assessment_is_only_asked_of_students_who_are_in_the_class(): void
    {
        $class = $this->createClass();

        $this->enrol($class, 'Ana Ativa', EnrollmentStatus::Active);
        $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left, EnrollmentStatusReason::MovedClass);

        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}/self-assessments")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1));
    }

    // ---------------------------------------------- 4. nada é apagado

    #[Test]
    public function leaving_the_class_never_removes_the_enrolment_or_the_student(): void
    {
        $class = $this->createClass();

        $enrollment = $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Active);
        $studentId = $enrollment->student_id;

        app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($enrollment): void {
            $enrollment->update([
                'status' => EnrollmentStatus::Left,
                'status_reason' => EnrollmentStatusReason::MovedClass,
            ]);
        });

        app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($enrollment, $studentId): void {
            $again = Enrollment::findOrFail($enrollment->id);

            $this->assertSame($studentId, $again->student_id);
            $this->assertSame($enrollment->enrolled_on->toDateString(), $again->enrolled_on->toDateString());
            $this->assertNull($again->left_on, 'o ficheiro não dá data de saída e nenhuma é inventada');
        });
    }

    #[Test]
    public function history_is_read_through_the_relation_that_keeps_everyone(): void
    {
        $class = $this->createClass();

        $this->enrol($class, 'Ana Ativa', EnrollmentStatus::Active);
        $this->enrol($class, 'Eva Mudou', EnrollmentStatus::Left, EnrollmentStatusReason::MovedClass);

        app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($class): void {
            // The calculator, the summary and the interim snapshots all read
            // `enrollments()`, which is why a student who left in February is
            // still in November's results (§5, §6, §21).
            $this->assertSame(2, $class->enrollments()->count());
        });
    }

    // ------------------------------------------------- 5. a reativação

    #[Test]
    public function coming_back_puts_a_student_straight_into_the_current_class(): void
    {
        $class = $this->createClass();

        $enrollment = $this->enrol($class, 'Eva Voltou', EnrollmentStatus::Left, EnrollmentStatusReason::MovedClass);

        $this->actingAs($this->user)->get("/classes/{$class->ulid}")
            ->assertInertia(fn ($page) => $page->has('students', 0));

        app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($enrollment): void {
            $enrollment->update(['status' => EnrollmentStatus::Active, 'status_reason' => null]);
        });

        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}")
            ->assertInertia(fn ($page) => $page->has('students', 1)->has('former_students', 0));

        // The same row, not a second enrolment and not a second student.
        $this->assertSame(1, Enrollment::withoutGlobalScope('organization')->count());
    }
}
