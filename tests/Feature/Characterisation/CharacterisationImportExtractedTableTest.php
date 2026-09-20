<?php

namespace Tests\Feature\Characterisation;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `extracted_table` path: the JSON an OCR run already produced entirely
 * in-browser (see resources/js/pages/classes/partials/
 * characterisation-image-extraction.ts) and posts here instead of an image —
 * the image itself never reaches this server (§ privacy). This is CLIENT
 * JSON, so it is treated as hostile input: malformed shape, an invalid
 * source_type, or a payload past the documented row/column/text limits must
 * all be refused before an ExtractedCell object is ever built from it.
 */
class CharacterisationImportExtractedTableTest extends TestCase
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
            app(StudentEnrollmentService::class)->enrollNew($this->class, ['name' => 'Maria Santos']);
            app(StudentEnrollmentService::class)->enrollNew($this->class, ['name' => 'João Pinto']);
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

    /**
     * @return array<string, mixed>
     */
    private function validTable(): array
    {
        return [
            'source_type' => 'pasted_image',
            'source_filename' => null,
            'warnings' => [],
            'extraction_confidence' => 0.9,
            'rows' => [
                [
                    'index' => 0,
                    'kind' => 'unknown',
                    'cells' => [
                        ['text' => 'Nome', 'row' => 0, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.98],
                        ['text' => 'Necessidades', 'row' => 0, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.95],
                        ['text' => 'Notas', 'row' => 0, 'column' => 2, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.95],
                    ],
                ],
                [
                    'index' => 1,
                    'kind' => 'unknown',
                    'cells' => [
                        ['text' => 'Maria Santos', 'row' => 1, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.91],
                        ['text' => 'Precisa de apoio', 'row' => 1, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.87],
                        ['text' => '', 'row' => 1, 'column' => 2, 'colspan' => 1, 'rowspan' => 1, 'confidence' => null],
                    ],
                ],
                [
                    'index' => 2,
                    'kind' => 'unknown',
                    'cells' => [
                        ['text' => 'João Pinto', 'row' => 2, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.91],
                        // The empty cell this test cares about: a real OCR
                        // gap must reach the preview as EMPTY — never shift
                        // João's row into the next column.
                        ['text' => '', 'row' => 2, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => null],
                        ['text' => 'Falta material', 'row' => 2, 'column' => 2, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.8],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function an_ocr_recognised_table_produces_the_expected_preview(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->validTable()),
        ]);

        $response->assertOk()
            ->assertJsonPath('source_kind', 'pasted_image')
            ->assertJsonCount(2, 'preview.rows');
    }

    #[Test]
    public function the_image_upload_source_variant_is_also_accepted(): void
    {
        $table = $this->validTable();
        $table['source_type'] = 'image_upload';

        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($table),
        ]);

        $response->assertOk()->assertJsonPath('source_kind', 'image_upload');
    }

    #[Test]
    public function an_empty_cell_in_the_middle_of_a_row_is_never_shifted(): void
    {
        // Same shape a missing OCR word produces client-side (see
        // characterisation-ocr-grid.test.ts's equivalent case): João's
        // "Necessidades" cell is empty — it must stay absent from his
        // sections (an empty section is omitted, never written as '' —
        // see sectionsFor()), and NEVER pull his "Notas" text into it.
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->validTable()),
        ]);

        $response->assertOk();

        $joao = collect($response->json('preview.rows'))->firstWhere('raw_name', 'João Pinto');

        $this->assertNotNull($joao);
        $this->assertArrayNotHasKey('needs', $joao['sections']);
    }

    #[Test]
    public function an_invalid_source_type_is_refused(): void
    {
        $table = $this->validTable();
        $table['source_type'] = 'docx'; // reserved for the real .docx path, never client JSON

        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($table),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['extracted_table']);
    }

    #[Test]
    public function malformed_json_is_refused(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => '{not valid json',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['extracted_table']);
    }

    #[Test]
    public function a_table_beyond_the_row_limit_is_refused(): void
    {
        $table = $this->validTable();
        $table['rows'] = array_map(
            fn (int $index): array => [
                'index' => $index,
                'kind' => 'unknown',
                'cells' => [['text' => 'x', 'row' => $index, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.9]],
            ],
            range(0, 600),
        );

        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($table),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['extracted_table']);
    }

    #[Test]
    public function a_cell_with_text_beyond_the_length_limit_is_refused(): void
    {
        $table = $this->validTable();
        $table['rows'][1]['cells'][0]['text'] = str_repeat('a', 3000);

        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($table),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['extracted_table']);
    }

    #[Test]
    public function nothing_is_written_by_an_extracted_table_preview(): void
    {
        $before = \DB::table('enrollments')->count();

        $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->validTable()),
        ])->assertOk();

        $this->assertSame($before, \DB::table('enrollments')->count());
    }
}
