<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Support\Privacy\BlindIndex;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Correcting a student's basic data in place.
 *
 * The rule these tests exist to protect: a correction is an edit, never a
 * re-creation. The pseudonym and the enrollment are the anchors every result,
 * record and intervention hangs off (§11.2, §11.4) — if a typo in a name could
 * move them, the pedagogical history would silently detach from the student.
 */
class EnrollmentUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function createClass(?User $owner = null): SchoolClass
    {
        $owner ??= $this->user;

        $context = app(CurrentOrganization::class)->runFor($owner->personalOrganization(), fn () => [
            'year' => AcademicYear::factory()->recycle($owner->personalOrganization())->create([
                'starts_on' => '2026-09-14',
                'ends_on' => '2027-06-30',
            ])->id,
            'subject' => Subject::factory()->recycle($owner->personalOrganization())->create()->id,
        ]);

        $this->actingAs($owner)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')
            ->where('organization_id', $owner->personalOrganization()->id)
            ->latest('id')
            ->firstOrFail();
    }

    protected function enroll(SchoolClass $class, string $name, ?int $number = null): Enrollment
    {
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", [
            'name' => $name,
            'class_number' => $number,
            'enrolled_on' => '2026-09-14',
        ]);

        return Enrollment::withoutGlobalScope('organization')
            ->where('class_id', $class->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function edit(SchoolClass $class, Enrollment $enrollment, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user)->put(
            "/classes/{$class->ulid}/students/{$enrollment->ulid}",
            array_merge([
                'name' => 'Nome Corrigido',
                'class_number' => 3,
                'enrolled_on' => '2026-09-14',
            ], $overrides),
        );
    }

    // ------------------------------------------------------------ the edits

    #[Test]
    public function a_teacher_can_correct_a_students_name(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->edit($class, $enrollment, ['name' => 'João Silva'])
            ->assertRedirect();

        $identity = StudentIdentity::withoutGlobalScopes()
            ->where('student_id', $enrollment->student_id)
            ->firstOrFail();

        $this->assertSame('João Silva', $identity->display_name);
    }

    #[Test]
    public function a_student_without_an_identity_gets_one_and_stops_showing_as_nameless(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Temporario');

        // The state this test is about: an enrollment whose student carries no
        // identity row at all, which the UI renders as "(sem identidade)".
        StudentIdentity::withoutGlobalScopes()
            ->where('student_id', $enrollment->student_id)
            ->delete();
        $this->assertNull(
            Student::withoutGlobalScopes()->find($enrollment->student_id)->identity,
        );

        $this->edit($class, $enrollment, ['name' => 'Maria Antunes'])
            ->assertRedirect();

        $student = Student::withoutGlobalScopes()->findOrFail($enrollment->student_id);
        $this->assertNotNull($student->identity);
        $this->assertSame('Maria Antunes', $student->identity->display_name);

        // And the class page now shows the real name.
        $this->actingAs($this->user)->get("/classes/{$class->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('students.0.name', 'Maria Antunes')
                ->where('students.0.has_identity', true));
    }

    #[Test]
    public function a_teacher_can_correct_the_class_number(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva', 12);

        $this->edit($class, $enrollment, ['class_number' => 7])->assertRedirect();

        $this->assertSame(7, $enrollment->fresh()->class_number);
    }

    #[Test]
    public function a_teacher_can_clear_the_class_number(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva', 12);

        $this->edit($class, $enrollment, ['class_number' => null])->assertRedirect();

        $this->assertNull($enrollment->fresh()->class_number);
    }

    #[Test]
    public function a_teacher_can_correct_the_entry_date_and_the_late_marker_follows(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->assertFalse($enrollment->is_late_entry);

        // Moving the entry past the start of the academic year makes it late...
        $this->edit($class, $enrollment, ['enrolled_on' => '2026-11-03'])->assertRedirect();

        $enrollment->refresh();
        $this->assertSame('2026-11-03', $enrollment->enrolled_on->toDateString());
        $this->assertTrue($enrollment->is_late_entry);

        // ...and correcting the mistake back has to clear it again, not leave a
        // stale marker behind.
        $this->edit($class, $enrollment, ['enrolled_on' => '2026-09-14'])->assertRedirect();

        $this->assertFalse($enrollment->fresh()->is_late_entry);
    }

    // ------------------------------------------------- what must never move

    #[Test]
    public function the_pseudonym_never_changes(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $pseudonymBefore = Student::withoutGlobalScopes()
            ->findOrFail($enrollment->student_id)->pseudonym_code;

        $this->edit($class, $enrollment, [
            'name' => 'João Silva',
            'class_number' => 9,
            'enrolled_on' => '2026-10-01',
        ])->assertRedirect();

        $this->assertSame(
            $pseudonymBefore,
            Student::withoutGlobalScopes()->findOrFail($enrollment->student_id)->pseudonym_code,
        );
    }

    #[Test]
    public function no_new_student_or_enrollment_is_created(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $studentsBefore = Student::withoutGlobalScopes()->count();
        $enrollmentsBefore = Enrollment::withoutGlobalScopes()->count();
        $identitiesBefore = StudentIdentity::withoutGlobalScopes()->count();

        $this->edit($class, $enrollment, ['name' => 'João Silva'])->assertRedirect();

        $this->assertSame($studentsBefore, Student::withoutGlobalScopes()->count());
        $this->assertSame($enrollmentsBefore, Enrollment::withoutGlobalScopes()->count());
        $this->assertSame($identitiesBefore, StudentIdentity::withoutGlobalScopes()->count());

        // Same rows, not merely the same count.
        $fresh = $enrollment->fresh();
        $this->assertSame($enrollment->id, $fresh->id);
        $this->assertSame($enrollment->ulid, $fresh->ulid);
        $this->assertSame($enrollment->student_id, $fresh->student_id);
    }

    #[Test]
    public function existing_pedagogical_records_stay_attached_to_the_student(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $score = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            function () use ($class, $enrollment): StudentItemScore {
                $instrument = Instrument::factory()
                    ->recycle($this->user->personalOrganization())
                    ->for($class, 'schoolClass')
                    ->create();

                return StudentItemScore::factory()
                    ->recycle($this->user->personalOrganization())
                    ->create([
                        'instrument_id' => $instrument->id,
                        'instrument_item_id' => InstrumentItem::factory()
                            ->for($instrument)
                            ->create()->id,
                        'enrollment_id' => $enrollment->id,
                    ]);
            },
        );

        $this->edit($class, $enrollment, [
            'name' => 'João Silva',
            'class_number' => 4,
            'enrolled_on' => '2026-10-20',
        ])->assertRedirect();

        $score->refresh();
        $this->assertSame($enrollment->id, $score->enrollment_id);
        $this->assertSame('assessed', $score->result_state->value);
        $this->assertSame(1, StudentItemScore::withoutGlobalScopes()
            ->where('enrollment_id', $enrollment->id)->count());
    }

    // ------------------------------------------------------- authorization

    #[Test]
    public function a_colleague_in_the_same_organization_who_does_not_teach_the_class_is_forbidden(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        // Same organization — so tenancy resolves the class and the Policy is
        // what has to refuse. "Only my classes" (§23), not only my tenant.
        $colleague = User::factory()->create();
        $colleague->organizations()->attach(
            $this->user->personalOrganization(),
            ['joined_at' => now()],
        );

        $this->actingAs($colleague)->put(
            "/classes/{$class->ulid}/students/{$enrollment->ulid}",
            ['name' => 'Invadido', 'enrolled_on' => '2026-09-14'],
        )->assertForbidden();

        $this->assertSame('Joao Silva', StudentIdentity::withoutGlobalScopes()
            ->where('student_id', $enrollment->student_id)->firstOrFail()->display_name);
    }

    #[Test]
    public function a_teacher_from_another_organization_cannot_even_see_the_class(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        // A different tenant: the class must not resolve at all, so the answer
        // is 404 rather than 403 — no confirmation that it exists.
        $this->actingAs(User::factory()->create())->put(
            "/classes/{$class->ulid}/students/{$enrollment->ulid}",
            ['name' => 'Invadido', 'enrolled_on' => '2026-09-14'],
        )->assertNotFound();

        $this->assertSame('Joao Silva', StudentIdentity::withoutGlobalScopes()
            ->where('student_id', $enrollment->student_id)->firstOrFail()->display_name);
    }

    #[Test]
    public function a_student_from_another_organization_is_not_reachable(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        // Another teacher, another organization, another class of their own.
        $other = User::factory()->create();
        $otherClass = $this->createClass($other);

        // Their own class ulid, but our enrollment ulid: the tenant scope must
        // not resolve it, whatever ids are hand-crafted into the request.
        $this->actingAs($other)->put(
            "/classes/{$otherClass->ulid}/students/{$enrollment->ulid}",
            ['name' => 'Invadido', 'enrolled_on' => '2026-09-14'],
        )->assertNotFound();

        $this->assertSame('Joao Silva', StudentIdentity::withoutGlobalScopes()
            ->where('student_id', $enrollment->student_id)->firstOrFail()->display_name);
    }

    #[Test]
    public function an_enrollment_from_another_class_of_mine_is_rejected(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $otherClass = $this->createClass();

        $this->actingAs($this->user)->put(
            "/classes/{$otherClass->ulid}/students/{$enrollment->ulid}",
            ['name' => 'Trocado', 'enrolled_on' => '2026-09-14'],
        )->assertNotFound();
    }

    // ---------------------------------------------------------- validation

    #[Test]
    public function invalid_data_is_rejected(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->edit($class, $enrollment, ['name' => ''])->assertSessionHasErrors('name');
        $this->edit($class, $enrollment, ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
        $this->edit($class, $enrollment, ['class_number' => 0])->assertSessionHasErrors('class_number');
        $this->edit($class, $enrollment, ['class_number' => 70000])->assertSessionHasErrors('class_number');
        $this->edit($class, $enrollment, ['enrolled_on' => 'ontem'])->assertSessionHasErrors('enrolled_on');
        $this->edit($class, $enrollment, ['enrolled_on' => ''])->assertSessionHasErrors('enrolled_on');

        // Nothing leaked through any of those.
        $this->assertSame('Joao Silva', StudentIdentity::withoutGlobalScopes()
            ->where('student_id', $enrollment->student_id)->firstOrFail()->display_name);
    }

    // ------------------------------- the composite unique, scenario by scenario

    /**
     * enrollments_class_student_date_unique is (class_id, student_id,
     * enrolled_on). The validation rule has to reproduce exactly that triple —
     * a plain unique on enrolled_on would forbid two classmates from sharing an
     * entry date, which is the normal case for a whole class.
     */
    #[Test]
    public function two_different_students_may_share_an_entry_date_in_the_same_class(): void
    {
        $class = $this->createClass();
        $studentA = $this->enroll($class, 'Aluno A');
        $studentB = $this->enroll($class, 'Aluno B');

        // Both already sit on 2026-09-14; editing B onto the very same date must
        // not be read as a clash with A.
        $this->edit($class, $studentB, ['name' => 'Aluno B', 'enrolled_on' => '2026-09-14'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('2026-09-14', $studentB->fresh()->enrolled_on->toDateString());
        $this->assertSame('2026-09-14', $studentA->fresh()->enrolled_on->toDateString());
    }

    #[Test]
    public function moving_an_enrollment_onto_the_date_of_the_same_students_other_enrollment_is_rejected(): void
    {
        $class = $this->createClass();
        $first = $this->enroll($class, 'Joao Silva');

        // The same student re-entering the same class later — the case the date
        // is in the unique key for.
        $second = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): Enrollment => Enrollment::create([
                'class_id' => $class->id,
                'student_id' => $first->student_id,
                'enrolled_on' => '2027-01-12',
                'status' => 'active',
            ]),
        );

        // Dragging the second onto the first's date would violate the index:
        // it has to come back as validation, never as a 500.
        $this->edit($class, $second, ['enrolled_on' => '2026-09-14'])
            ->assertSessionHasErrors('enrolled_on');

        $this->assertSame('2027-01-12', $second->fresh()->enrolled_on->toDateString());
    }

    #[Test]
    public function saving_an_enrollment_on_its_own_unchanged_date_is_allowed(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        // Without ignore() the row would collide with itself, and a teacher
        // correcting only the spelling of a name would be blocked.
        $this->edit($class, $enrollment, ['name' => 'João Silva', 'enrolled_on' => '2026-09-14'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('João Silva', StudentIdentity::withoutGlobalScopes()
            ->where('student_id', $enrollment->student_id)->firstOrFail()->display_name);
        $this->assertSame('2026-09-14', $enrollment->fresh()->enrolled_on->toDateString());
    }

    #[Test]
    public function the_same_student_may_hold_the_same_entry_date_in_a_different_class(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $otherClass = $this->createClass();

        // class_id is part of the key, so the same student on the same date in
        // another class is not a clash — a teacher's two classes commonly start
        // on the same day.
        $otherEnrollment = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): Enrollment => Enrollment::create([
                'class_id' => $otherClass->id,
                'student_id' => $enrollment->student_id,
                'enrolled_on' => '2026-10-05',
                'status' => 'active',
            ]),
        );

        $this->edit($otherClass, $otherEnrollment, ['enrolled_on' => '2026-09-14'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('2026-09-14', $otherEnrollment->fresh()->enrolled_on->toDateString());
        $this->assertSame('2026-09-14', $enrollment->fresh()->enrolled_on->toDateString());
    }

    // -------------------------------------------------------------- privacy

    #[Test]
    public function the_corrected_name_stays_encrypted_and_its_blind_index_follows(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->edit($class, $enrollment, ['name' => 'João Silva'])->assertRedirect();

        $row = DB::table('student_identities')->where('student_id', $enrollment->student_id)->first();

        // Never in the clear at rest.
        $this->assertNotSame('João Silva', $row->display_name);
        $this->assertStringNotContainsString('João', $row->display_name);

        // And the blind index was recomputed for the new name, so exact search
        // keeps working after a correction.
        $this->assertSame(BlindIndex::of('João Silva'), $row->display_name_index);
        $this->assertNotSame(BlindIndex::of('Joao Silva'), $row->display_name_index);
    }

    #[Test]
    public function editing_a_name_does_not_touch_the_other_identity_fields(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $identity = StudentIdentity::withoutGlobalScopes()
            ->where('student_id', $enrollment->student_id)->firstOrFail();
        $identity->forceFill([
            'school_number' => '20260001',
            'photo_path' => 'private/student-photos/joao.jpg',
        ])->save();

        $this->edit($class, $enrollment, ['name' => 'João Silva'])->assertRedirect();

        $identity->refresh();
        $this->assertSame('João Silva', $identity->display_name);
        // Photo and school number belong to other flows; a name correction must
        // not quietly drop them.
        $this->assertSame('20260001', $identity->school_number);
        $this->assertSame('private/student-photos/joao.jpg', $identity->photo_path);
    }
}
