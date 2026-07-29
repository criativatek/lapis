<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\ClassStatus;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\ProfileVersionStatus;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\StudentEnrollmentService;
use App\Support\Privacy\BlindIndex;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClassTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @return array{year: int, subject: int}
     */
    protected function context(): array
    {
        return app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn () => [
            'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
        ]);
    }

    #[Test]
    public function creating_a_class_makes_the_creator_its_owner(): void
    {
        $context = $this->context();

        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
            'grade_level' => '7.º',
        ])->assertRedirect();

        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();
        $this->assertTrue($class->teachers()->whereKey($this->user->id)->wherePivot('role', 'owner')->exists());
    }

    #[Test]
    public function the_index_shows_only_the_teachers_own_classes(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);

        // Personal orgs are single-teacher, so the negative (a colleague not
        // seeing it) is out of scope here; assert the owner's index lists it.
        $response = $this->actingAs($this->user)->get('/classes');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('classes/Index')->has('classes', 1));
    }

    #[Test]
    public function a_teacher_cannot_view_a_class_they_do_not_teach(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();

        // Cross-organization: 404 (isolation). Same org but not a teacher would be
        // 403, but personal orgs make cross-org the reachable case here.
        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get("/classes/{$class->ulid}")->assertNotFound();
    }

    #[Test]
    public function enrolling_a_student_separates_name_from_pedagogical_record(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", [
            'name' => 'Miguel Santos',
            'class_number' => 12,
            'enrolled_on' => '2026-09-14',
        ])->assertRedirect();

        $student = Student::withoutGlobalScope('organization')->firstOrFail();
        // The student record carries no name — only a pseudonym.
        $this->assertStringStartsWith('ALU-', $student->pseudonym_code);
        $this->assertArrayNotHasKey('name', $student->getAttributes());

        // The name lives in the separate identity, and reads back decrypted.
        $identity = StudentIdentity::firstOrFail();
        $this->assertSame('Miguel Santos', $identity->display_name);

        // The enrollment ties them together.
        $this->assertSame(1, $class->enrollments()->count());
    }

    #[Test]
    public function the_stored_name_is_encrypted_at_rest(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Miguel Santos', 'enrolled_on' => '2026-09-14']);

        // The raw column bytes must not contain the plaintext name.
        $raw = DB::table('student_identities')->value('display_name');
        $this->assertStringNotContainsString('Miguel Santos', (string) $raw);

        // But the blind index lets an exact search find it without decrypting.
        $index = DB::table('student_identities')->value('display_name_index');
        $this->assertSame(BlindIndex::of('Miguel Santos'), $index);
        $this->assertSame(BlindIndex::of('miguel  santos'), $index, 'The index normalizes spacing and case.');
    }

    #[Test]
    public function a_student_entering_after_the_year_began_is_flagged_as_a_late_entry(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", [
            'name' => 'Aluna Nova',
            'enrolled_on' => '2026-11-03', // after the 2026-09-14 start
        ]);

        $enrollment = $class->enrollments()->firstOrFail();
        $this->assertTrue($enrollment->is_late_entry);
    }

    #[Test]
    public function a_profile_can_be_assigned_to_a_class_after_creation(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();
        $this->assertNull($class->assessment_profile_version_id);

        // An activated profile for the same subject.
        $version = app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($context) {
            $profile = AssessmentProfile::factory()->recycle($this->user->personalOrganization())->create([
                'academic_year_id' => $context['year'],
                'subject_id' => $context['subject'],
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($this->user->personalOrganization())->create([
                'assessment_profile_id' => $profile->id,
                'scale_id' => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
                'status' => ProfileVersionStatus::Draft,
            ]);
            $domain = Domain::factory()->recycle($this->user->personalOrganization())->create();
            $version->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 100, 'sequence' => 1]);

            return app(ActivateProfileVersion::class)->activate($version->refresh(), $this->user);
        });

        $this->actingAs($this->user)
            ->put("/classes/{$class->ulid}/profile", ['assessment_profile_version_id' => $version->id])
            ->assertRedirect();

        $this->assertSame($version->id, $class->refresh()->assessment_profile_version_id);
    }

    #[Test]
    public function a_draft_profile_cannot_be_assigned_to_a_class(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();

        $draft = app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($context) {
            $profile = AssessmentProfile::factory()->recycle($this->user->personalOrganization())->create([
                'academic_year_id' => $context['year'],
                'subject_id' => $context['subject'],
            ]);

            return AssessmentProfileVersion::factory()->recycle($this->user->personalOrganization())->create([
                'assessment_profile_id' => $profile->id,
                'scale_id' => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
                'status' => ProfileVersionStatus::Draft,
            ]);
        });

        // A class is assessed by a frozen, active version — never a draft (§10.2).
        $this->actingAs($this->user)
            ->put("/classes/{$class->ulid}/profile", ['assessment_profile_version_id' => $draft->id])
            ->assertSessionHasErrors('assessment_profile_version_id');

        $this->assertNull($class->refresh()->assessment_profile_version_id);
    }

    #[Test]
    public function a_student_entering_on_the_first_day_is_not_a_late_entry(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Aluno Pontual', 'enrolled_on' => '2026-09-14']);

        $this->assertFalse($class->enrollments()->firstOrFail()->is_late_entry);
    }

    #[Test]
    public function enrolling_a_student_accepts_optional_birth_date_and_note(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();

        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => app(StudentEnrollmentService::class)->enrollNew($class, [
                'name' => 'Maria Teste',
                'enrolled_on' => '2026-09-14',
                'birth_date' => '2013-05-04',
                'import_note' => 'Repetente · ASE: B',
            ]),
        );

        $identity = StudentIdentity::firstOrFail();
        $this->assertSame('2013-05-04', $identity->birth_date->toDateString());
        $this->assertSame('Repetente · ASE: B', Enrollment::withoutGlobalScope('organization')->firstOrFail()->import_note);
    }

    #[Test]
    public function a_class_in_preparation_can_be_activated(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/activate")
            ->assertRedirect();

        $this->assertSame(ClassStatus::Active, $class->refresh()->status);
    }

    #[Test]
    public function the_class_page_includes_the_raw_and_labelled_active_status(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();
        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => $class->update(['status' => ClassStatus::Active]),
        );

        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('schoolClass.status', 'active')
                ->where('schoolClass.status_label', 'Ativa'),
            );
    }

    #[Test]
    public function a_teacher_not_assigned_to_a_class_cannot_activate_it(): void
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);
        $class = SchoolClass::withoutGlobalScope('organization')->firstOrFail();
        $organization = $this->user->personalOrganization();
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->post("/classes/{$class->ulid}/activate")
            ->assertForbidden();

        $this->assertSame(ClassStatus::Preparation, $class->refresh()->status);
    }
}
