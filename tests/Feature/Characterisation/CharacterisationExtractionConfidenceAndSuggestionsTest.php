<?php

namespace Tests\Feature\Characterisation;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §18/§19 of the per-student preview step:
 *
 *  - EXTRACTION confidence ("did I read this cell right?") now travels
 *    through to `measures`/`resources`/`unresolved` — present for an OCR
 *    source, null for every other one.
 *  - A low-confidence, unrecognised OCR token with a plausible near-miss in
 *    AcronymDictionary gets an explicit `suggested_correction` — never
 *    applied by default, and accepting one re-runs the normal resolver
 *    rather than bypassing it.
 */
class CharacterisationExtractionConfidenceAndSuggestionsTest extends TestCase
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
     * An OCR table with a single row, whose "Medidas" cell reads "ACN5" — a
     * plausible misread of ACNS — at a LOW extraction confidence.
     *
     * @return array<string, mixed>
     */
    private function ocrTableWithLowConfidenceToken(string $measureText, float $confidence): array
    {
        return [
            'source_type' => 'pasted_image',
            'source_filename' => null,
            'warnings' => [],
            'extraction_confidence' => $confidence,
            'rows' => [
                [
                    'index' => 0,
                    'kind' => 'header',
                    'cells' => [
                        ['text' => 'Nome', 'row' => 0, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.98],
                        ['text' => 'Medidas', 'row' => 0, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.98],
                    ],
                ],
                [
                    'index' => 1,
                    'kind' => 'data',
                    'cells' => [
                        ['text' => 'Maria Santos', 'row' => 1, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.95],
                        ['text' => $measureText, 'row' => 1, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => $confidence],
                    ],
                ],
            ],
        ];
    }

    private function measureRow(TestResponse $response): array
    {
        $row = collect($response->json('preview.rows'))->firstWhere('raw_name', 'Maria Santos');

        $this->assertNotNull($row);

        return $row;
    }

    #[Test]
    public function a_low_confidence_ocr_token_with_a_near_miss_gets_a_suggestion_and_stays_unchanged(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->ocrTableWithLowConfidenceToken('ACN5', 0.4)),
        ]);

        $response->assertOk();

        $row = $this->measureRow($response);

        $this->assertCount(0, $row['measures'], 'ACN5 must not resolve on its own — it stays unresolved until accepted.');
        $this->assertCount(1, $row['unresolved']);

        $unresolved = $row['unresolved'][0];
        $this->assertSame('ACN5', $unresolved['raw_token'], 'The original token is untouched by the suggestion.');
        $this->assertSame(0.4, $unresolved['extraction_confidence']);
        $this->assertNotNull($unresolved['suggested_correction']);
        $this->assertSame('ACNS', $unresolved['suggested_correction']['token']);
    }

    #[Test]
    public function accepting_the_suggestion_resolves_through_the_normal_resolver(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->ocrTableWithLowConfidenceToken('ACN5', 0.4)),
            'corrections' => ['ACN5' => 'ACNS'],
        ]);

        $response->assertOk();

        $row = $this->measureRow($response);

        // Went through LegalCodeResolver exactly as a hand-typed "ACNS"
        // would — a recognised, storable measure — never a client-side
        // shortcut that marks the suggestion "accepted" without resolving.
        $this->assertCount(1, $row['measures']);
        $this->assertSame('non_significant_curricular_adaptation', $row['measures'][0]['code']);
        $this->assertCount(0, $row['unresolved']);
    }

    #[Test]
    public function declining_leaves_the_original_token_and_its_unresolved_state(): void
    {
        // No `corrections` sent — the equivalent of never clicking "Aceitar".
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->ocrTableWithLowConfidenceToken('ACN5', 0.4)),
        ]);

        $response->assertOk();

        $row = $this->measureRow($response);

        $this->assertCount(0, $row['measures']);
        $this->assertCount(1, $row['unresolved']);
        $this->assertSame('ACN5', $row['unresolved'][0]['raw_token']);
    }

    #[Test]
    public function a_non_ocr_source_gets_no_suggestion_even_for_a_near_miss_shaped_token(): void
    {
        // Pasted plain text — read EXACTLY, never OCR — carrying the exact
        // same "ACN5" text. The school's file (or paste) literally says
        // ACN5, and that is a different fact from a misread pixel: no
        // suggestion, no extraction confidence at all.
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_text' => "Nome\tMedidas\nMaria Santos\tACN5",
        ]);

        $response->assertOk();

        $row = $this->measureRow($response);

        $this->assertCount(1, $row['unresolved']);
        $unresolved = $row['unresolved'][0];
        $this->assertSame('ACN5', $unresolved['raw_token']);
        $this->assertNull($unresolved['extraction_confidence']);
        $this->assertNull($unresolved['suggested_correction']);
    }

    #[Test]
    public function a_token_with_no_plausible_candidate_gets_no_suggestion(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->ocrTableWithLowConfidenceToken('ZZQXW', 0.3)),
        ]);

        $response->assertOk();

        $row = $this->measureRow($response);

        $this->assertCount(1, $row['unresolved']);
        $this->assertNull($row['unresolved'][0]['suggested_correction']);
    }

    #[Test]
    public function a_high_confidence_unrecognised_ocr_token_gets_no_suggestion(): void
    {
        // Read confidently by OCR: whatever the token is, it is not a
        // misread, so no correction is offered even if a near-miss exists.
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->ocrTableWithLowConfidenceToken('ACN5', 0.95)),
        ]);

        $response->assertOk();

        $row = $this->measureRow($response);

        $this->assertCount(1, $row['unresolved']);
        $this->assertSame(0.95, $row['unresolved'][0]['extraction_confidence']);
        $this->assertNull($row['unresolved'][0]['suggested_correction']);
    }
}
