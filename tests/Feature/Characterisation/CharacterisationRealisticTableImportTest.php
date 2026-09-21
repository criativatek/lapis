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
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Characterisation\CharacterisationFixture;
use Tests\TestCase;

/**
 * Proves, END TO END — through POST classes/{class}/characterisation-imports/preview,
 * never the extractor or the classifier in isolation — that the real,
 * multi-level pedagogical characterisation table (a two-row header, «Medidas»
 * and «Apoio» each merged over several sub-columns, group captions, a legend,
 * and a free-text column that also says "medidas") produces real students,
 * classifies its columns correctly, and never turns a caption or a legend
 * line into a child.
 *
 * The dataset is CharacterisationFixture's — already built for exactly this
 * shape (see its own docblock) — rendered as a real .docx and as the HTML a
 * Word paste carries, so both the merge-aware and the merge-less code paths
 * are exercised against the SAME logical table.
 */
class CharacterisationRealisticTableImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SchoolClass $class;

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

    /**
     * The names the preview is expected to end up with. Leonor Machado —
     * CharacterisationFixture's deliberate "quiet student" with a name and
     * NOTHING else — has no content of any kind (no measure, no resource, no
     * free text) and is correctly left out by PreviewRow::hasContent(): she
     * is a student with nothing proposed to add, not a row this parser lost.
     *
     * @return list<string>
     */
    private function expectedNames(): array
    {
        return array_values(array_filter(
            CharacterisationFixture::studentNames(),
            fn (string $name) => $name !== 'Leonor Machado',
        ));
    }

    #[Test]
    public function the_realistic_docx_table_produces_the_real_students(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('realistic_').'.docx';
        CharacterisationFixture::writeDocx($path);

        try {
            $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
                'file' => new UploadedFile($path, 'caracterizacao.docx', null, null, true),
            ]);
        } finally {
            @unlink($path);
        }

        $response->assertOk()->assertJsonPath('source_kind', 'docx');

        $names = array_map(fn (array $row) => $row['raw_name'], $response->json('preview.rows'));

        $this->assertSame($this->expectedNames(), $names);
    }

    #[Test]
    public function the_word_clipboard_paste_produces_the_same_students(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => CharacterisationFixture::toWordClipboardHtml(),
        ]);

        $response->assertOk()->assertJsonPath('source_kind', 'pasted_html');

        $names = array_map(fn (array $row) => $row['raw_name'], $response->json('preview.rows'));

        $this->assertSame($this->expectedNames(), $names);
    }

    #[Test]
    public function neither_group_caption_reaches_the_preview_as_a_student(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => CharacterisationFixture::toWordClipboardHtml(),
        ]);

        $response->assertOk();

        $names = array_map(fn (array $row) => $row['raw_name'], $response->json('preview.rows'));

        $this->assertNotContains(CharacterisationFixture::groupCaptionWithRtp(), $names);
        $this->assertNotContains(CharacterisationFixture::groupCaptionWithoutRtp(), $names);
        $this->assertNotEmpty($response->json('warnings'));
    }

    #[Test]
    public function no_legend_line_reaches_the_preview_as_a_student(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => CharacterisationFixture::toWordClipboardHtml(),
        ]);

        $response->assertOk();

        $names = array_map(fn (array $row) => $row['raw_name'], $response->json('preview.rows'));

        foreach (CharacterisationFixture::legendLines() as $legendLine) {
            $this->assertNotContains($legendLine, $names);
        }
    }

    /**
     * MU/MS/MA arrive joined with the merged "Medidas" top-level cell —
     * "Medidas MU", not bare "MU" — because NormaliseExtractedTable::
     * joinHeaderLevels() concatenates every header level that carries text
     * for a column. Before this hotfix, ClassifyColumns::levelFor() only
     * ever looked up the WHOLE trimmed header in AcronymDictionary, so
     * "Medidas MU" matched nothing and every one of these three columns lost
     * its level — MU/MS/MA still classified as Measures (the "medida"
     * fragment matches), but every bare letter underneath them stayed
     * ambiguous instead of inheriting Universal/Selective/Additional.
     */
    #[Test]
    public function mu_ms_ma_classify_as_measures_with_their_own_level_even_when_the_header_is_joined(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => CharacterisationFixture::toWordClipboardHtml(),
        ]);

        $response->assertOk();

        $columns = collect($response->json('preview.columns'))->keyBy('header');

        $this->assertSame('measures', $columns['Medidas MU']['role']);
        $this->assertSame('universal', $columns['Medidas MU']['level']);

        $this->assertSame('measures', $columns['Medidas MS']['role']);
        $this->assertSame('selective', $columns['Medidas MS']['level']);

        $this->assertSame('measures', $columns['Medidas MA']['role']);
        $this->assertSame('additional', $columns['Medidas MA']['level']);
    }

    /**
     * The four "Apoio" sub-columns (P / M / Ing. / Outros) join back to
     * "Apoio P", "Apoio M", "Apoio Ing.", "Apoio Outros" — the SAME strings
     * the flat TSV header already uses (see CharacterisationFixture's own
     * docblock on why that join is deliberately symmetric) — and must
     * classify as Resources (C), never as free text and never as Unknown.
     */
    #[Test]
    public function the_four_apoio_sub_columns_classify_as_resources(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => CharacterisationFixture::toWordClipboardHtml(),
        ]);

        $response->assertOk();

        $columns = collect($response->json('preview.columns'))->keyBy('header');

        foreach (['Apoio P', 'Apoio M', 'Apoio Ing.', 'Apoio Outros'] as $header) {
            $this->assertSame('resources', $columns[$header]['role'], "Column \"{$header}\" should classify as resources.");
        }
    }

    /**
     * THE CENTRAL BUG THIS HOTFIX TARGETS: «Outras medidas/recursos /
     * Observações» classifies as Measures (it says "medidas", and Measures
     * is deliberately tested before Characterisation — see ClassifyColumns::
     * PATTERNS' own docblock). Before this fix, that meant the column's free
     * text was handed ONLY to LegalCodeResolver, which read prose like "RTP
     * (12/2020)\nRedução de turma\nMS b) Preencher ACNS" as a string of
     * unrecognised codes and never reached CharacterisationSection::Summary
     * at all. The column now feeds BOTH destinations (ClassifiedColumn::
     * $alsoFreeText): Tiago Nogueira's multiline observation must appear,
     * verbatim, in his row's `sections.summary`.
     */
    #[Test]
    public function the_dual_purpose_column_still_delivers_its_free_text_to_the_summary_section(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_html' => CharacterisationFixture::toWordClipboardHtml(),
        ]);

        $response->assertOk();

        $columns = collect($response->json('preview.columns'))->keyBy('header');
        $observationsHeader = 'Outras medidas/recursos / Observações';

        $this->assertSame('measures', $columns[$observationsHeader]['role']);
        $this->assertTrue($columns[$observationsHeader]['also_free_text']);

        $rows = collect($response->json('preview.rows'))->keyBy('raw_name');

        $this->assertSame(
            "Primeira linha da observação.\nSegunda linha da observação.",
            $rows['Tiago Nogueira']['sections']['summary'] ?? null,
        );

        $this->assertSame(
            'Observação simples sobre o aluno.',
            $rows['Mariana Fonseca']['sections']['summary'] ?? null,
        );
    }

    /**
     * A row the preview could not place on the roll ("Não encontrado") is
     * still importable: the teacher picks the real enrolment by hand in the
     * dialog (CharacterisationImportDialog.vue's manual-match selector), and
     * the STORE endpoint accepts that choice on its own merits — it does not
     * re-derive or gate on whatever RowMatch the preview produced, because
     * the preview's guess and the person's decision are not the same thing.
     * This is the end-to-end path a4f4563a's client-side fix (choosing a
     * student already includes the row) assumes exists on the server; this
     * test is the server-side half of that guarantee.
     */
    #[Test]
    public function a_not_found_row_can_be_matched_by_hand_and_confirmed(): void
    {
        $enrollment = $this->class->enrollments()->first();
        $this->assertNotNull($enrollment);

        $preview = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'pasted_text' => "Nome\tObservações\nNome Escrito de Forma Irreconhecível\tTexto qualquer.\n",
        ]);

        $preview->assertOk()->assertJsonPath('preview.rows.0.match.state', 'not_found');

        $confirm = $this->actingAs($this->user)->post(
            "/classes/{$this->class->ulid}/characterisation-imports",
            [
                'source_kind' => 'paste',
                'decisions' => [[
                    // The person's own choice — not the preview's match.
                    'enrollment_ulid' => $enrollment->ulid,
                    'sections' => ['summary' => 'Texto qualquer.'],
                ]],
            ],
        );

        $confirm->assertRedirect();

        $this->assertDatabaseHas('enrollment_characterisations', [
            'enrollment_id' => $enrollment->id,
            'summary' => 'Texto qualquer.',
        ]);
    }
}
