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
use App\Services\StudentEnrollmentService;
use App\Support\Import\RosterImportTempStorage;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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

    /**
     * @return array{token: string, rows: array<int, array<string, mixed>>}
     */
    protected function uploadRosterOnly(SchoolClass $class): array
    {
        $excel = UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new RosterFixture)->build()),
        );

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", [
            'roster' => $excel,
        ]);

        $token = null;
        $rows = null;
        $response->assertInertia(function ($page) use (&$token, &$rows) {
            $token = $page->toArray()['props']['token'];
            $rows = $page->toArray()['props']['rows'];

            return $page->component('roster-imports/Preview');
        });

        return ['token' => $token, 'rows' => $rows];
    }

    #[Test]
    public function uploading_only_the_roster_then_attaching_photos_matches_them_in_the_preview(): void
    {
        $class = $this->createClass();
        $uploaded = $this->uploadRosterOnly($class);

        $photos = UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(DocxFixtureBuilder::build([
                ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ])),
        );

        $response = $this->actingAs($this->user)->post(
            "/classes/{$class->ulid}/roster-imports/{$uploaded['token']}/photos",
            ['photos' => $photos, 'rows' => $uploaded['rows']],
        );

        $response->assertInertia(fn ($page) => $page
            ->component('roster-imports/Preview')
            ->where('rows.0.name', 'Maria Teste')
            ->where('rows.0.photo_index', 0),
        );
    }

    #[Test]
    public function attaching_photos_gives_the_preview_every_parsed_photo_even_ones_that_did_not_auto_match_a_row(): void
    {
        $class = $this->createClass();
        $uploaded = $this->uploadRosterOnly($class);

        $photos = UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(DocxFixtureBuilder::build([
                ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
                ['name' => 'Nome Que Não Está No Excel', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ])),
        );

        $response = $this->actingAs($this->user)->post(
            "/classes/{$class->ulid}/roster-imports/{$uploaded['token']}/photos",
            ['photos' => $photos, 'rows' => $uploaded['rows']],
        );

        // Two photos were parsed, but only the first ("Maria Teste") matches a
        // roster row by name. The teacher must still be offered the second,
        // unmatched photo to manually assign to whichever row it belongs to
        // (finding 5) — so `photos` must list every parsed entry, not just
        // the ones that auto-matched.
        $response->assertInertia(fn ($page) => $page
            ->component('roster-imports/Preview')
            ->has('photos', 2)
            ->where('photos.0.index', 0)
            ->where('photos.0.extension', 'jpg')
            ->where('photos.1.index', 1)
            ->where('photos.1.extension', 'jpg')
            ->where('rows.0.photo_index', 0),
        );
    }

    #[Test]
    public function attaching_photos_matches_against_the_clients_edited_names_not_the_original_ones(): void
    {
        // Proves the matching in attachPhotos() runs against the CURRENT
        // client-supplied row names (i.e. whatever the teacher has already
        // edited on the preview page), not a stale server-side copy of the
        // roster. If it matched by the ORIGINAL "Maria Teste" instead, this
        // row would incorrectly receive the photo.
        $class = $this->createClass();
        $uploaded = $this->uploadRosterOnly($class);

        $editedRows = $uploaded['rows'];
        $editedRows[0]['name'] = 'Nome Totalmente Diferente';

        $photos = UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(DocxFixtureBuilder::build([
                ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ])),
        );

        $response = $this->actingAs($this->user)->post(
            "/classes/{$class->ulid}/roster-imports/{$uploaded['token']}/photos",
            ['photos' => $photos, 'rows' => $editedRows],
        );

        $response->assertInertia(fn ($page) => $page
            ->component('roster-imports/Preview')
            ->where('rows.0.name', 'Nome Totalmente Diferente')
            ->where('rows.0.photo_index', null),
        );
    }

    #[Test]
    public function a_corrupted_photos_file_at_attach_photos_is_rejected_with_a_clear_error_instead_of_crashing(): void
    {
        $class = $this->createClass();
        $uploaded = $this->uploadRosterOnly($class);

        // Passes the mimes:doc,docx rule (Laravel's mimes check is MIME-type
        // based, driven by the fake's declared/guessed type for the .docx
        // name — not by sniffing real zip content), but is not a valid zip
        // at all, so PhotoFileParser::parse() fails to open it and throws
        // RosterFileParseException. Before this fix that exception was
        // unguarded and bubbled up as a 500.
        $corruptedPhotos = UploadedFile::fake()->createWithContent('photos.docx', 'not actually a zip file');

        $this->actingAs($this->user)
            ->post(
                "/classes/{$class->ulid}/roster-imports/{$uploaded['token']}/photos",
                ['photos' => $corruptedPhotos, 'rows' => $uploaded['rows']],
            )
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

    #[Test]
    public function confirming_creates_an_enrollment_per_included_row_with_its_photo(): void
    {
        $class = $this->createClass();

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/".Str::uuid().'/confirm', [
            'rows' => [
                [
                    'name' => 'Maria Teste',
                    'class_number' => 1,
                    'birth_date' => '2013-05-04',
                    'situation_code' => 'X',
                    'note' => 'ASE: B',
                    'process_number' => '1001',
                    'photo_index' => null,
                    'photo_extension' => null,
                    'include' => true,
                ],
                [
                    'name' => 'Excluído Este',
                    'class_number' => 2,
                    'birth_date' => null,
                    'situation_code' => 'X',
                    'note' => null,
                    'process_number' => null,
                    'photo_index' => null,
                    'photo_extension' => null,
                    'include' => false,
                ],
            ],
        ]);

        $response->assertRedirect("/classes/{$class->ulid}");
        $this->assertSame(1, $class->enrollments()->count());

        $identity = StudentIdentity::firstOrFail();
        $this->assertSame('Maria Teste', $identity->display_name);
        $this->assertSame('2013-05-04', $identity->birth_date->toDateString());
        $this->assertSame('1001', $identity->school_number);

        $enrollment = $class->enrollments()->firstOrFail();
        $this->assertSame('ASE: B', $enrollment->import_note);
        $this->assertSame(1, $enrollment->class_number);
    }

    #[Test]
    public function confirming_moves_a_matched_photo_from_temp_to_permanent_storage_and_cleans_up(): void
    {
        $class = $this->createClass();
        $storage = app(RosterImportTempStorage::class);
        $token = $storage->newToken();
        $tempPath = $storage->storePhoto($token, 0, 'fake-photo-bytes', 'jpg');

        $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/{$token}/confirm", [
            'rows' => [[
                'name' => 'Maria Teste',
                'class_number' => 1,
                'birth_date' => null,
                'situation_code' => 'X',
                'note' => null,
                'photo_index' => 0,
                'photo_extension' => 'jpg',
                'include' => true,
            ]],
        ]);

        $identity = StudentIdentity::firstOrFail();
        $this->assertNotNull($identity->photo_path);
        Storage::disk('local')->assertExists($identity->photo_path);
        $this->assertSame('fake-photo-bytes', Storage::disk('local')->get($identity->photo_path));

        // The whole temp token folder is gone, not just the one file.
        Storage::disk('local')->assertMissing($tempPath);
        Storage::disk('local')->assertDirectoryEmpty("roster-imports/{$token}");
    }

    #[Test]
    public function an_enrollment_that_fails_after_its_photo_was_written_leaves_no_orphan_file(): void
    {
        $class = $this->createClass();
        $storage = app(RosterImportTempStorage::class);
        $token = $storage->newToken();
        $storage->storePhoto($token, 0, 'fake-photo-bytes', 'jpg');

        // A real failure inside enrollNew(), raised where a database error
        // would surface. Its own transaction rolls the rows back, so nothing
        // ends up pointing at the photo already written to permanent storage.
        StudentIdentity::creating(function (): void {
            throw new RuntimeException('database unavailable');
        });

        try {
            $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/{$token}/confirm", [
                'rows' => [[
                    'name' => 'Maria Teste',
                    'class_number' => 1,
                    'birth_date' => null,
                    'situation_code' => 'X',
                    'note' => null,
                    'photo_index' => 0,
                    'photo_extension' => 'jpg',
                    'include' => true,
                ]],
            ]);
        } catch (RuntimeException) {
            // The failure propagates so the teacher sees it — that is intended.
        }

        // Nothing was enrolled...
        $this->assertSame(0, Enrollment::withoutGlobalScopes()->count());
        $this->assertSame(0, StudentIdentity::withoutGlobalScopes()->count());

        // ...and the permanent photo written for that row was compensated away
        // rather than left behind for nobody.
        $this->assertSame([], Storage::disk('local')->files('student-photos'));
    }

    #[Test]
    public function a_validation_error_on_confirm_does_not_delete_the_temp_photos(): void
    {
        $class = $this->createClass();
        $uploaded = $this->uploadRosterOnly($class);
        $token = $uploaded['token'];

        $photos = UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(DocxFixtureBuilder::build([
                ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ])),
        );

        $this->actingAs($this->user)->post(
            "/classes/{$class->ulid}/roster-imports/{$token}/photos",
            ['photos' => $photos, 'rows' => $uploaded['rows']],
        )->assertInertia(fn ($page) => $page->component('roster-imports/Preview'));

        $photoPath = "roster-imports/{$token}/0.jpg";
        Storage::disk('local')->assertExists($photoPath);

        // An empty name fails the confirm() validation (rows.*.name is
        // required) BEFORE any row is processed. Before the fix, the whole
        // method body — including Gate::authorize() and validate() — sat
        // inside the try/finally that deletes the temp folder, so a
        // validation failure here silently destroyed the still-needed photo.
        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/{$token}/confirm", [
            'rows' => [[
                'name' => '',
                'class_number' => 1,
                'birth_date' => null,
                'situation_code' => 'X',
                'note' => null,
                'photo_index' => 0,
                'photo_extension' => 'jpg',
                'include' => true,
            ]],
        ]);

        $response->assertSessionHasErrors('rows.0.name');

        // The whole point of this test: the temp photo must still be there,
        // so a corrected resubmission can still find it.
        Storage::disk('local')->assertExists($photoPath);
    }

    #[Test]
    public function a_photo_index_with_no_file_staged_under_this_token_enrolls_without_a_photo(): void
    {
        // There is no longer a client-supplied path at all: confirm() only
        // ever accepts a bare photo_index/photo_extension pair and always
        // rebuilds "roster-imports/{token}/{index}.{extension}" itself using
        // the route's own $token. So a REAL photo staged under a genuinely
        // different token can never be reached from this request — its name
        // never appears anywhere in the payload. This test proves that:
        // even though another token's photo exists on disk at the very same
        // index/extension this request asks for, nothing here can resolve to
        // it, because the path is never derived from anything but THIS
        // confirm request's own token.
        $class = $this->createClass();
        $storage = app(RosterImportTempStorage::class);

        $otherToken = $storage->newToken();
        $storage->storePhoto($otherToken, 0, 'someone-elses-photo-bytes', 'jpg');

        $thisToken = $storage->newToken();

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/{$thisToken}/confirm", [
            'rows' => [[
                'name' => 'Maria Teste',
                'class_number' => 1,
                'birth_date' => null,
                'situation_code' => 'X',
                'note' => null,
                'photo_index' => 0,
                'photo_extension' => 'jpg',
                'include' => true,
            ]],
        ]);

        $response->assertRedirect("/classes/{$class->ulid}");

        // No file was ever staged under $thisToken, so the reconstructed path
        // ("roster-imports/{$thisToken}/0.jpg") does not exist on disk. The
        // row still enrolls — it just does not get a photo, exactly as if
        // photo_index had been null. The other token's real photo is never
        // touched.
        $identity = StudentIdentity::firstOrFail();
        $this->assertNull($identity->photo_path);
        Storage::disk('local')->assertExists("roster-imports/{$otherToken}/0.jpg");
    }

    #[Test]
    public function a_photo_extension_containing_a_path_separator_or_traversal_sequence_fails_validation(): void
    {
        // photo_extension is validated against an allowlist regex
        // (alphanumeric only), not a denylist of specific bad substrings —
        // so this is not "we blocked the traversal sequence we thought of",
        // it is "nothing but [a-zA-Z0-9] can ever reach the string that gets
        // concatenated into a filesystem path".
        $class = $this->createClass();

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/".Str::uuid().'/confirm', [
            'rows' => [[
                'name' => 'Maria Teste',
                'class_number' => 1,
                'birth_date' => null,
                'situation_code' => 'X',
                'note' => null,
                'photo_index' => 0,
                'photo_extension' => '../../../etc/passwd',
                'include' => true,
            ]],
        ]);

        $response->assertSessionHasErrors('rows.0.photo_extension');
        $this->assertSame(0, StudentIdentity::count());
    }

    #[Test]
    public function a_photo_index_without_its_matching_photo_extension_fails_validation(): void
    {
        // Both fields must arrive together or not at all — a lone photo_index
        // (or a lone photo_extension) can never make it into the row
        // processing loop, so there is no half-supplied state to reason
        // about there.
        $class = $this->createClass();

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/".Str::uuid().'/confirm', [
            'rows' => [[
                'name' => 'Maria Teste',
                'class_number' => 1,
                'birth_date' => null,
                'situation_code' => 'X',
                'note' => null,
                'photo_index' => 0,
                'photo_extension' => null,
                'include' => true,
            ]],
        ]);

        $response->assertSessionHasErrors('rows.0.photo_extension');
        $this->assertSame(0, StudentIdentity::count());
    }

    #[Test]
    public function an_unrecognized_situation_code_still_enrolls_as_active(): void
    {
        $class = $this->createClass();

        $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/".Str::uuid().'/confirm', [
            'rows' => [[
                'name' => 'Maria Teste',
                'class_number' => 1,
                'birth_date' => null,
                'situation_code' => 'ZZ',
                'note' => null,
                'photo_index' => null,
                'photo_extension' => null,
                'include' => true,
            ]],
        ]);

        $enrollment = $class->enrollments()->firstOrFail();
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
    }

    #[Test]
    public function the_temp_folder_is_deleted_even_when_a_row_throws_partway_through_the_loop(): void
    {
        $class = $this->createClass();
        $storage = app(RosterImportTempStorage::class);
        $token = $storage->newToken();

        // No real-world input can currently make enrollNew() throw here: every
        // field the loop passes it is already constrained by confirm()'s own
        // validation rules, and the enrollments/students unique constraints
        // are re-checked (fresh student per row) or MySQL/MariaDB-only CHECK
        // constraints the SQLite test database never enforces (see addCheck()
        // in create_classes_and_students_tables). So this binds a fake service
        // that behaves exactly like the real one except it deliberately throws
        // for one row, to exercise the try/finally cleanup guarantee under a
        // genuine thrown exception rather than merely reading the code.
        $this->app->bind(StudentEnrollmentService::class, function ($app) {
            return new class($app->make(CurrentOrganization::class)) extends StudentEnrollmentService
            {
                public function enrollNew(SchoolClass $class, array $data): Enrollment
                {
                    if ($data['name'] === 'Explode') {
                        throw new RuntimeException('Synthetic failure for the temp-folder-cleanup test.');
                    }

                    return parent::enrollNew($class, $data);
                }
            };
        });

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports/{$token}/confirm", [
            'rows' => [
                [
                    'name' => 'Maria Teste',
                    'class_number' => 1,
                    'birth_date' => null,
                    'situation_code' => 'X',
                    'note' => null,
                    'photo_index' => null,
                    'photo_extension' => null,
                    'include' => true,
                ],
                [
                    'name' => 'Explode',
                    'class_number' => 2,
                    'birth_date' => null,
                    'situation_code' => 'X',
                    'note' => null,
                    'photo_index' => null,
                    'photo_extension' => null,
                    'include' => true,
                ],
            ],
        ]);

        // The request itself fails (the exception propagates, it is not swallowed)...
        $response->assertServerError();

        // ...but the row processed before the throw stays enrolled: partial
        // success is the accepted, deliberate behavior here, not a bug.
        $this->assertSame(1, $class->enrollments()->count());

        // The whole point of this test: cleanup happened anyway.
        Storage::disk('local')->assertDirectoryEmpty("roster-imports/{$token}");
    }
}
