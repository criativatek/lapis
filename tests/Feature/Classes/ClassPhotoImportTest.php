<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DocxFixtureBuilder;
use Tests\TestCase;

class ClassPhotoImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    protected function createClass(): SchoolClass
    {
        $context = app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn () => [
            'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())->create([
                'starts_on' => '2026-09-14',
                'ends_on' => '2027-06-30',
            ])->id,
            'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
        ]);

        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')->firstOrFail();
    }

    protected function enroll(SchoolClass $class, string $name): void
    {
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", [
            'name' => $name,
            'enrolled_on' => '2026-09-14',
        ]);
    }

    #[Test]
    public function photos_can_be_attached_to_students_already_enrolled_in_a_class(): void
    {
        $class = $this->createClass();
        $this->enroll($class, 'Maria Teste');
        $this->enroll($class, 'João Silva');
        $photos = UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(DocxFixtureBuilder::build([
                ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
                ['name' => 'João Silva', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ])),
        );

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/photos", ['photos' => $photos])
            ->assertRedirect(route('classes.show', $class->ulid))
            ->assertSessionHas('status', '2 foto(s) associada(s).');

        $identities = StudentIdentity::query()->orderBy('id')->get();

        foreach ($identities as $identity) {
            $photoPath = $identity->photo_path;
            $this->assertNotNull($photoPath);
            Storage::disk('local')->assertExists($photoPath);
        }
    }

    #[Test]
    public function a_student_without_a_matching_photo_keeps_a_null_photo_path(): void
    {
        $class = $this->createClass();
        $this->enroll($class, 'Maria Teste');
        $this->enroll($class, 'Sem Fotografia');
        $photos = UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(DocxFixtureBuilder::build([
                ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ])),
        );

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/photos", ['photos' => $photos])
            ->assertRedirect();

        $unmatched = StudentIdentity::query()->get()->first(
            fn (StudentIdentity $identity) => $identity->display_name === 'Sem Fotografia',
        );

        $this->assertNotNull($unmatched);
        $this->assertNull($unmatched->photo_path);
    }

    #[Test]
    public function a_malformed_photo_file_returns_a_validation_error(): void
    {
        $class = $this->createClass();
        $photos = UploadedFile::fake()->createWithContent('photos.docx', 'not-a-word-file');

        $this->actingAs($this->user)
            ->from("/classes/{$class->ulid}")
            ->post("/classes/{$class->ulid}/photos", ['photos' => $photos])
            ->assertRedirect("/classes/{$class->ulid}")
            ->assertSessionHasErrors('photos');
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_cannot_attach_photos(): void
    {
        $class = $this->createClass();
        $organization = $this->user->personalOrganization();
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);
        $photos = UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(DocxFixtureBuilder::build([
                ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ])),
        );

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->post("/classes/{$class->ulid}/photos", ['photos' => $photos])
            ->assertForbidden();
    }
}
