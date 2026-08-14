<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentPhotoService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Managing one student's photo by hand, independently of the batch import.
 *
 * The photo is personal data about a minor: it stays on the private disk, is
 * reached only through an authorized route, and is attached to the student we
 * already hold — never guessed from a name or a filename the way batch import
 * has to (§22.2).
 */
class StudentPhotoManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake(StudentPhotoService::DISK);
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

    protected function enroll(SchoolClass $class, string $name, string $enrolledOn = '2026-09-14'): Enrollment
    {
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", [
            'name' => $name,
            'enrolled_on' => $enrolledOn,
        ]);

        return Enrollment::withoutGlobalScope('organization')
            ->where('class_id', $class->id)
            ->latest('id')
            ->firstOrFail();
    }

    protected function identityOf(Enrollment $enrollment): ?StudentIdentity
    {
        return StudentIdentity::withoutGlobalScopes()
            ->where('student_id', $enrollment->student_id)
            ->first();
    }

    protected function upload(SchoolClass $class, Enrollment $enrollment, ?UploadedFile $file = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->post(
            "/classes/{$class->ulid}/students/{$enrollment->ulid}/photo",
            ['photo' => $file ?? UploadedFile::fake()->image('aluno.jpg', 300, 400)],
        );
    }

    // ------------------------------------------------------------- adding

    #[Test]
    public function a_photo_can_be_added_to_a_student_who_has_none(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->assertNull($this->identityOf($enrollment)->photo_path);

        $this->upload($class, $enrollment)->assertRedirect()->assertSessionHasNoErrors();

        $path = $this->identityOf($enrollment)->photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith(StudentPhotoService::DIRECTORY.'/', $path);
        Storage::disk(StudentPhotoService::DISK)->assertExists($path);
    }

    #[Test]
    public function a_late_entry_student_gets_a_photo_without_re_importing_the_whole_file(): void
    {
        $class = $this->createClass();

        // The priority case: enrolled by hand in November, long after the
        // Inovar photo file was generated in September.
        $enrollment = $this->enroll($class, 'Maria Antunes', '2026-11-03');
        $this->assertTrue($enrollment->is_late_entry);

        $this->upload($class, $enrollment)->assertRedirect()->assertSessionHasNoErrors();

        Storage::disk(StudentPhotoService::DISK)->assertExists($this->identityOf($enrollment)->photo_path);
        // The late-entry marker is untouched by managing a photo.
        $this->assertTrue($enrollment->fresh()->is_late_entry);
    }

    // ---------------------------------------------------------- replacing

    #[Test]
    public function replacing_a_photo_deletes_the_previous_file(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->upload($class, $enrollment, UploadedFile::fake()->image('antiga.jpg'));
        $firstPath = $this->identityOf($enrollment)->photo_path;

        $this->upload($class, $enrollment, UploadedFile::fake()->image('nova.png'))->assertRedirect();
        $secondPath = $this->identityOf($enrollment)->photo_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk(StudentPhotoService::DISK)->assertExists($secondPath);
        // No orphan left behind on disk.
        Storage::disk(StudentPhotoService::DISK)->assertMissing($firstPath);
    }

    #[Test]
    public function replacing_a_photo_preserves_the_student_the_enrollment_and_the_pseudonym(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $studentsBefore = Student::withoutGlobalScopes()->count();
        $enrollmentsBefore = Enrollment::withoutGlobalScopes()->count();
        $pseudonymBefore = Student::withoutGlobalScopes()->findOrFail($enrollment->student_id)->pseudonym_code;

        $this->upload($class, $enrollment);
        $this->upload($class, $enrollment, UploadedFile::fake()->image('nova.png'));

        $this->assertSame($studentsBefore, Student::withoutGlobalScopes()->count());
        $this->assertSame($enrollmentsBefore, Enrollment::withoutGlobalScopes()->count());
        $this->assertSame(
            $pseudonymBefore,
            Student::withoutGlobalScopes()->findOrFail($enrollment->student_id)->pseudonym_code,
        );

        $fresh = $enrollment->fresh();
        $this->assertSame($enrollment->id, $fresh->id);
        $this->assertSame($enrollment->student_id, $fresh->student_id);
    }

    #[Test]
    public function managing_the_photo_leaves_name_number_and_entry_date_alone(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->actingAs($this->user)->put("/classes/{$class->ulid}/students/{$enrollment->ulid}", [
            'name' => 'João Silva',
            'class_number' => 11,
            'enrolled_on' => '2026-09-14',
        ]);

        $this->upload($class, $enrollment);
        $this->actingAs($this->user)->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}/photo");

        $identity = $this->identityOf($enrollment);
        $this->assertSame('João Silva', $identity->display_name);
        $this->assertSame(11, $enrollment->fresh()->class_number);
        $this->assertSame('2026-09-14', $enrollment->fresh()->enrolled_on->toDateString());
    }

    // ----------------------------------------------------------- removing

    #[Test]
    public function a_photo_can_be_removed_without_touching_anything_else(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->upload($class, $enrollment);
        $path = $this->identityOf($enrollment)->photo_path;

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}/photo")
            ->assertRedirect();

        $identity = $this->identityOf($enrollment);
        $this->assertNull($identity->photo_path);
        Storage::disk(StudentPhotoService::DISK)->assertMissing($path);

        // Everything else survives.
        $this->assertNotNull($identity);
        $this->assertSame('Joao Silva', $identity->display_name);
        $this->assertNotNull(Student::withoutGlobalScopes()->find($enrollment->student_id));
        $this->assertNotNull($enrollment->fresh());
    }

    #[Test]
    public function removing_a_photo_that_does_not_exist_is_harmless(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}/photo")
            ->assertRedirect();

        $this->assertNull($this->identityOf($enrollment)->photo_path);
    }

    #[Test]
    public function the_class_page_stops_offering_a_photo_url_once_it_is_removed(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->upload($class, $enrollment);

        $this->actingAs($this->user)->get("/classes/{$class->ulid}")
            ->assertInertia(fn ($page) => $page->whereNot('students.0.photo_url', null));

        $this->actingAs($this->user)->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}/photo");

        // No dangling reference to a file that is gone.
        $this->actingAs($this->user)->get("/classes/{$class->ulid}")
            ->assertInertia(fn ($page) => $page->where('students.0.photo_url', null));
    }

    // --------------------------------------------------- no identity yet

    #[Test]
    public function a_student_without_an_identity_is_told_to_set_a_name_first(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Temporario');

        StudentIdentity::withoutGlobalScopes()->where('student_id', $enrollment->student_id)->delete();

        // photo_path lives on student_identities and display_name is NOT NULL,
        // so the schema has nowhere to put a photo yet. Refuse plainly rather
        // than fabricate a name to hang it on.
        $this->upload($class, $enrollment)->assertSessionHasErrors('photo');

        $this->assertNull($this->identityOf($enrollment));

        // And naming the student is all it takes to unblock it.
        $this->actingAs($this->user)->put("/classes/{$class->ulid}/students/{$enrollment->ulid}", [
            'name' => 'Maria Antunes',
            'enrolled_on' => '2026-09-14',
        ]);

        $this->upload($class, $enrollment)->assertSessionHasNoErrors();
        Storage::disk(StudentPhotoService::DISK)->assertExists($this->identityOf($enrollment)->photo_path);
    }

    #[Test]
    public function removing_a_photo_from_a_student_without_an_identity_does_not_error(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Temporario');

        StudentIdentity::withoutGlobalScopes()->where('student_id', $enrollment->student_id)->delete();

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}/photo")
            ->assertRedirect();
    }

    // --------------------------------------------------------- validation

    #[Test]
    public function a_file_that_is_not_an_image_is_rejected(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        // A document with an image extension: the check has to read the file,
        // not trust the name.
        $this->upload($class, $enrollment, UploadedFile::fake()->create('malicioso.jpg', 40, 'application/pdf'))
            ->assertSessionHasErrors('photo');

        $this->upload($class, $enrollment, UploadedFile::fake()->create('lista.docx', 20))
            ->assertSessionHasErrors('photo');

        // An unsupported image format is refused too.
        $this->upload($class, $enrollment, UploadedFile::fake()->image('animacao.gif'))
            ->assertSessionHasErrors('photo');

        $this->assertNull($this->identityOf($enrollment)->photo_path);
    }

    #[Test]
    public function an_oversized_image_is_rejected(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $tooBig = UploadedFile::fake()->image('enorme.jpg')->size(5121);

        $this->upload($class, $enrollment, $tooBig)->assertSessionHasErrors('photo');

        $this->assertNull($this->identityOf($enrollment)->photo_path);
    }

    #[Test]
    public function a_rejected_upload_leaves_the_existing_photo_in_place(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->upload($class, $enrollment);
        $originalPath = $this->identityOf($enrollment)->photo_path;

        $this->upload($class, $enrollment, UploadedFile::fake()->create('mau.jpg', 10, 'application/pdf'))
            ->assertSessionHasErrors('photo');

        // The good photo must survive a bad upload.
        $this->assertSame($originalPath, $this->identityOf($enrollment)->photo_path);
        Storage::disk(StudentPhotoService::DISK)->assertExists($originalPath);
    }

    // ------------------------------------- filesystem/database consistency

    #[Test]
    public function a_failure_to_persist_the_new_reference_leaves_no_orphan_and_keeps_the_old_photo(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->upload($class, $enrollment);
        $identity = $this->identityOf($enrollment);
        $originalPath = $identity->photo_path;

        // A real failure, not a mock: the save is made to throw from a model
        // event, which is exactly where a database error would surface.
        StudentIdentity::saving(function (StudentIdentity $saving): void {
            if ($saving->isDirty('photo_path') && $saving->photo_path !== null) {
                throw new RuntimeException('database unavailable');
            }
        });

        $before = Storage::disk(StudentPhotoService::DISK)->allFiles();

        try {
            app(StudentPhotoService::class)->storeUploaded(
                $identity,
                UploadedFile::fake()->image('nova.jpg'),
            );
            $this->fail('The write was expected to throw.');
        } catch (RuntimeException) {
            // expected
        }

        // The file just written was compensated away...
        $this->assertSame($before, Storage::disk(StudentPhotoService::DISK)->allFiles());

        // ...and the student still has the photo they had before.
        $this->assertSame($originalPath, $this->identityOf($enrollment)->photo_path);
        Storage::disk(StudentPhotoService::DISK)->assertExists($originalPath);
    }

    // The import-side compensation is covered where that flow lives, by
    // RosterImportTest::an_enrollment_that_fails_after_its_photo_was_written_leaves_no_orphan_file.

    #[Test]
    public function a_failed_physical_delete_does_not_resurrect_the_reference(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');
        $this->upload($class, $enrollment);

        $identity = $this->identityOf($enrollment);
        $path = $identity->photo_path;

        // Delete the file behind the service's back, so its own delete() call
        // finds nothing — the database must still end up clean.
        Storage::disk(StudentPhotoService::DISK)->delete($path);

        app(StudentPhotoService::class)->remove($identity);

        $this->assertNull($this->identityOf($enrollment)->photo_path);
    }

    // ------------------------------------------------------ authorization

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_cannot_add_replace_or_remove(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');
        $this->upload($class, $enrollment);
        $originalPath = $this->identityOf($enrollment)->photo_path;

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->user->personalOrganization(), ['joined_at' => now()]);

        $this->upload($class, $enrollment, null, as: $colleague)->assertForbidden();

        $this->actingAs($colleague)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}/photo")
            ->assertForbidden();

        $this->assertSame($originalPath, $this->identityOf($enrollment)->photo_path);
        Storage::disk(StudentPhotoService::DISK)->assertExists($originalPath);
    }

    #[Test]
    public function a_teacher_from_another_organization_cannot_reach_the_photo(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');
        $this->upload($class, $enrollment);
        $originalPath = $this->identityOf($enrollment)->photo_path;

        $outsider = User::factory()->create();

        $this->upload($class, $enrollment, null, as: $outsider)->assertNotFound();

        $this->actingAs($outsider)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}/photo")
            ->assertNotFound();

        // Nor read it through the serving route: the tenant scope stops the
        // Student from resolving at all, so it is 404 — the answer never even
        // confirms that this student exists.
        $student = Student::withoutGlobalScopes()->findOrFail($enrollment->student_id);
        $this->actingAs($outsider)->get("/students/{$student->ulid}/photo")->assertNotFound();

        $this->assertSame($originalPath, $this->identityOf($enrollment)->photo_path);
    }

    #[Test]
    public function an_enrollment_from_another_class_cannot_be_used(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');
        $otherClass = $this->createClass();

        // A real enrollment ulid of mine, pointed at another of my classes.
        $this->actingAs($this->user)->post(
            "/classes/{$otherClass->ulid}/students/{$enrollment->ulid}/photo",
            ['photo' => UploadedFile::fake()->image('aluno.jpg')],
        )->assertNotFound();

        $this->actingAs($this->user)
            ->delete("/classes/{$otherClass->ulid}/students/{$enrollment->ulid}/photo")
            ->assertNotFound();

        $this->assertNull($this->identityOf($enrollment)->photo_path);
    }

    // ------------------------------------------- thumbnails in the payload

    #[Test]
    public function the_class_roster_carries_a_photo_url_only_for_students_who_have_one(): void
    {
        $class = $this->createClass();
        $withPhoto = $this->enroll($class, 'Com Foto');
        $this->enroll($class, 'Sem Foto');
        $this->upload($class, $withPhoto);

        $this->actingAs($this->user)->get("/classes/{$class->ulid}")
            ->assertInertia(function ($page) {
                $students = collect($page->toArray()['props']['students']);
                $comFoto = $students->firstWhere('name', 'Com Foto');
                $semFoto = $students->firstWhere('name', 'Sem Foto');

                // A URL, never the image bytes, and never a filesystem path.
                $this->assertStringContainsString('/photo', $comFoto['photo_url']);
                $this->assertStringNotContainsString(StudentPhotoService::DIRECTORY, $comFoto['photo_url']);
                $this->assertStringNotContainsString('storage/app', $comFoto['photo_url']);
                $this->assertNull($semFoto['photo_url']);

                return $page;
            });
    }

    #[Test]
    public function the_photo_url_changes_when_the_photo_is_replaced_so_no_stale_image_is_cached(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->upload($class, $enrollment);
        $first = $this->photoUrlFromRoster($class);

        // updated_at has one-second resolution; move the clock so the stamp
        // provably differs rather than relying on the test being slow.
        $this->travel(2)->seconds();
        $this->upload($class, $enrollment, UploadedFile::fake()->image('nova.png'));
        $second = $this->photoUrlFromRoster($class);

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function listing_a_class_costs_the_same_number_of_queries_whatever_the_roster_size(): void
    {
        $class = $this->createClass();
        $first = $this->enroll($class, 'Aluno 1');
        $this->upload($class, $first);

        $queriesForOne = $this->countQueriesListing($class);

        foreach (range(2, 6) as $index) {
            $enrollment = $this->enroll($class, "Aluno {$index}");
            $this->upload($class, $enrollment);
        }

        // photoUrl() reads the eager-loaded identity, so six students with
        // photos must cost exactly what one costs — no per-student query.
        $this->assertSame($queriesForOne, $this->countQueriesListing($class));
    }

    protected function photoUrlFromRoster(SchoolClass $class): ?string
    {
        $url = null;

        $this->actingAs($this->user)->get("/classes/{$class->ulid}")
            ->assertInertia(function ($page) use (&$url) {
                $url = $page->toArray()['props']['students'][0]['photo_url'];

                return $page;
            });

        return $url;
    }

    protected function countQueriesListing(SchoolClass $class): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->actingAs($this->user)->get("/classes/{$class->ulid}")->assertOk();

        // Stop counting: Laravel has no public "forget listeners", so the
        // closure is left in place and the counter reset by the next call.
        return $count;
    }

    // ---------------------------------------------------- private storage

    #[Test]
    public function the_photo_is_kept_on_the_private_disk_and_served_only_through_the_guarded_route(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');

        $this->upload($class, $enrollment);
        $path = $this->identityOf($enrollment)->photo_path;
        $student = Student::withoutGlobalScopes()->findOrFail($enrollment->student_id);

        // Never in the public disk, and the stored name gives nothing away
        // about the student.
        Storage::disk(StudentPhotoService::DISK)->assertExists($path);
        $this->assertStringNotContainsString('Joao', $path);
        $this->assertStringNotContainsString($student->pseudonym_code, $path);
        $this->assertStringNotContainsString('public', $path);

        // The owning teacher can read it; that is the only way in.
        $this->actingAs($this->user)->get("/students/{$student->ulid}/photo")->assertOk();
    }

    #[Test]
    public function a_guest_cannot_read_a_student_photo(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');
        $this->upload($class, $enrollment);
        $student = Student::withoutGlobalScopes()->findOrFail($enrollment->student_id);

        // actingAs persists for the rest of a test, so the session has to be
        // dropped explicitly — otherwise this would pass while still logged in.
        auth()->logout();
        session()->flush();

        $this->get("/students/{$student->ulid}/photo")->assertRedirect('/login');
    }
}
