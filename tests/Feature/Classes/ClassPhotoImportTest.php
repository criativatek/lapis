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
use Inertia\Support\SessionKey;
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

    /**
     * Uploads a photo file and returns the preview it produced. Writes nothing.
     *
     * @return array{token: string, rows: list<array<string, mixed>>, photos: list<array<string, mixed>>}
     */
    protected function previewPhotos(SchoolClass $class, string $docxPath): array
    {
        $photos = UploadedFile::fake()->createWithContent('photos.docx', file_get_contents($docxPath));

        $props = [];
        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/photos", ['photos' => $photos])
            ->assertInertia(function ($page) use (&$props) {
                $props = $page->toArray()['props'];

                return $page->component('roster-imports/Preview');
            });

        return ['token' => $props['token'], 'rows' => $props['rows'], 'photos' => $props['photos']];
    }

    #[Test]
    public function photos_are_previewed_against_the_students_already_enrolled_and_applied_on_confirmation(): void
    {
        $class = $this->createClass();
        $this->enroll($class, 'Maria Teste');
        $this->enroll($class, 'João Silva');

        // O UPLOAD JÁ NÃO ESCREVE NADA. Passa a devolver a mesma
        // pré-visualização do resto da importação, com uma linha por aluno já
        // inscrito — quem foi associado, quem não foi, e todas as fotos do
        // ficheiro para atribuir à mão (§6).
        $preview = $this->previewPhotos($class, DocxFixtureBuilder::build([
            ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ['name' => 'João Silva', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
        ]));

        $this->assertCount(2, $preview['rows']);

        foreach ($preview['rows'] as $row) {
            $this->assertTrue($row['already_enrolled']);
            $this->assertSame('update', $row['action']);
            $this->assertNotNull($row['photo_index']);
        }

        foreach (StudentIdentity::query()->get() as $identity) {
            $this->assertNull($identity->photo_path, 'A pré-visualização não escreve.');
        }

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/roster-imports/{$preview['token']}/confirm", ['rows' => $preview['rows']])
            ->assertRedirect(route('classes.show', $class->ulid))
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => ['type' => 'success', 'message' => '2 aluno(s) atualizado(s), 2 foto(s) associada(s).'],
            ]);

        $identities = StudentIdentity::query()->orderBy('id')->get();

        $this->assertCount(2, $identities, 'Nenhum aluno foi criado nem eliminado.');

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

        $preview = $this->previewPhotos($class, DocxFixtureBuilder::build([
            ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
        ]));

        // A linha sem foto continua lá, por atribuir — é ela que dá ao
        // professor onde clicar. O que não fica é marcada.
        $unmatchedRow = collect($preview['rows'])->firstWhere('name', 'Sem Fotografia');
        $this->assertNotNull($unmatchedRow);
        $this->assertNull($unmatchedRow['photo_index']);
        $this->assertFalse($unmatchedRow['include']);

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/roster-imports/{$preview['token']}/confirm", ['rows' => $preview['rows']])
            ->assertRedirect();

        $unmatched = StudentIdentity::query()->get()->first(
            fn (StudentIdentity $identity) => $identity->display_name === 'Sem Fotografia',
        );

        $this->assertNotNull($unmatched);
        $this->assertNull($unmatched->photo_path);
    }

    #[Test]
    public function a_photo_file_with_no_photographs_at_all_says_so_instead_of_previewing_nothing(): void
    {
        $class = $this->createClass();
        $this->enroll($class, 'Maria Teste');

        $photos = UploadedFile::fake()->createWithContent(
            'photos.docx',
            file_get_contents(DocxFixtureBuilder::build([])),
        );

        $this->actingAs($this->user)
            ->from("/classes/{$class->ulid}")
            ->post("/classes/{$class->ulid}/photos", ['photos' => $photos])
            ->assertRedirect("/classes/{$class->ulid}")
            ->assertSessionHasErrors('photos');
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
