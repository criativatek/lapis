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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §38 ("Rever tabela reconhecida") and §39 (manual correction) at the HTTP
 * boundary: `show_structural_step` is the ONE trigger the dialog reads (see
 * CharacterisationImportController::preview()'s own comment for the rule),
 * `structural` carries the whole recognised table — including what got
 * classified Group/Legend and dropped — and a corrected table resubmitted
 * through `extracted_table` re-derives the preview from the CORRECTION,
 * never the original guess, and is never sent back through the structural
 * step a second time.
 */
class CharacterisationStructuralReviewTest extends TestCase
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
    private function ocrTable(): array
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
                        ['text' => 'Medidas', 'row' => 0, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.95],
                    ],
                ],
                [
                    'index' => 1,
                    'kind' => 'unknown',
                    'cells' => [
                        ['text' => 'Maria Santos', 'row' => 1, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.91],
                        ['text' => 'MU', 'row' => 1, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.6],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function an_ocr_source_always_shows_the_structural_step(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->ocrTable()),
        ]);

        $response->assertOk()
            ->assertJsonPath('show_structural_step', true)
            ->assertJsonPath('structural.headers', ['Nome', 'Medidas'])
            // The header row plus the one data row — the whole table, in order.
            ->assertJsonCount(2, 'structural.rows')
            ->assertJsonPath('structural.rows.0.kind', 'header')
            ->assertJsonPath('structural.rows.1.kind', 'data');
    }

    #[Test]
    public function a_plain_paste_never_shows_the_structural_step(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_text' => "Nome\tMedidas\nMaria Santos\tMU",
        ]);

        $response->assertOk()->assertJsonPath('show_structural_step', false);
    }

    #[Test]
    public function a_corrected_table_resubmission_reclassifies_by_the_corrected_kinds_and_never_loops_back(): void
    {
        // Round 1: the raw OCR guess — as sent by extractTableFromImage().
        $first = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->ocrTable()),
        ]);
        $first->assertOk()->assertJsonPath('show_structural_step', true);

        // Round 2: the teacher corrected João's row back in (the OCR had
        // missed him entirely — this test instead exercises the OTHER
        // correction shape: she reclassifies a row the auto-detector called
        // Data as a Group caption, and fixes a misread cell's text) and
        // resubmits through the SAME field, now carrying an explicit kind
        // per row and the "corrected" source variant.
        // The two accepted "corrected" variants map back to docx/pasted_html
        // only — OCR corrections keep resubmitting as pasted_image/image_upload
        // (see the controller's own comment). This test corrects an OCR
        // table, so it stays pasted_image, but now with explicit kinds.
        $correctedTable = [
            'source_type' => 'pasted_image',
            'source_filename' => null,
            'warnings' => [],
            'extraction_confidence' => 0.9,
            'rows' => [
                [
                    'index' => 0,
                    'kind' => 'header',
                    'cells' => [
                        ['text' => 'Nome', 'row' => 0, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.98],
                        ['text' => 'Medidas', 'row' => 0, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.95],
                    ],
                ],
                [
                    'index' => 1,
                    'kind' => 'data',
                    'cells' => [
                        // The teacher fixed the misread token: "ACN5" -> "MU".
                        ['text' => 'Maria Santos', 'row' => 1, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.91],
                        ['text' => 'MU', 'row' => 1, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.6],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($correctedTable),
        ]);

        $response->assertOk()
            ->assertJsonPath('show_structural_step', false)
            ->assertJsonCount(1, 'preview.rows')
            ->assertJsonPath('preview.rows.0.raw_name', 'Maria Santos');
    }

    #[Test]
    public function an_explicit_group_row_in_a_correction_is_dropped_and_never_reaches_the_preview(): void
    {
        $correctedTable = [
            'source_type' => 'pasted_image',
            'source_filename' => null,
            'warnings' => [],
            'extraction_confidence' => 0.9,
            'rows' => [
                [
                    'index' => 0,
                    'kind' => 'header',
                    'cells' => [
                        ['text' => 'Nome', 'row' => 0, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.98],
                        ['text' => 'Medidas', 'row' => 0, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.95],
                    ],
                ],
                [
                    'index' => 1,
                    // The teacher marked this a Group caption in the review
                    // step, even though nothing about its shape would have
                    // triggered the automatic detector.
                    'kind' => 'group',
                    'cells' => [
                        ['text' => 'Turma Piloto', 'row' => 1, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.7],
                        ['text' => '', 'row' => 1, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => null],
                    ],
                ],
                [
                    'index' => 2,
                    'kind' => 'data',
                    'cells' => [
                        ['text' => 'Maria Santos', 'row' => 2, 'column' => 0, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.91],
                        ['text' => 'MU', 'row' => 2, 'column' => 1, 'colspan' => 1, 'rowspan' => 1, 'confidence' => 0.6],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($correctedTable),
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'preview.rows')
            ->assertJsonPath('preview.rows.0.raw_name', 'Maria Santos');
    }

    #[Test]
    public function a_bare_docx_source_type_is_still_refused_from_client_json(): void
    {
        // §39's CorrectedDocx/CorrectedPastedHtml variants exist precisely so
        // this stays refused — a correction never gets to claim it IS a
        // genuine server-side .docx read.
        $table = $this->ocrTable();
        $table['source_type'] = 'docx';

        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($table),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['extracted_table']);
    }

    /**
     * F2 (second adversarial review): a plain .xlsx upload used to skip the
     * structural step outright — `show_structural_step` excluded 'xlsx' from
     * its condition entirely, on the (wrong) theory that a spreadsheet has
     * no OCR/parsing ambiguity. But an .xlsx CAN carry merged cells, and a
     * left "spine" column merged down the sheet is exactly the shape that
     * could fold a real student into the header (see
     * NormaliseExtractedTable's own headerLevels() comment). Without the
     * step, a teacher had no way back for a row silently swallowed that
     * way. Now .xlsx shows the step whenever the source actually HAD a
     * merged cell — the same `hadMergedCells` signal pasted HTML already
     * uses.
     */
    #[Test]
    public function an_xlsx_with_a_merged_cell_shows_the_structural_step(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'carac').'.xlsx';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Turma A', 'Aluno', 'RTP/PEI', 'Apoio'],
            [null, 'Maria Santos', 'PEI', 'Terapia da fala'],
            [null, 'João Pinto', 'RTP', '—'],
        ]);
        $sheet->mergeCells('A1:A3');
        (new Xlsx($spreadsheet))->save($path);

        $response = $this->actingAs($this->user)->post($this->previewUrl(), [
            'file' => new UploadedFile($path, 'caracterizacao.xlsx', null, null, true),
        ], ['Accept' => 'application/json']);

        @unlink($path);

        $response->assertOk()
            ->assertJsonPath('show_structural_step', true)
            ->assertJsonPath('structural.headers', ['Turma A', 'Aluno', 'RTP/PEI', 'Apoio']);
    }

    /**
     * The counterpart: an ordinary .xlsx WITHOUT any merged cell never shows
     * the step — forcing every plain spreadsheet through an extra click
     * would be the "spreadsheet, not a minimum" this scope explicitly warns
     * against.
     */
    #[Test]
    public function a_plain_xlsx_without_merged_cells_never_shows_the_structural_step(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'carac').'.xlsx';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Nome', 'Medidas'],
            ['Maria Santos', 'MU'],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        $response = $this->actingAs($this->user)->post($this->previewUrl(), [
            'file' => new UploadedFile($path, 'caracterizacao.xlsx', null, null, true),
        ], ['Accept' => 'application/json']);

        @unlink($path);

        $response->assertOk()->assertJsonPath('show_structural_step', false);
    }
}
