<?php

namespace Tests\Unit\Characterisation;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Characterisation\Import\BuildCharacterisationPreview;
use App\Services\Characterisation\Import\TableGrid;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BuildCharacterisationPreview::build() directly, on hand-built grids —
 * bypassing the readers entirely so the two ways a row can vanish (§A) are
 * pinned down without depending on how ReadCharacterisationTable happens to
 * classify a caption/legend line upstream (see CharacterisationImportTest's
 * own note on that, and FormatConvergenceTest for the format-level cases).
 *
 * The bug this guards against: a data row disappearing from the response
 * with NOTHING in it saying it ever existed — "0 de 0" indistinguishable
 * from "the file was empty".
 */
class BuildCharacterisationPreviewTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        $context = app(CurrentOrganization::class)->runFor($organization, fn (): array => [
            'year' => AcademicYear::factory()->recycle($organization)
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($organization)->create()->id,
        ]);

        $this->actingAs($user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        $this->class = SchoolClass::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('label', '7.º A')
            ->firstOrFail();

        app(CurrentOrganization::class)->runFor($organization, function () {
            app(StudentEnrollmentService::class)->enrollNew($this->class, [
                'name' => 'Ana Silva',
                'school_number' => '12345',
            ]);
        });
    }

    /**
     * A header that ClassifyColumns cannot map to any content-bearing role
     * (Measures, Resources, or a CharacterisationSection) must not disappear
     * as "0 de 0" — it is a structural fact about the HEADER, and the response
     * must say so explicitly rather than let every row's hasContent() failure
     * be mistaken for "nothing was ready to import".
     */
    #[Test]
    public function a_row_with_only_unrecognised_content_columns_is_counted_and_explained(): void
    {
        $grid = new TableGrid(
            ['Aluno', 'N.º processo', 'Coluna Estranha Que Ninguém Reconhece'],
            [['Ana Silva', '12345', 'Texto qualquer aqui.']],
        );

        $preview = app(BuildCharacterisationPreview::class)->build($this->class, $grid);

        $this->assertSame([], $preview->rows);
        $this->assertSame(1, $preview->dataRowCount);
        $this->assertSame(0, $preview->footerRowCount);
        $this->assertFalse($preview->hasRecognisedContentColumn);
    }

    /**
     * A row that identifies nobody (no name, no process number — a footer or
     * a total) is legitimately skipped, but it must still be COUNTED, and
     * distinguished in the count from an unrecognised header: the two produce
     * the same empty `rows` array for very different reasons.
     */
    #[Test]
    public function a_footer_row_is_counted_separately_from_an_unrecognised_header(): void
    {
        $grid = new TableGrid(
            ['Aluno', 'N.º processo', 'Observações'],
            [['', '', 'Total Alunos - 27']],
        );

        $preview = app(BuildCharacterisationPreview::class)->build($this->class, $grid);

        $this->assertSame([], $preview->rows);
        $this->assertSame(1, $preview->dataRowCount);
        $this->assertSame(1, $preview->footerRowCount);
        $this->assertTrue($preview->hasRecognisedContentColumn);
    }

    /**
     * The ordinary, working case: a recognised content column with real text
     * reaches the preview as before — the new counters do not change what
     * gets shown, only what gets explained when nothing does.
     */
    #[Test]
    public function a_recognised_row_still_reaches_the_preview(): void
    {
        $grid = new TableGrid(
            ['Aluno', 'N.º processo', 'Observações'],
            [['Ana Silva', '12345', 'Participa bastante.']],
        );

        $preview = app(BuildCharacterisationPreview::class)->build($this->class, $grid);

        $this->assertCount(1, $preview->rows);
        $this->assertSame(1, $preview->dataRowCount);
        $this->assertSame(0, $preview->footerRowCount);
        $this->assertTrue($preview->hasRecognisedContentColumn);
    }
}
