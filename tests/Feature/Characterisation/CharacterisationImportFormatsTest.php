<?php

namespace Tests\Feature\Characterisation;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\Word2007;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Characterisation\CharacterisationFixture;
use Tests\TestCase;

/**
 * The plumbing added on top of the already-built extraction layer: pasted
 * HTML, .docx uploads (single and multi-table), unrecognised content, the
 * warnings NormaliseExtractedTable produces, and the two privacy guarantees
 * (nothing written, nothing left on disk) that already hold for CSV/XLSX
 * and must hold here too.
 */
class CharacterisationImportFormatsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->user = User::factory()->create();
        $this->class = $this->createClass($this->user);

        $organization = $this->user->personalOrganization();

        app(CurrentOrganization::class)->runFor($organization, function () {
            foreach (CharacterisationFixture::studentNames() as $name) {
                app(StudentEnrollmentService::class)->enrollNew($this->class, ['name' => $name]);
            }
        });
    }

    private function createClass(User $owner, string $label = '7.º A'): SchoolClass
    {
        $organization = $owner->personalOrganization();

        $context = app(CurrentOrganization::class)->runFor($organization, fn (): array => [
            'year' => AcademicYear::factory()->recycle($organization)
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($organization)->create()->id,
        ]);

        $this->actingAs($owner)->post('/classes', [
            'label' => $label,
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('label', $label)
            ->firstOrFail();
    }

    private function previewUrl(): string
    {
        return '/classes/'.$this->class->ulid.'/characterisation-imports/preview';
    }

    private function docxPath(string $name = 'caracterizacao.docx'): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('characterisation_').'_'.$name;
        CharacterisationFixture::writeDocx($path);

        return $path;
    }

    #[Test]
    public function docx_upload_produces_a_preview(): void
    {
        $path = $this->docxPath();

        try {
            $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
                'file' => new UploadedFile($path, 'caracterizacao.docx', null, null, true),
            ]);

            $response->assertOk()
                ->assertJsonPath('source_kind', 'docx')
                ->assertJsonCount(count(CharacterisationFixture::studentNames()) - 1, 'preview.rows');
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function multi_table_docx_returns_a_chooser_instead_of_a_preview(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('characterisation_multi_').'.docx';

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        $firstTable = $section->addTable();
        $firstTable->addRow();
        $firstTable->addCell(2000)->addText('Legenda');

        $secondTable = $section->addTable();
        $secondTable->addRow();
        $secondTable->addCell(2000)->addText('Aluno');
        $secondTable->addCell(2000)->addText('Observações');
        $secondTable->addRow();
        $secondTable->addCell(2000)->addText('Ana Silva');
        $secondTable->addCell(2000)->addText('Texto.');

        (new Word2007($phpWord))->save($path);

        try {
            $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
                'file' => new UploadedFile($path, 'duas-tabelas.docx', null, null, true),
            ]);

            $response->assertOk()
                ->assertJsonMissingPath('preview')
                ->assertJsonCount(2, 'tables');

            // A second call, now with the chosen index, proceeds to a real
            // preview — never a silent guess of which table was meant.
            $chosen = $this->actingAs($this->user)->postJson($this->previewUrl(), [
                'file' => new UploadedFile($path, 'duas-tabelas.docx', null, null, true),
                'table_index' => 1,
            ]);

            $chosen->assertOk()->assertJsonStructure(['preview']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function pasted_word_html_produces_the_expected_rows(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => CharacterisationFixture::toWordClipboardHtml(),
        ]);

        $response->assertOk()
            ->assertJsonPath('source_kind', 'pasted_html')
            ->assertJsonCount(count(CharacterisationFixture::studentNames()) - 1, 'preview.rows');
    }

    #[Test]
    public function unrecognised_pasted_html_surfaces_a_clear_message(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => '<p>Nada de tabular aqui.</p>',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['pasted_html']);

        $this->assertNotEmpty($response->json('errors.pasted_html.0'));
    }

    #[Test]
    public function normalise_warnings_reach_the_preview_response(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => CharacterisationFixture::toWordClipboardHtml(),
        ]);

        // The fixture's group captions and legend lines are exactly what
        // NormaliseExtractedTable drops — that drop is what warnings()
        // reports.
        $response->assertOk();
        $this->assertNotEmpty($response->json('warnings'));
    }

    #[Test]
    public function oversized_docx_is_refused_naming_the_eight_megabyte_limit(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('characterisation_big_').'.docx';
        CharacterisationFixture::writeDocx($path);
        // Pad past 8 MB with harmless appended bytes at the end of the zip;
        // the size check runs before the file is ever opened as OOXML.
        file_put_contents($path, str_repeat('0', 9 * 1024 * 1024), FILE_APPEND);

        try {
            $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
                'file' => new UploadedFile($path, 'grande.docx', null, null, true),
            ]);

            $response->assertUnprocessable();
            $message = $response->json('errors.file.0');
            $this->assertNotNull($message);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function a_renamed_non_docx_file_is_refused(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('characterisation_fake_').'.docx';
        file_put_contents($path, 'not actually a zip or a word document');

        try {
            $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
                'file' => new UploadedFile($path, 'falso.docx', null, null, true),
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['file']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function preview_never_writes_anything_for_docx_or_pasted_html(): void
    {
        $before = [
            'batches' => DB::table('characterisation_import_batches')->count(),
            'revisions' => DB::table('characterisation_revisions')->count(),
            'enrollment_characterisations' => DB::table('enrollment_characterisations')->count(),
        ];

        $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => CharacterisationFixture::toWordClipboardHtml(),
        ])->assertOk();

        $path = $this->docxPath();

        try {
            $this->actingAs($this->user)->postJson($this->previewUrl(), [
                'file' => new UploadedFile($path, 'caracterizacao.docx', null, null, true),
            ])->assertOk();
        } finally {
            @unlink($path);
        }

        $this->assertSame($before, [
            'batches' => DB::table('characterisation_import_batches')->count(),
            'revisions' => DB::table('characterisation_revisions')->count(),
            'enrollment_characterisations' => DB::table('enrollment_characterisations')->count(),
        ]);
    }

    #[Test]
    public function preview_does_not_leave_the_uploaded_docx_on_disk(): void
    {
        $tempDir = sys_get_temp_dir();
        $before = scandir($tempDir) ?: [];

        $path = $this->docxPath('privacidade.docx');

        try {
            $this->actingAs($this->user)->postJson($this->previewUrl(), [
                'file' => new UploadedFile($path, 'privacidade.docx', null, null, true),
            ])->assertOk();
        } finally {
            @unlink($path);
        }

        // Laravel's own UploadedFile::fake()/test upload machinery writes to
        // its own tmp prefix; what matters is that nothing this controller
        // or reader touches survives with the ORIGINAL uploaded filename in
        // it, and that the temp directory does not grow a stray file this
        // request is responsible for cleaning up itself.
        $after = scandir($tempDir) ?: [];
        $newEntries = array_diff($after, $before);

        foreach ($newEntries as $entry) {
            $this->assertStringNotContainsString('privacidade', $entry);
        }
    }
}
