<?php

namespace Tests\Feature\Limits;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Services\StudentEnrollmentService;
use App\Support\Limits\LimitKey;
use App\Support\Limits\Limits;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `active_students` enforcement (§Lote 3) at its two growth points:
 * `StudentEnrollmentService::enrollNew()` (reached via `classes.students.store`
 * — a brand new student, always) and `::fillFromRoster()` (the real
 * "reactivation" path: a re-imported roll flipping an existing enrolment's
 * status back to Active).
 */
class ActiveStudentsLimitTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function createClass(): SchoolClass
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

    protected function postStudent(SchoolClass $class, string $name): TestResponse
    {
        return $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => $name]);
    }

    /**
     * $count brand-new, distinct, Active students on $class — created
     * directly against Enrollment/Student, bypassing StudentEnrollmentService
     * entirely, purely to seed a starting usage level quickly. The guarded
     * path itself is only ever exercised by postStudent()/fillFromRoster().
     */
    protected function seedActiveStudents(SchoolClass $class, int $count): void
    {
        $organization = $this->user->personalOrganization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $class, $count): void {
            Enrollment::factory()->recycle($organization)->count($count)->create([
                'class_id' => $class->id,
                'status' => 'active',
            ]);
        });
    }

    protected function activeStudentCount(): int
    {
        return app(Limits::class)->usageFor($this->user->personalOrganization()->fresh(), LimitKey::ActiveStudents);
    }

    /**
     * The actual `errors.limit` message(s) flashed to the session by the
     * request just made — read the same way `assertSessionHasErrors()` does
     * internally (`session.store`), never via `Limits` directly, so this
     * proves what a real HTTP response handed the teacher.
     *
     * @return array<int, string>
     */
    protected function limitErrorMessages(): array
    {
        return app('session.store')->get('errors')->getBag('default')->get('limit');
    }

    // ------------------------------------------------------- 1 / 2: o limite

    #[Test]
    public function base_with_299_active_students_admits_the_300th(): void
    {
        $class = $this->createClass();
        $this->seedActiveStudents($class, 299);

        $this->postStudent($class, 'Aluno 300')->assertRedirect();

        $this->assertSame(300, $this->activeStudentCount());
    }

    #[Test]
    public function base_with_300_active_students_blocks_the_301st(): void
    {
        $class = $this->createClass();
        $this->seedActiveStudents($class, 300);

        $this->postStudent($class, 'Aluno 301')->assertSessionHasErrors('limit');

        // The teacher sees the real configured limit and an explicit
        // assurance that nothing already on record was touched — not a
        // generic "operation failed" message (§Tarefa 1).
        $messages = $this->limitErrorMessages();
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('300', $messages[0], 'quotes the real configured limit');
        $this->assertStringContainsString('alunos', $messages[0]);
        $this->assertStringContainsString('mantidos', $messages[0], 'confirms existing data is kept');

        // ...and the blocked attempt really did create nothing: usage and
        // the raw row count agree, in the SAME test as the message check.
        $this->assertSame(300, $this->activeStudentCount(), 'the blocked attempt created nothing');
        $this->assertSame(
            300,
            Student::withoutGlobalScope('organization')
                ->where('organization_id', $this->user->personalOrganization()->getKey())
                ->count(),
            'no student row exists beyond the 300 seeded ones',
        );
    }

    // -------------------------------------------------------- 3: o que conta

    #[Test]
    public function a_student_with_only_a_historical_enrollment_does_not_count(): void
    {
        $organization = $this->user->personalOrganization();
        $class = $this->createClass();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $class): void {
            Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id, 'status' => 'transferred_out']);
            Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id, 'status' => 'left']);
            Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id, 'status' => 'concluded']);
        });

        $this->assertSame(0, $this->activeStudentCount());
    }

    // -------------------------------------------------------- 5: reativação

    /**
     * Reactivation via a real roster re-import — `RosterImportController::confirm()`
     * (`classes.roster-imports.confirm`), not a direct call into
     * `StudentEnrollmentService::fillFromRoster()`. `situation_code: 'X'`
     * (`EnrollmentSituation::Enrolled`) is exactly what a school's own export
     * uses for "matriculado", and is what the confirm loop translates into the
     * Active status that would grow usage here.
     */
    #[Test]
    public function reactivating_a_student_via_fill_from_roster_is_blocked_at_the_limit(): void
    {
        $organization = $this->user->personalOrganization();
        $class = $this->createClass();
        $this->seedActiveStudents($class, 300);

        $departed = app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): Enrollment => Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id, 'status' => 'left']),
        );

        $response = $this->actingAs($this->user)->post(
            "/classes/{$class->ulid}/roster-imports/".Str::uuid().'/confirm',
            [
                'rows' => [[
                    'name' => 'Aluno Reativado',
                    'class_number' => null,
                    'birth_date' => null,
                    'situation_code' => 'X',
                    'note' => null,
                    'process_number' => null,
                    'photo_index' => null,
                    'photo_extension' => null,
                    'include' => true,
                    'enrollment_id' => $departed->id,
                ]],
            ],
        );

        $response->assertSessionHasErrors('limit');

        $messages = $this->limitErrorMessages();
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('300', $messages[0], 'quotes the real configured limit');
        $this->assertStringContainsString('alunos', $messages[0]);
        $this->assertStringContainsString('mantidos', $messages[0], 'confirms existing data is kept');

        // The blocked reimport left the existing enrollment exactly as it
        // was — still not Active — and usage unchanged, in the SAME test as
        // the message check.
        $this->assertSame(
            EnrollmentStatus::Left,
            $departed->fresh()->status,
            'the blocked reactivation via re-import left the enrollment status untouched',
        );
        $this->assertSame(300, $this->activeStudentCount());
    }

    #[Test]
    public function reactivating_a_student_via_fill_from_roster_succeeds_under_the_limit(): void
    {
        $organization = $this->user->personalOrganization();
        $class = $this->createClass();
        $this->seedActiveStudents($class, 299);

        $departed = app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): Enrollment => Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id, 'status' => 'left']),
        );

        app(CurrentOrganization::class)->runFor($organization, function () use ($departed): void {
            app(StudentEnrollmentService::class)->fillFromRoster($departed, [
                'situation' => ['status' => EnrollmentStatus::Active->value, 'status_reason' => null],
            ]);
        });

        $this->assertSame(EnrollmentStatus::Active, $departed->fresh()->status);
        $this->assertSame(300, $this->activeStudentCount());
    }

    #[Test]
    public function reactivating_a_student_already_active_through_another_enrollment_needs_no_new_capacity(): void
    {
        // The student is already counted once, through a DIFFERENT active
        // enrolment — reactivating THIS one must not double-count them, and
        // must not be blocked even with the organization already full.
        $organization = $this->user->personalOrganization();
        $classA = $this->createClass();
        $this->seedActiveStudents($classA, 300);

        $student = app(CurrentOrganization::class)->runFor($organization, fn () => Student::factory()->recycle($organization)->create());

        $context = app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn (): array => [
            'year' => AcademicYear::factory()->recycle($organization)->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($organization)->create()->id,
        ]);
        $this->actingAs($this->user)->post('/classes', ['label' => '8.º B', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $classB = SchoolClass::withoutGlobalScope('organization')->where('label', '8.º B')->firstOrFail();

        [$activeElsewhere, $left] = app(CurrentOrganization::class)->runFor($organization, fn (): array => [
            Enrollment::factory()->recycle($organization)->create(['class_id' => $classA->id, 'student_id' => $student->id, 'status' => 'active']),
            Enrollment::factory()->recycle($organization)->create(['class_id' => $classB->id, 'student_id' => $student->id, 'status' => 'left']),
        ]);

        // Usage is 301 real distinct students now (300 seeded + this one via
        // $activeElsewhere) — already above the 300 cap, on purpose: the
        // point of this test is that reactivating $left must not need
        // assertCanIncreaseFor() to pass, because it adds no NEW student.
        app(CurrentOrganization::class)->runFor($organization, function () use ($left): void {
            app(StudentEnrollmentService::class)->fillFromRoster($left, [
                'situation' => ['status' => EnrollmentStatus::Active->value, 'status_reason' => null],
            ]);
        });

        $this->assertSame(EnrollmentStatus::Active, $left->fresh()->status);
    }

    // --------------------------------------------------- 6 / 7: acima do limite

    #[Test]
    public function an_organization_already_above_300_preserves_every_student(): void
    {
        $class = $this->createClass();
        $this->seedActiveStudents($class, 305);

        $this->assertSame(305, $this->activeStudentCount());
        $this->assertSame(
            305,
            Student::withoutGlobalScope('organization')
                ->where('organization_id', $this->user->personalOrganization()->getKey())
                ->count(),
            'Nothing was deleted just by being over the limit.',
        );
    }

    #[Test]
    public function an_organization_already_above_300_cannot_enroll_another_student(): void
    {
        $class = $this->createClass();
        $this->seedActiveStudents($class, 305);

        $this->postStudent($class, 'Aluno Extra')->assertSessionHasErrors('limit');

        $this->assertSame(305, $this->activeStudentCount());
    }

    // ------------------------------------------------------- 8 / 9: ilimitado

    #[Test]
    public function a_pro_organization_is_unlimited_even_with_many_students(): void
    {
        $organization = $this->user->personalOrganization();
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), Plan::where('key', 'pro')->firstOrFail());
        $class = $this->createClass();
        $this->seedActiveStudents($class, 500);

        $this->postStudent($class, 'Aluno Extra')->assertRedirect();

        $this->assertSame(501, $this->activeStudentCount());
    }

    #[Test]
    public function an_institutional_organization_defaults_to_unlimited(): void
    {
        $organization = $this->user->personalOrganization();
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), Plan::where('key', 'institutional')->firstOrFail());
        $class = $this->createClass();
        $this->seedActiveStudents($class, 500);

        $this->postStudent($class, 'Aluno Extra')->assertRedirect();

        $this->assertSame(501, $this->activeStudentCount());
    }

    // ---------------------------------------------------- 10: o boundary HTTP

    #[Test]
    public function the_http_route_itself_is_blocked_not_only_the_underlying_service(): void
    {
        $class = $this->createClass();
        $this->seedActiveStudents($class, 300);

        $response = $this->postStudent($class, 'Aluno 301');

        $response->assertSessionHasErrors('limit');
        $this->assertSame(300, $this->activeStudentCount());
    }
}
