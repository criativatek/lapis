<?php

namespace Tests\Feature\Characterisation;

use App\Actions\Characterisation\ApplyCharacterisationImport;
use App\Actions\Interventions\CreateIntervention;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\CharacterisationImportBatch;
use App\Models\CharacterisationRevision;
use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\EnrollmentCharacterisationSourceMeasure;
use App\Models\EnrollmentStatus;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionType;
use App\Models\LegalMappingSource;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Models\User;
use App\Services\Characterisation\RecordCharacterisation;
use App\Services\StudentEnrollmentService;
use App\Support\Characterisation\LegalCodeResolver;
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

        // Nothing left unresolved: under the diploma, article 9.º n.º 2 alínea
        // b) IS «as adaptações curriculares não significativas», so the letter
        // corroborates the measure instead of riding along unread.
        $this->assertSame([], $response->json('preview.rows.0.measures.0.unresolved_annotations'));
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
     * A column header naming the level is the context a bare letter needs, and
     * the framework supplies the rest: «Medidas universais» + «b)» is article
     * 8.º, n.º 2, alínea b) — as acomodações curriculares.
     *
     * Without the header the same cell stays ambiguous, which is the case
     * above. The difference between the two is the whole rule.
     */
    #[Test]
    public function a_column_header_gives_a_letter_the_context_the_framework_needs(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $response = $this->preview("Nome\tMedidas universais\nAna Silva\tb)\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.measures.0.confidence', 'recognised')
            ->assertJsonPath('preview.rows.0.measures.0.level', 'universal')
            ->assertJsonPath('preview.rows.0.measures.0.code', 'curricular_accommodation')
            ->assertJsonPath('preview.rows.0.unresolved', []);
    }

    /**
     * The catalogue now names «o plano individual de transição» — and a column
     * reading «PIT» is still the document a school keeps, not a statement that
     * the measure applies to that child.
     */
    #[Test]
    public function pit_does_not_become_a_measure_through_the_import(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->preview("Nome\tMedidas\nAna Silva\tPIT\n")
            ->assertOk()
            ->assertJsonPath('preview.rows.0.measures', [])
            ->assertJsonPath('preview.rows.0.unresolved.0.confidence', 'unrecognised')
            ->assertJsonPath('preview.rows.0.unresolved.0.code', null);
    }

    /**
     * A measure that arrived with the canonical catalogue, never known to this
     * importer, resolves without the parser being touched.
     */
    #[Test]
    public function a_measure_from_the_canonical_catalogue_resolves_without_parser_changes(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->preview("Nome\tMedidas\nAna Silva\tOs percursos curriculares diferenciados\n")
            ->assertOk()
            ->assertJsonPath('preview.rows.0.measures.0.confidence', 'recognised')
            ->assertJsonPath('preview.rows.0.measures.0.code', 'differentiated_curricular_paths')
            ->assertJsonPath('preview.rows.0.measures.0.level', 'selective');
    }

    // ------------------------------------------------ 3b. apoios e recursos

    /**
     * A COLUNA «APOIOS» NÃO É A COLUNA «NECESSIDADES». Antes, um cabeçalho com
     * «apoio» classificava como Necessidades e o conteúdo da célula — um CRI —
     * era escrito tal e qual na necessidade do aluno. Um Centro de Recursos
     * para a Inclusão é um apoio mobilizado, não uma necessidade da criança, e
     * a coluna que por acaso existe não é razão para afirmar o contrário.
     */
    #[Test]
    public function a_resources_column_is_not_written_into_the_needs_section(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $response = $this->preview("Nome\tApoios e recursos\nAna Silva\tCRI\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.sections', [])
            ->assertJsonPath('preview.rows.0.measures', []);

        $this->assertSame('resource_support', $response->json('preview.rows.0.resources.0.family'));
        $this->assertFalse($response->json('preview.rows.0.resources.0.has_structured_destination'));
    }

    #[Test]
    public function a_recognised_resource_is_shown_with_its_kind_and_its_source_text(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $response = $this->preview("Nome\tApoios\nAna Silva\tCRI\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.resources.0.raw_token', 'CRI')
            ->assertJsonPath('preview.rows.0.resources.0.family_label', 'Apoio ou recurso')
            ->assertJsonPath('preview.rows.0.resources.0.code', null);

        $this->assertStringContainsString(
            'Centro de Recursos para a Inclusão',
            (string) $response->json('preview.rows.0.resources.0.note'),
        );
    }

    /**
     * Confirming an import that carried a resource writes the resource
     * NOWHERE — not into needs, not into barriers, not as a measure. There is
     * no honest destination, so there is no write.
     */
    #[Test]
    public function confirming_an_import_with_a_resource_stores_the_resource_nowhere(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        // What the preview would hand back for a resources column: no sections,
        // no measure codes.
        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'sections' => [],
            'measure_codes' => [],
        ]])->assertRedirect();

        $this->assertSame(0, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());
        $this->assertSame(0, EnrollmentCharacterisation::withoutGlobalScope('organization')->count());
    }

    /** A resource token can never be smuggled in as a measure code. */
    #[Test]
    public function a_resource_cannot_be_written_as_a_measure(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => ['CRI'],
            'raw_tokens' => ['CRI' => 'CRI'],
        ]])->assertRedirect();

        $this->assertSame(0, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());
    }

    /** A measures column keeps reaching the resolver, resources column or not. */
    #[Test]
    public function a_resources_column_does_not_disturb_the_measures_column(): void
    {
        $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->preview("Nome\tApoios\tMedidas\nAna Silva\tCRI\tMS b) + ACNS\n")
            ->assertOk()
            ->assertJsonPath('preview.rows.0.measures.0.code', 'non_significant_curricular_adaptation')
            ->assertJsonPath('preview.rows.0.resources.0.raw_token', 'CRI');
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

    /**
     * §5: the server is the source of the level. The client sends codes; the
     * framework answers what level each sits at. Nothing the request says about
     * a level is taken on trust — the request has no field for one at all.
     */
    #[Test]
    public function the_level_written_is_the_frameworks_answer_not_the_clients(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');
        $code = SupportMeasureCode::SignificantCurricularAdaptation->value;

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [$code],
            'raw_tokens' => [$code => 'ACS'],
            // A client insisting on the wrong level, as an old tab or a crafted
            // request would.
            'support_measure_level' => SupportMeasureLevel::Universal->value,
        ]])->assertRedirect();

        $measure = EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame(SupportMeasureLevel::Additional, $measure->support_measure_level);
    }

    /**
     * A recognised, confirmed measure now creates a structured Intervention —
     * because a person, not a spreadsheet cell, confirmed it (see the
     * reconciled docblock on `ApplyCharacterisationImport::writeMeasures()`).
     */
    #[Test]
    public function a_recognised_measure_creates_an_intervention(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [SupportMeasureCode::PsychopedagogicalSupport->value],
            'raw_tokens' => [SupportMeasureCode::PsychopedagogicalSupport->value => 'Apoio psicopedagógico'],
        ]])->assertRedirect();

        // The measure was written to the characterisation…
        $this->assertSame(1, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());

        // …and it ALSO reached Estratégias e Medidas, because the teacher
        // confirmed it row-by-row from the preview — nobody deduced anything.
        $intervention = Intervention::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame($enrollment->getKey(), $intervention->enrollment_id);
        $this->assertSame(SupportMeasureCode::PsychopedagogicalSupport, $intervention->support_measure_code);
        $this->assertSame('characterisation_import', $intervention->origin->value);
        $this->assertTrue($intervention->started_on->isToday());

        // #4: the description is COMPOSED BY THIS IMPORT, quoting the
        // school's paperwork — not the teacher's own words, so it must never
        // be stamped `Manual` ("Escrita pelo professor").
        $this->assertSame(InterventionDescriptionSource::Import, $intervention->description_source);

        // #7: the app derived this framing from the framework and the
        // teacher confirmed it row-by-row in the preview — exactly what
        // SystemSuggestedConfirmed documents, not a teacher picking it
        // unaided (Manual).
        $this->assertSame(LegalMappingSource::SystemSuggestedConfirmed, $intervention->legal_mapping_source);
    }

    /** Two distinct recognised measures create two distinct interventions. */
    #[Test]
    public function two_recognised_measures_create_two_interventions(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [
                SupportMeasureCode::PsychopedagogicalSupport->value,
                SupportMeasureCode::NonSignificantCurricularAdaptation->value,
            ],
            'raw_tokens' => [
                SupportMeasureCode::PsychopedagogicalSupport->value => 'Apoio psicopedagógico',
                SupportMeasureCode::NonSignificantCurricularAdaptation->value => 'ACNS',
            ],
        ]])->assertRedirect();

        $this->assertSame(2, Intervention::withoutGlobalScope('organization')->count());
    }

    /**
     * THE END-TO-END TEST FOR THE MU/MS/MA COLUMN DEFECT. Every other
     * intervention test above hands `measure_codes` to confirm() already
     * resolved — none of them exercises ClassifyColumns against a real
     * measures COLUMN the way a school's own table names it: bare "MU", not
     * "Medidas Universais". Before the fix, "MU" classified as Unknown, the
     * preview never resolved anything under it, and «a) b)» was ignored
     * outright — nothing reached Estratégias e Medidas. This goes through
     * preview() with an actual MU column, confirms exactly what the preview
     * proposed (as the browser would), and asserts both the source measures
     * and the resulting interventions exist with the right codes and level.
     */
    #[Test]
    public function an_mu_column_reaches_estrategias_e_medidas(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $preview = $this->preview("Aluno\tMU\nAna Silva\ta) b)\n")->assertOk();

        $measures = $preview->json('preview.rows.0.measures');
        $this->assertCount(2, $measures, 'MU a) b) must resolve to two distinct measures.');
        $this->assertSame('universal', $measures[0]['level']);
        $this->assertSame('universal', $measures[1]['level']);

        $codes = array_column($measures, 'code');
        $this->assertContains('pedagogical_differentiation', $codes);
        $this->assertContains('curricular_accommodation', $codes);

        $rawTokens = array_combine($codes, array_column($measures, 'raw_token'));

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => $codes,
            'raw_tokens' => $rawTokens,
        ]])->assertRedirect();

        $this->assertSame(
            2,
            EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count(),
            'The source measures read from the MU column must be written.',
        );

        $interventions = Intervention::withoutGlobalScope('organization')->get();
        $this->assertCount(2, $interventions, 'Two codes under MU must create two interventions in Estratégias e Medidas.');

        $interventionCodes = $interventions->pluck('support_measure_code')->map(fn ($code) => $code->value)->all();
        $this->assertContains('pedagogical_differentiation', $interventionCodes);
        $this->assertContains('curricular_accommodation', $interventionCodes);

        foreach ($interventions as $intervention) {
            $this->assertSame($enrollment->getKey(), $intervention->enrollment_id);
            $this->assertSame(SupportMeasureLevel::Universal, $intervention->support_measure_level);
        }
    }

    /**
     * The negative that already held before this fix, kept here so the two
     * cases stay side by side: a PLNM cell — a curricular pathway, not a
     * legal measure — must still create no intervention, MU column or not.
     */
    #[Test]
    public function a_plnm_cell_creates_no_intervention(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Beatriz Carvalho');

        $preview = $this->preview("Aluno\tMU\nBeatriz Carvalho\tPLNM\n")->assertOk();

        $this->assertSame([], $preview->json('preview.rows.0.measures'));

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [],
        ]])->assertRedirect();

        $this->assertSame(0, Intervention::withoutGlobalScope('organization')->count());
    }

    /**
     * §30: a measure already active for this student does not get a second
     * Intervention from a re-import — it is left alone rather than guessed
     * about.
     */
    #[Test]
    public function an_already_active_measure_creates_no_second_intervention(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');
        $code = SupportMeasureCode::PsychopedagogicalSupport;

        $decision = [[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [$code->value],
            'raw_tokens' => [$code->value => 'Apoio psicopedagógico'],
        ]];

        $this->confirm($decision)->assertRedirect();
        $this->confirm($decision)->assertRedirect();

        $this->assertSame(1, Intervention::withoutGlobalScope('organization')->count());
    }

    /**
     * §30, the case `activeInterventionExists()` used to miss (audit finding
     * #2): a hand-created intervention carrying TWO measures stores only the
     * FIRST on the parent's own `support_measure_code` column — the second
     * lives exclusively in `intervention_support_measures`. Checking only the
     * parent column let a sheet naming that second measure create a
     * duplicate. Both places must be checked.
     */
    #[Test]
    public function a_measure_held_only_in_a_multi_measure_interventions_pivot_is_still_deduplicated(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        // The teacher registers one intervention carrying two measures by
        // hand. The parent column holds only the first, pedagogical_differentiation.
        $this->actingAs($this->user)->postJson("/classes/{$this->class->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->getKey()],
            'intervention_type' => InterventionType::WritingOrganizationSupport->value,
            'domain_relation' => 'none',
            'description' => null,
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
            'legal_framing' => 'manual',
            'support_measures' => [
                ['level' => 'universal', 'code' => SupportMeasureCode::PedagogicalDifferentiation->value],
                ['level' => 'selective', 'code' => SupportMeasureCode::PsychopedagogicalSupport->value],
            ],
        ])->assertRedirect();

        $registered = Intervention::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame(SupportMeasureCode::PedagogicalDifferentiation, $registered->support_measure_code);
        $this->assertTrue(
            $registered->supportMeasures()
                ->where('support_measure_code', SupportMeasureCode::PsychopedagogicalSupport->value)
                ->exists(),
            'The second measure must be held in the pivot, not the parent column, for this test to exercise the bug.',
        );

        // Importing a sheet naming the SECOND measure — already registered,
        // just not on the parent column — must not create a duplicate.
        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [SupportMeasureCode::PsychopedagogicalSupport->value],
            'raw_tokens' => [SupportMeasureCode::PsychopedagogicalSupport->value => 'Apoio psicopedagógico'],
        ]])->assertRedirect();

        $this->assertSame(
            1,
            Intervention::withoutGlobalScope('organization')->count(),
            'A measure already registered — even only in the pivot — must not be imported a second time.',
        );
    }

    /**
     * Audit finding #6: `recognisedMeasures()` used to ask a
     * `LegalCodeResolver` for the level, which re-resolves the applicable
     * framework itself, through `CurrentOrganization` + `now()` — a SECOND
     * source of truth beside the framework `apply()` already resolved from
     * `$class->organization` at the batch's own `$startedOn`. They agreed
     * today only because CurrentOrganization happens to be bound to the same
     * organization the class belongs to in every real request; if it were
     * ever unresolved (a queued job, a console command), the resolver's
     * `levelFor()` would return null for every code and the row would be
     * counted `skipped`, indistinguishable from an empty one — silently,
     * because that call swallows the unresolved case rather than throwing.
     *
     * Reaching that exact runtime scenario needs a caller other than the
     * confirm() HTTP endpoint that also skips resolving CurrentOrganization
     * entirely, which no other write in `apply()` currently tolerates. What
     * IS directly testable, and pins the actual fix, is that the class no
     * longer depends on `LegalCodeResolver` at all: the level is read from
     * `InterventionLegalFramework::levelFor()` on the framework this action
     * already resolved for itself, with no second lookup left to disagree.
     */
    #[Test]
    public function the_action_no_longer_depends_on_legalcoderesolver_for_the_level(): void
    {
        $constructor = new \ReflectionMethod(ApplyCharacterisationImport::class, '__construct');
        $paramTypes = array_map(
            fn (\ReflectionParameter $parameter) => $parameter->getType()?->getName(),
            $constructor->getParameters(),
        );

        $this->assertNotContains(
            LegalCodeResolver::class,
            $paramTypes,
            'The level must come from the framework already resolved for the batch '.
            '(InterventionLegalFramework::levelFor()), not from a second lookup through '.
            'LegalCodeResolver, which resolves CurrentOrganization + now() independently.',
        );
    }

    /**
     * F11: an intervention created FROM AN IMPORT is audited exactly like one
     * created by hand — ids and counts, never the pedagogical text.
     */
    #[Test]
    public function an_import_created_intervention_is_audited(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');
        $code = SupportMeasureCode::PsychopedagogicalSupport;

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [$code->value],
            'raw_tokens' => [$code->value => 'Apoio psicopedagógico'],
        ]])->assertRedirect();

        $intervention = Intervention::withoutGlobalScope('organization')->firstOrFail();

        $event = AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'intervention.created')
            ->where('subject_id', $intervention->getKey())
            ->firstOrFail();

        $this->assertSame($this->user->getKey(), $event->causer_id);
        $this->assertSame('Intervention', $event->subject_type);
        $this->assertSame($enrollment->getKey(), $event->properties['enrollment_id']);
        $this->assertSame($code->value, $event->properties['support_measure_code']);

        // Ids and counts only — never the pedagogical text ("o ficheiro
        // indicava: Apoio psicopedagógico") that description carries.
        $this->assertStringNotContainsString('Apoio psicopedagógico', json_encode($event->properties));
    }

    /**
     * Audit finding #5: the import wrote its OWN `intervention.created`
     * property set instead of sharing the controller's shaper, so the same
     * event name carried two different shapes depending on which path wrote
     * it — the import's omitted `target_type`, `participants`,
     * `intervention_type` and `status` that every controller-issued event
     * carries. Both paths must now emit the same base shape, with the
     * import's own extra keys merged on top.
     */
    #[Test]
    public function an_import_created_interventions_audit_event_carries_the_same_base_shape_as_the_controllers(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');
        $code = SupportMeasureCode::PsychopedagogicalSupport;

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [$code->value],
            'raw_tokens' => [$code->value => 'Apoio psicopedagógico'],
        ]])->assertRedirect();

        $intervention = Intervention::withoutGlobalScope('organization')->firstOrFail();

        $event = AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'intervention.created')
            ->where('subject_id', $intervention->getKey())
            ->firstOrFail();

        // The base shape every controller-issued `intervention.created` event
        // already carries (see InterventionController::record() and
        // InterventionAuditProperties::base()).
        $this->assertSame((int) $intervention->class_id, $event->properties['class_id']);
        $this->assertSame('student', $event->properties['target_type']);
        $this->assertSame(1, $event->properties['participants']);
        $this->assertSame($intervention->intervention_type->value, $event->properties['intervention_type']);
        $this->assertSame('new', $event->properties['status']);

        // Plus the import's own extra keys, on top.
        $this->assertSame($enrollment->getKey(), $event->properties['enrollment_id']);
        $this->assertSame($code->value, $event->properties['support_measure_code']);
    }

    /**
     * F11: the audit write happens INSIDE the same transaction as the
     * intervention it describes — forcing the import's transaction to roll
     * back (here, by making the second decision's characterisation write
     * throw) must leave neither the intervention from the FIRST decision nor
     * its audit event behind. A queued audit write would survive a rollback
     * it should not have; an in-transaction Eloquent write cannot.
     */
    #[Test]
    public function no_audit_event_survives_a_rolled_back_import(): void
    {
        $first = $this->enrol($this->user, $this->class, 'Ana Silva');
        $second = $this->enrol($this->user, $this->class, 'Bruno Costa');
        $code = SupportMeasureCode::PsychopedagogicalSupport;

        $this->partialMock(RecordCharacterisation::class, function ($mock) {
            $calls = 0;

            $mock->shouldReceive('apply')
                ->twice()
                ->andReturnUsing(function (...$args) use (&$calls) {
                    $calls++;

                    if ($calls === 2) {
                        throw new \RuntimeException('forced rollback for the test');
                    }

                    return [];
                });
        });

        $this->confirm([
            [
                'enrollment_ulid' => $first->ulid,
                'measure_codes' => [$code->value],
                'raw_tokens' => [$code->value => 'Apoio psicopedagógico'],
            ],
            [
                'enrollment_ulid' => $second->ulid,
                'measure_codes' => [$code->value],
                'raw_tokens' => [$code->value => 'Apoio psicopedagógico'],
            ],
        ]);

        $this->assertSame(0, Intervention::withoutGlobalScope('organization')->count());
        $this->assertSame(
            0,
            AuditEvent::withoutGlobalScope('organization')->where('event', 'intervention.created')->count(),
        );
    }

    /** A bare, level-less letter creates no measure and no intervention. */
    #[Test]
    public function a_bare_letter_creates_no_intervention(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'measure_codes' => [],
        ]])->assertRedirect();

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

    // ------------------------------------------------ 8. importar é aditivo

    /** An empty student plus an import creates content — the simple case. */
    #[Test]
    public function importing_into_an_empty_student_creates_content(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'sections' => ['needs' => 'Necessita de apoio na compreensão dos enunciados.'],
        ]])->assertRedirect();

        $characterisation = EnrollmentCharacterisation::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('Necessita de apoio na compreensão dos enunciados.', $characterisation->needs);
    }

    /**
     * THE LOAD-BEARING TEST FOR CHANGE 1. A student who already has hand-written
     * text keeps it — the import's text lands beside it, never instead of it.
     */
    #[Test]
    public function importing_into_a_student_with_existing_content_preserves_it_and_appends(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => EnrollmentCharacterisation::create([
                'enrollment_id' => $enrollment->getKey(),
                'needs' => 'Dificuldade na organização do estudo.',
            ]),
        );

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'sections' => ['needs' => 'Necessita de apoio na compreensão dos enunciados.'],
        ]])->assertRedirect();

        $characterisation = EnrollmentCharacterisation::withoutGlobalScope('organization')->firstOrFail();
        $this->assertStringContainsString('Dificuldade na organização do estudo.', $characterisation->needs);
        $this->assertStringContainsString('Necessita de apoio na compreensão dos enunciados.', $characterisation->needs);
    }

    /** §25: re-importing the exact same table twice must not duplicate text. */
    #[Test]
    public function reimporting_identical_text_adds_nothing(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $decision = [[
            'enrollment_ulid' => $enrollment->ulid,
            'sections' => ['needs' => 'Necessita de apoio na compreensão dos enunciados.'],
        ]];

        $this->confirm($decision)->assertRedirect();
        $this->confirm($decision)->assertRedirect();

        $characterisation = EnrollmentCharacterisation::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('Necessita de apoio na compreensão dos enunciados.', $characterisation->needs);
        $this->assertSame(1, CharacterisationRevision::withoutGlobalScope('organization')->count());
    }

    /** Two sections merge independently — one section's text never leaks into another's. */
    #[Test]
    public function two_sections_merge_independently(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => EnrollmentCharacterisation::create([
                'enrollment_id' => $enrollment->getKey(),
                'strengths' => 'Trabalha bem em grupo.',
                'needs' => 'Dificuldade na organização do estudo.',
            ]),
        );

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'sections' => [
                'strengths' => 'Boa capacidade de concentração.',
                'needs' => 'Necessita de apoio na compreensão dos enunciados.',
            ],
        ]])->assertRedirect();

        $characterisation = EnrollmentCharacterisation::withoutGlobalScope('organization')->firstOrFail();
        $this->assertStringContainsString('Trabalha bem em grupo.', $characterisation->strengths);
        $this->assertStringContainsString('Boa capacidade de concentração.', $characterisation->strengths);
        $this->assertStringContainsString('Dificuldade na organização do estudo.', $characterisation->needs);
        $this->assertStringContainsString('Necessita de apoio na compreensão dos enunciados.', $characterisation->needs);
    }

    /** A revision is created, and it preserves the value that was there before. */
    #[Test]
    public function a_merge_creates_a_revision_that_preserves_the_previous_value(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => EnrollmentCharacterisation::create([
                'enrollment_id' => $enrollment->getKey(),
                'needs' => 'Dificuldade na organização do estudo.',
            ]),
        );

        $this->confirm([[
            'enrollment_ulid' => $enrollment->ulid,
            'sections' => ['needs' => 'Necessita de apoio na compreensão dos enunciados.'],
        ]])->assertRedirect();

        $revision = CharacterisationRevision::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('Dificuldade na organização do estudo.', $revision->previous_values['needs']);
    }

    /** The preview still writes absolutely nothing, merge logic included. */
    #[Test]
    public function previewing_with_existing_content_still_writes_nothing(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => EnrollmentCharacterisation::create([
                'enrollment_id' => $enrollment->getKey(),
                'needs' => 'Dificuldade na organização do estudo.',
            ]),
        );

        $this->preview("Nome\tNecessidades\nAna Silva\tNecessita de apoio na compreensão dos enunciados.\n")
            ->assertOk();

        $characterisation = EnrollmentCharacterisation::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('Dificuldade na organização do estudo.', $characterisation->needs);
        $this->assertSame(0, CharacterisationRevision::withoutGlobalScope('organization')->count());
    }

    // ------------------------------------------------ 9. atomicidade

    /**
     * §32: characterisation writes and intervention writes for one confirmed
     * import happen in ONE transaction. A failure creating the Intervention
     * must leave the section text and the source measure unwritten too.
     */
    #[Test]
    public function a_failure_partway_through_leaves_nothing_applied(): void
    {
        $enrollment = $this->enrol($this->user, $this->class, 'Ana Silva');

        $this->partialMock(CreateIntervention::class, function ($mock) {
            $mock->shouldReceive('create')->andThrow(new \RuntimeException('forced failure for the atomicity test'));
        });

        $this->withoutExceptionHandling();

        try {
            $this->confirm([[
                'enrollment_ulid' => $enrollment->ulid,
                'sections' => ['strengths' => 'Trabalha bem em grupo.'],
                'measure_codes' => [SupportMeasureCode::PsychopedagogicalSupport->value],
                'raw_tokens' => [SupportMeasureCode::PsychopedagogicalSupport->value => 'Apoio psicopedagógico'],
            ]]);
            $this->fail('Expected the forced failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced failure for the atomicity test', $exception->getMessage());
        }

        $this->assertSame(0, EnrollmentCharacterisation::withoutGlobalScope('organization')->count());
        $this->assertSame(0, EnrollmentCharacterisationSourceMeasure::withoutGlobalScope('organization')->count());
        $this->assertSame(0, Intervention::withoutGlobalScope('organization')->count());
        $this->assertSame(0, CharacterisationImportBatch::withoutGlobalScope('organization')->count());
    }
}
