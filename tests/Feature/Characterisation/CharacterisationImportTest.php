<?php

namespace Tests\Feature\Characterisation;

use App\Models\AcademicYear;
use App\Models\CharacterisationImportBatch;
use App\Models\CharacterisationRevision;
use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\EnrollmentCharacterisationSourceMeasure;
use App\Models\EnrollmentStatus;
use App\Models\Intervention;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Importing a characterisation from the school's own paperwork.
 *
 * Almost every test here is about something NOT happening: nothing written
 * before confirmation, no student matched on a guess, no measure invented from
 * an acronym nobody confirmed, no row landing on a student in another class.
 */
class CharacterisationImportTest extends TestCase
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

    private function enrol(User $owner, SchoolClass $class, string $name, ?string $schoolNumber = null): Enrollment
    {
        return app(CurrentOrganization::class)->runFor(
            $owner->personalOrganization(),
            fn (): Enrollment => app(StudentEnrollmentService::class)->enrollNew($class, array_filter([
                'name' => $name,
                'school_number' => $schoolNumber,
            ])),
        );
    }

    private function preview(string $pastedText, ?SchoolClass $class = null)
    {
        return $this->actingAs($this->user)->postJson(
            '/classes/'.($class ?? $this->class)->ulid.'/characterisation-imports/preview',
            ['pasted_text' => $pastedText],
        );
    }

    // ------------------------------------------------ 1. nada é gravado a ler

    /**
     * THE LOAD-BEARING TEST. Parsing is not writing, and there must be no path
     * between the two that does not pass through a person.
     */
    #[Test]
    public function previewing_writes_absolutely_nothing(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva', '12345');

        $this->preview("Nome\tN.º de processo\tMedidas\tObservações\nAna Silva\t12345\tACNS\tParticipa bastante.\n")
            ->assertOk();

        $this->assertSame(0, EnrollmentCharacterisation::withoutGlobalScope('organization')->count());
        $this->assertSame(0, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());
        $this->assertSame(0, CharacterisationImportBatch::withoutGlobalScope('organization')->count());
        $this->assertSame(0, CharacterisationRevision::withoutGlobalScope('organization')->count());
    }

    // ------------------------------------------------ 2. correspondência

    #[Test]
    public function a_row_matched_by_process_number_is_confident_and_preselected(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva', '12345');

        $response = $this->preview("Nome\tN.º de processo\tObservações\nNome Escrito Doutra Maneira\t12345\tTexto.\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.match.state', 'confident')
            ->assertJsonPath('preview.rows.0.match.matched_by', 'process_number')
            ->assertJsonPath('preview.rows.0.match.preselected', true);
    }

    #[Test]
    public function an_exact_unique_name_is_confident(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->preview("Nome\tObservações\nAna Silva\tTexto.\n")
            ->assertOk()
            ->assertJsonPath('preview.rows.0.match.state', 'confident')
            ->assertJsonPath('preview.rows.0.match.matched_by', 'name');
    }

    /**
     * «Ana Silva» inside «Ana Maria Silva» is a suggestion, and a suggestion
     * reaches the preview unticked.
     */
    #[Test]
    public function a_looser_name_match_is_possible_and_never_preselected(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Maria Silva');

        $this->preview("Nome\tObservações\nAna Silva\tTexto.\n")
            ->assertOk()
            ->assertJsonPath('preview.rows.0.match.state', 'possible')
            ->assertJsonPath('preview.rows.0.match.preselected', false);
    }

    /** Two students answering the same name is not a tie for the app to break. */
    #[Test]
    public function two_students_with_the_same_name_are_ambiguous_with_candidates(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $response = $this->preview("Nome\tObservações\nAna Silva\tTexto.\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.match.state', 'ambiguous')
            ->assertJsonPath('preview.rows.0.match.enrollment_ulid', null)
            ->assertJsonPath('preview.rows.0.match.preselected', false);

        $this->assertCount(2, $response->json('preview.rows.0.match.candidates'));
    }

    #[Test]
    public function a_student_who_is_not_on_the_roll_is_not_found_and_is_never_created(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->preview("Nome\tObservações\nZacarias Desconhecido\tTexto.\n")
            ->assertOk()
            ->assertJsonPath('preview.rows.0.match.state', 'not_found')
            ->assertJsonPath('preview.rows.0.match.enrollment_ulid', null);

        $this->assertSame(1, $this->class->enrollments()->count(), 'The import must never enrol anybody.');
    }

    // ------------------------------------------------ 3. siglas e confiança

    #[Test]
    public function a_recognised_measure_reaches_the_measures_destination(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $response = $this->preview("Nome\tMedidas\nAna Silva\tMS b) + ACNS\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.measures.0.confidence', 'recognised')
            ->assertJsonPath('preview.rows.0.measures.0.code', 'non_significant_curricular_adaptation')
            ->assertJsonPath('preview.rows.0.measures.0.storable', true);

        $this->assertSame(['b)'], $response->json('preview.rows.0.measures.0.unresolved_annotations'));
    }

    #[Test]
    public function a_bare_letter_is_ambiguous_and_lands_in_the_unrecognised_destination(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $response = $this->preview("Nome\tMedidas\nAna Silva\tb)\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.measures', [])
            ->assertJsonPath('preview.rows.0.unresolved.0.confidence', 'ambiguous')
            ->assertJsonPath('preview.rows.0.unresolved.0.storable', false);
    }

    #[Test]
    public function an_unconfirmed_acronym_is_never_given_a_meaning(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $response = $this->preview("Nome\tMedidas\nAna Silva\tRTP\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.measures', [])
            ->assertJsonPath('preview.rows.0.unresolved.0.confidence', 'unrecognised')
            ->assertJsonPath('preview.rows.0.unresolved.0.code', null);
    }

    /**
     * A column header naming the level is what gives the letters under it any
     * meaning — and even then, only the level, never the measure.
     */
    #[Test]
    public function a_column_header_supplies_the_level_but_still_not_the_measure(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $response = $this->preview("Nome\tMedidas universais\nAna Silva\tb)\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.unresolved.0.confidence', 'ambiguous')
            ->assertJsonPath('preview.rows.0.unresolved.0.level', 'universal')
            ->assertJsonPath('preview.rows.0.unresolved.0.code', null);
    }

    // ------------------------------------------------ 4. destinos separados

    #[Test]
    public function the_destinations_are_kept_apart(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $response = $this->preview(
            "Nome\tMedidas\tPotencialidades\tInteresses\n".
            "Ana Silva\tACNS; RTP\tTrabalha bem em grupo.\tMúsica.\n"
        );

        $response->assertOk();

        // (A) characterisation, split by the column it came from
        $this->assertSame('Trabalha bem em grupo.', $response->json('preview.rows.0.sections.strengths'));
        $this->assertSame('Música.', $response->json('preview.rows.0.sections.interests'));

        // (B) one recognised measure
        $this->assertCount(1, $response->json('preview.rows.0.measures'));

        // (D) the acronym nobody confirmed, kept out of everything storable
        $this->assertCount(1, $response->json('preview.rows.0.unresolved'));
        $this->assertSame('RTP', $response->json('preview.rows.0.unresolved.0.raw_token'));
    }

    // ------------------------------------------------ 5. confirmação

    private function confirm(array $decisions, array $overrides = [])
    {
        return $this->actingAs($this->user)->post(
            "/classes/{$this->class->ulid}/characterisation-imports",
            array_merge([
                'source_kind' => 'paste',
                'decisions' => $decisions,
            ], $overrides),
        );
    }

    #[Test]
    public function confirming_writes_the_text_the_measures_and_the_provenance(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'sections' => ['strengths' => 'Trabalha bem em grupo.'],
            'measure_codes' => [SupportMeasureCode::NonSignificantCurricularAdaptation->value],
            'raw_tokens' => [SupportMeasureCode::NonSignificantCurricularAdaptation->value => 'MS b) + ACNS'],
        ]])->assertRedirect();

        $characterisation = EnrollmentCharacterisation::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('Trabalha bem em grupo.', $characterisation->strengths);

        $measure = EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame(SupportMeasureCode::NonSignificantCurricularAdaptation, $measure->support_measure_code);
        $this->assertSame(SupportMeasureLevel::Selective, $measure->support_measure_level);
        // What the file said survives the interpretation.
        $this->assertSame('MS b) + ACNS', $measure->raw_token);

        $batch = CharacterisationImportBatch::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('paste', $batch->source_kind);
        $this->assertSame($this->user->getKey(), $batch->confirmed_by);
        $this->assertSame($batch->getKey(), $measure->import_batch_id);

        $revision = CharacterisationRevision::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('import', $revision->source->value);
        $this->assertSame($batch->getKey(), $revision->import_batch_id);
    }

    /** An ignored row is a row that leaves no trace at all. */
    #[Test]
    public function a_row_the_teacher_left_out_is_never_written(): void
    {
        $kept = $this->enrol($this->user, $this->class, 'Ana Silva');
        $ignored = $this->enrol($this->user, $this->class, 'Bruno Costa');

        $this->confirm([[
            'enrollment_ulid' => $kept->ulid,
            'sections' => ['summary' => 'Só a Ana.'],
        ]])->assertRedirect();

        $this->assertSame(1, EnrollmentCharacterisation::withoutGlobalScope('organization')->count());
        $this->assertNull(
            EnrollmentCharacterisation::withoutGlobalScope('organization')
                ->where('enrollment_id', $ignored->getKey())
                ->first(),
        );
    }

    /** The preview is a document from a browser. The server trusts none of it. */
    #[Test]
    public function an_enrolment_from_another_class_is_dropped_rather_than_written(): void
    {
        $otherClass = $this->createClass($this->user, '7.º B');
        $foreign = $this->enrol($this->user, $otherClass, 'Bruno Costa');

        $this->confirm([[
            'enrollment_ulid' => $foreign->ulid,
            'sections' => ['summary' => 'Não devia aterrar aqui.'],
        ]])->assertRedirect();

        $this->assertSame(0, EnrollmentCharacterisation::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function an_enrolment_from_another_organization_is_dropped(): void
    {
        $stranger = User::factory()->create();
        $strangerClass = $this->createClass($stranger, '9.º Z');
        $foreign = $this->enrol($stranger, $strangerClass, 'Carla Estranha');

        $this->confirm([[
            'enrollment_ulid' => $foreign->ulid,
            'sections' => ['summary' => 'Atravessou a fronteira.'],
        ]])->assertRedirect();

        $this->assertSame(0, EnrollmentCharacterisation::withoutGlobalScope('organization')->count());
    }

    /** Only codes the catalogue actually has. An invented one writes nothing. */
    #[Test]
    public function an_unknown_measure_code_is_refused_at_the_write(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => ['medida_inventada_por_alguem'],
        ]])->assertRedirect();

        $this->assertSame(0, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function importing_the_same_measure_twice_does_not_stack_duplicates(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $decision = [[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [SupportMeasureCode::NonSignificantCurricularAdaptation->value],
            'raw_tokens' => [SupportMeasureCode::NonSignificantCurricularAdaptation->value => 'ACNS'],
        ]];

        $this->confirm($decision)->assertRedirect();
        $this->confirm($decision)->assertRedirect();

        $this->assertSame(1, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());
    }

    /** The importer never creates an intervention — that is a teacher's act. */
    #[Test]
    public function importing_a_measure_creates_no_intervention(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [SupportMeasureCode::PsychopedagogicalSupport->value],
            'raw_tokens' => [SupportMeasureCode::PsychopedagogicalSupport->value => 'Apoio psicopedagógico'],
        ]])->assertRedirect();

        // The measure was written…
        $this->assertSame(1, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());
        // …and it did not become an intervention. That is a teacher's act,
        // with dates, an objective and reviews; deducing one from a cell would
        // be inventing a measure nobody decided on.
        $this->assertSame(0, Intervention::withoutGlobalScope('organization')->count());
    }

    /**
     * `raw_token` is rendered as «o ficheiro indicava: …». Substituting the
     * enum's own label when the source text is missing would put Lapispro's
     * words into the school document's mouth, which is the one distinction the
     * whole feature is built around. Nothing honest to store, so nothing is.
     */
    #[Test]
    public function a_measure_with_no_source_text_is_not_written_with_our_own_label(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [SupportMeasureCode::NonSignificantCurricularAdaptation->value],
            // no raw_tokens at all
        ]])->assertRedirect();

        $this->assertSame(0, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());
    }

    /**
     * The one field in the write path that reaches a JSON column. Unshaped, a
     * crafted request could store unbounded nested data per measure.
     */
    #[Test]
    public function deeply_nested_annotations_are_refused(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');
        $code = SupportMeasureCode::NonSignificantCurricularAdaptation->value;

        $this->actingAs($this->user)->postJson(
            "/classes/{$this->class->ulid}/characterisation-imports",
            [
                'source_kind' => 'paste',
                'decisions' => [[
                    'enrollment_ulid' => $enrollment->ulid,
                    'measure_codes' => [$code],
                    'raw_tokens' => [$code => 'ACNS'],
                    'annotations' => [$code => [['nested' => str_repeat('x', 5000)]]],
                ]],
            ],
        )->assertStatus(422);

        $this->assertSame(0, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());
    }

    /**
     * A student who left in February was here in November, so a row about them
     * still matches — and the page has to be able to show what was written, or
     * the text lands somewhere nobody can ever see or correct.
     */
    #[Test]
    public function a_characterised_student_who_left_the_class_is_still_shown(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'sections' => ['summary' => 'Escrito enquanto cá esteve.'],
        ]])->assertRedirect();

        $enrollment->forceFill(['status' => EnrollmentStatus::TransferredOut])->save();

        $this->actingAs($this->user)
            ->get("/classes/{$this->class->ulid}/characterisation")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('students', 1)
                ->where('students.0.enrollment_ulid', $enrollment->ulid)
            );
    }

    // ------------------------------------------------ 6. autorização

    #[Test]
    public function a_teacher_who_does_not_teach_the_class_cannot_preview(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson("/classes/{$this->class->ulid}/characterisation-imports/preview", [
                'pasted_text' => "Nome\tObservações\nAna\tTexto.\n",
            ])
            ->assertNotFound();
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_class_cannot_confirm(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post("/classes/{$this->class->ulid}/characterisation-imports", [
                'source_kind' => 'paste',
                'decisions' => [['enrollment_ulid' => $enrollment->ulid, 'sections' => ['summary' => 'x']]],
            ])
            ->assertNotFound();

        $this->assertSame(0, EnrollmentCharacterisation::withoutGlobalScope('organization')->count());
    }

    // ------------------------------------------------ 7. entradas inválidas

    #[Test]
    public function an_import_with_neither_text_nor_file_is_refused(): void
    {
        $this->actingAs($this->user)
            ->postJson("/classes/{$this->class->ulid}/characterisation-imports/preview", [])
            ->assertStatus(422);
    }

    #[Test]
    public function a_table_with_no_way_to_identify_a_student_says_so(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->preview("Medidas\tObservações\nACNS\tTexto.\n")
            ->assertOk()
            ->assertJsonPath('preview.identifiable', false);
    }
}
