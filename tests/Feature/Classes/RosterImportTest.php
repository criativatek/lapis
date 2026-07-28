<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DocxFixtureBuilder;
use Tests\Support\RosterFixture;
use Tests\TestCase;

class RosterImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
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

    protected function createClass(): SchoolClass
    {
        $context = $this->context();
        $this->actingAs($this->user)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $context['year'], 'subject_id' => $context['subject']]);

        return SchoolClass::withoutGlobalScope('organization')->firstOrFail();
    }

    #[Test]
    public function a_students_photo_streams_for_the_teacher_who_teaches_them(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();

        Storage::disk('local')->put('student-photos/'.$student->ulid.'.jpg', 'fake-bytes');
        StudentIdentity::where('student_id', $student->id)->update(['photo_path' => 'student-photos/'.$student->ulid.'.jpg']);

        $response = $this->actingAs($this->user)->get("/students/{$student->ulid}/photo");

        $response->assertOk();
        $this->assertSame('fake-bytes', $response->streamedContent());
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_student_cannot_see_the_photo(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();
        StudentIdentity::where('student_id', $student->id)->update(['photo_path' => 'student-photos/x.jpg']);

        // A stranger with no organization in common with the student at all. This
        // is denied before StudentPolicy::viewPhoto() ever runs: the tenant-scoped
        // route-model-binding for {student} can't find the row under the
        // stranger's own organization, so Laravel 404s at binding time. It is NOT
        // exercising the Policy's "same school, wrong teacher" logic — see
        // a_teacher_in_the_same_organization_who_does_not_teach_the_student_is_forbidden()
        // below for that.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/students/{$student->ulid}/photo")->assertNotFound();
    }

    #[Test]
    public function a_teacher_in_the_same_organization_who_does_not_teach_the_student_is_forbidden(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();
        StudentIdentity::where('student_id', $student->id)->update(['photo_path' => 'student-photos/x.jpg']);

        // A colleague in the SAME organization, but never attached to this class's
        // teachers pivot — the actual scenario StudentPolicy::viewPhoto() exists to
        // guard against ("enrolled in a class this teacher teaches", not "any
        // class in the organization"). Unlike the cross-organization stranger
        // above, this request reaches Gate::authorize() and is denied there, so it
        // is a 403 (Laravel's default for a policy denial) — not a 404. Documented
        // here so nobody later assumes this should also 404.
        $organization = $this->user->personalOrganization();
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->get("/students/{$student->ulid}/photo")
            ->assertForbidden();
    }

    #[Test]
    public function a_student_with_no_photo_returns_not_found(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($this->user)->get("/students/{$student->ulid}/photo")->assertNotFound();
    }

    #[Test]
    public function a_teacher_can_no_longer_see_the_photo_once_the_student_has_transferred_out(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", ['name' => 'Maria Teste']);
        $student = Student::withoutGlobalScope('organization')->firstOrFail();
        StudentIdentity::where('student_id', $student->id)->update(['photo_path' => 'student-photos/x.jpg']);

        // Historical data is never deleted (§ data protection) — the enrollment
        // row survives, only its status changes. Photo access must not survive
        // with it: an inactive enrollment no longer satisfies viewPhoto(), even
        // though this same teacher genuinely taught this student and still
        // teaches the class. Policy-level denial, so 403 — not 404.
        Enrollment::where('student_id', $student->id)->update(['status' => EnrollmentStatus::TransferredOut]);

        $this->actingAs($this->user)
            ->get("/students/{$student->ulid}/photo")
            ->assertForbidden();
    }

    #[Test]
    public function uploading_only_the_roster_file_shows_a_preview_with_no_photos(): void
    {
        $class = $this->createClass();
        $excel = UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new RosterFixture)->build()),
        );

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", [
            'roster' => $excel,
        ]);

        $response->assertInertia(fn ($page) => $page
            ->component('roster-imports/Preview')
            ->where('rows.0.name', 'Maria Teste')
            ->where('rows.0.photo_index', null),
        );
    }

    #[Test]
    public function uploading_a_roster_and_a_photo_file_matches_them_in_the_preview(): void
    {
        $class = $this->createClass();
        $excel = UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new RosterFixture)->build()),
        );
        $photos = UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(DocxFixtureBuilder::build([
                ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ])),
        );

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", [
            'roster' => $excel,
            'photos' => $photos,
        ]);

        $response->assertInertia(fn ($page) => $page
            ->component('roster-imports/Preview')
            ->where('rows.0.name', 'Maria Teste')
            ->where('rows.0.photo_index', 0),
        );
    }

    #[Test]
    public function a_corrupted_photos_file_is_rejected_with_a_clear_error_instead_of_crashing(): void
    {
        $class = $this->createClass();
        $excel = UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new RosterFixture)->build()),
        );
        // Passes the mimes:doc,docx rule (Laravel's mimes check is MIME-type
        // based, driven by the fake's declared/guessed type for the .docx
        // name — not by sniffing real zip content), but is not a valid zip
        // at all, so PhotoFileParser::parse() fails to open it and throws
        // RosterFileParseException. Before this fix that exception was
        // unguarded and bubbled up as a 500.
        $corruptedPhotos = UploadedFile::fake()->createWithContent('photos.docx', 'not actually a zip file');

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/roster-imports", [
                'roster' => $excel,
                'photos' => $corruptedPhotos,
            ])
            ->assertSessionHasErrors('photos');
    }

    #[Test]
    public function a_pdf_upload_is_rejected_with_a_clear_error(): void
    {
        $class = $this->createClass();
        $pdf = UploadedFile::fake()->create('roster.pdf', 10, 'application/pdf');

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/roster-imports", ['roster' => $pdf])
            ->assertSessionHasErrors('roster');
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_class_cannot_import(): void
    {
        $class = $this->createClass();

        // A brand-new user with no relationship at all to $this->user's
        // organization. Like a_teacher_who_does_not_teach_the_student_cannot_see_the_photo()
        // above, this is denied before SchoolClassPolicy::update() ever runs:
        // the tenant-scoped route-model-binding for {class} can't find the row
        // under the stranger's own (different) organization, so Laravel 404s at
        // binding time. It exercises organization isolation, not the Policy's
        // "same school, wrong teacher" logic — there is no same-organization
        // variant of this test here (unlike the photo tests) because Task 8
        // does not add one; a future task should if that distinction ever needs
        // its own coverage for imports.
        $stranger = User::factory()->create();

        $excel = UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new RosterFixture)->build()),
        );

        $this->actingAs($stranger)
            ->post("/classes/{$class->ulid}/roster-imports", ['roster' => $excel])
            ->assertNotFound();
    }
}
