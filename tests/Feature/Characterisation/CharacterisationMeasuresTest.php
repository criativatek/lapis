<?php

namespace Tests\Feature\Characterisation;

use App\Actions\Interventions\CreateIntervention;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\EnrollmentCharacterisationSourceMeasure;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionOrigin;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\LegalMappingSource;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Characterisation\AcronymDictionary;
use App\Support\Characterisation\DecreeLaw54CodeResolver;
use App\Support\Interventions\LegalFrameworkRegistry;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Janela P: medidas associadas na Caracterização pedagógica.
 *
 * The two things this suite exists to make impossible: a measure appearing
 * twice for the same student because it was registered from two screens, and
 * a measure's provenance (import vs. manual) getting lost or invented.
 *
 * A) e F) e I) cobrem o payload que `ClassCharacterisationController::show()`
 * envia; B) e G) o novo `storeMeasure()`; C) e D) a convergência com
 * Estratégias e Medidas (o mesmo `Intervention`, lido dos dois ecrãs); E) a
 * proveniência de uma medida que já vinha da importação; H) e I) a garantia
 * de que uma sigla como CRI ou PLNM nunca resolve para uma medida.
 */
class CharacterisationMeasuresTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->user = User::factory()->create();
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

    private function enrol(User $owner, SchoolClass $class, string $name): Enrollment
    {
        return app(CurrentOrganization::class)->runFor(
            $owner->personalOrganization(),
            fn (): Enrollment => app(StudentEnrollmentService::class)->enrollNew($class, ['name' => $name]),
        );
    }

    private function measuresPayload(SchoolClass $class): array
    {
        return $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}/characterisation")
            ->viewData('page')['props']['students'];
    }

    // ------------------------------------------------ A) sem medidas

    #[Test]
    public function a_student_with_no_measures_has_an_empty_measures_payload(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Ana Silva');

        $students = $this->measuresPayload($class);
        $student = collect($students)->firstWhere('enrollment_ulid', $enrollment->ulid);

        $this->assertSame([], $student['measures']);
    }

    // ------------------------------------------------ B) registo manual

    #[Test]
    public function posting_a_measure_creates_a_structured_intervention_with_manual_origin(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Bruno Costa');

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation/measures", [
                'support_measure_code' => SupportMeasureCode::TutorialSupport->value,
            ])
            ->assertRedirect();

        $intervention = Intervention::withoutGlobalScope('organization')
            ->where('enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        $this->assertSame(InterventionOrigin::Manual, $intervention->origin);
        $this->assertSame(SupportMeasureCode::TutorialSupport, $intervention->support_measure_code);
        $this->assertNotNull($intervention->support_measure_level);
        $this->assertSame(InterventionDescriptionSource::Manual, $intervention->description_source);
        $this->assertTrue($intervention->supportMeasures()->where('support_measure_code', SupportMeasureCode::TutorialSupport)->exists());
    }

    // ------------------------------------------------ C) aparece em Estratégias e Medidas

    #[Test]
    public function a_manually_created_measure_appears_in_the_interventions_screen(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Carla Nunes');

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation/measures", [
                'support_measure_code' => SupportMeasureCode::TutorialSupport->value,
            ])
            ->assertRedirect();

        $interventions = $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}/interventions")
            ->viewData('page')['props']['interventions'];

        $found = collect($interventions)->first(fn (array $row) => $row['target_enrollment_ulid'] === $enrollment->ulid);

        $this->assertNotNull($found, 'The manually-registered measure must be visible from Estratégias e Medidas.');
        $this->assertSame(SupportMeasureCode::TutorialSupport->value, $found['legal_framing']['measure']);
    }

    // ------------------------------------------------ D) criada em Estratégias e Medidas aparece na Caracterização

    #[Test]
    public function a_measure_created_from_interventions_appears_in_characterisation_measures(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Duarte Alves');

        $this->actingAs($this->user)->postJson("/classes/{$class->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->getKey()],
            'intervention_types' => [InterventionType::TutorialSupport->value],
            'domain_relation' => InterventionDomainRelation::None->value,
            'description' => null,
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
            'legal_framing' => 'auto',
        ])->assertRedirect();

        $students = $this->measuresPayload($class);
        $student = collect($students)->firstWhere('enrollment_ulid', $enrollment->ulid);

        $this->assertNotEmpty($student['measures']);
        $this->assertSame('Registo manual', $student['measures'][0]['origin_label']);
    }

    // ------------------------------------------------ E) e F) proveniência

    #[Test]
    public function a_previously_imported_measure_still_appears_and_keeps_its_provenance(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Elsa Ramos');

        $this->createImportedIntervention($class, $enrollment, SupportMeasureCode::TutorialSupport);

        $students = $this->measuresPayload($class);
        $student = collect($students)->firstWhere('enrollment_ulid', $enrollment->ulid);

        $this->assertCount(1, $student['measures']);
        $this->assertSame('Importação da caracterização', $student['measures'][0]['origin_label']);
    }

    #[Test]
    public function manual_and_imported_measures_are_labelled_differently(): void
    {
        $class = $this->createClass($this->user);
        $imported = $this->enrol($this->user, $class, 'Filipa Costa');
        $manual = $this->enrol($this->user, $class, 'Gonçalo Reis');

        $this->createImportedIntervention($class, $imported, SupportMeasureCode::TutorialSupport);

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/students/{$manual->ulid}/characterisation/measures", [
                'support_measure_code' => SupportMeasureCode::TutorialSupport->value,
            ])
            ->assertRedirect();

        $students = collect($this->measuresPayload($class));

        $this->assertSame('Importação da caracterização', $students->firstWhere('enrollment_ulid', $imported->ulid)['measures'][0]['origin_label']);
        $this->assertSame('Registo manual', $students->firstWhere('enrollment_ulid', $manual->ulid)['measures'][0]['origin_label']);
    }

    // ------------------------------------------------ G) POST repetido → erro, não duplica

    #[Test]
    public function posting_the_same_measure_twice_fails_validation_and_does_not_duplicate(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Hugo Pires');

        $payload = ['support_measure_code' => SupportMeasureCode::TutorialSupport->value];

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation/measures", $payload)
            ->assertRedirect();

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation/measures", $payload)
            ->assertSessionHasErrors('support_measure_code');

        $count = Intervention::withoutGlobalScope('organization')
            ->where('enrollment_id', $enrollment->getKey())
            ->count();

        $this->assertSame(1, $count);
    }

    // ------------------------------------------------ ordenação das medidas

    /**
     * Universal → seletiva → adicional, e alfabética dentro de cada nível.
     *
     * Ordenar pela etiqueta traduzida sozinha punha «Adaptações curriculares
     * significativas» — uma medida ADICIONAL — no topo da lista de todos os
     * alunos, e fazia a lista parecer ordenada por gravidade decrescente. As
     * medidas abaixo são registadas fora de ordem de propósito: se a
     * ordenação voltar a ser alfabética pura, a primeira linha muda e este
     * teste falha.
     */
    #[Test]
    public function measures_are_ordered_by_legal_level_then_alphabetically(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Rita Nunes');

        foreach ([
            SupportMeasureCode::SignificantCurricularAdaptation, // adicional
            SupportMeasureCode::CurricularEnrichment,            // universal
            SupportMeasureCode::TutorialSupport,                 // seletiva
            SupportMeasureCode::PedagogicalDifferentiation,      // universal
        ] as $code) {
            $this->actingAs($this->user)
                ->post("/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation/measures", [
                    'support_measure_code' => $code->value,
                ])
                ->assertRedirect();
        }

        $students = $this->measuresPayload($class);
        $measures = collect($students)->firstWhere('enrollment_ulid', $enrollment->ulid)['measures'];

        $this->assertSame([
            SupportMeasureLevel::Universal->label(),
            SupportMeasureLevel::Universal->label(),
            SupportMeasureLevel::Selective->label(),
            SupportMeasureLevel::Additional->label(),
        ], array_column($measures, 'level_label'));

        $this->assertSame([
            SupportMeasureCode::PedagogicalDifferentiation->label(),
            SupportMeasureCode::CurricularEnrichment->label(),
            SupportMeasureCode::TutorialSupport->label(),
            SupportMeasureCode::SignificantCurricularAdaptation->label(),
        ], array_column($measures, 'code_label'));

        // O nível de ordenação não vai no payload: é detalhe da ordenação,
        // não informação que o ecrã mostre.
        $this->assertArrayNotHasKey('_level_rank', $measures[0]);
    }

    // ------------------------------------------------ H) CRI e I) PLNM nunca são medidas

    #[Test]
    public function cri_never_resolves_to_a_support_measure(): void
    {
        $resolutions = $this->decreeLaw54Resolver()->resolveCell('CRI');

        foreach ($resolutions as $resolution) {
            $this->assertNull($resolution->code, 'CRI é um recurso (Centro de Recursos para a Inclusão), nunca uma medida.');
        }
    }

    #[Test]
    public function plnm_never_resolves_to_a_support_measure(): void
    {
        $resolutions = $this->decreeLaw54Resolver()->resolveCell('PLNM');

        foreach ($resolutions as $resolution) {
            $this->assertNull($resolution->code, 'PLNM é um percurso curricular, nunca uma medida.');
        }
    }

    private function decreeLaw54Resolver(): DecreeLaw54CodeResolver
    {
        $organization = new Organization;
        $organization->jurisdiction = 'PT';

        $tenant = new class($organization) extends CurrentOrganization
        {
            public function __construct(private readonly Organization $resolved) {}

            public function isResolved(): bool
            {
                return true;
            }

            public function get(): Organization
            {
                return $this->resolved;
            }
        };

        return new DecreeLaw54CodeResolver(
            new AcronymDictionary,
            new LegalFrameworkResolver(new LegalFrameworkRegistry(null)),
            $tenant,
        );
    }

    /**
     * Fabricates exactly what `ApplyCharacterisationImport::createInterventions()`
     * would have written for a confirmed import row — a structured
     * Intervention with `origin = CharacterisationImport`, plus the source
     * measure record it would have left behind (raw_token included).
     */
    private function createImportedIntervention(SchoolClass $class, Enrollment $enrollment, SupportMeasureCode $code): void
    {
        app(CurrentOrganization::class)->runFor($class->organization, function () use ($class, $enrollment, $code): void {
            $framework = app(LegalFrameworkResolver::class)->for($class->organization, now());
            $level = $framework->levelFor($code) ?? SupportMeasureLevel::Selective;

            app(CreateIntervention::class)->create(
                class: $class,
                attributes: [
                    'enrollment_id' => $enrollment->getKey(),
                    'target_type' => InterventionTargetType::Student,
                    'intervention_type' => InterventionType::tryFrom($code->value) ?? InterventionType::Other,
                    'intervention_type_label' => $code->label(),
                    'domain_relation' => InterventionDomainRelation::None,
                    'title' => $code->label(),
                    'description' => 'Importado da caracterização — o ficheiro indicava: apoio tutorial',
                    'description_source' => InterventionDescriptionSource::Import,
                    'status' => InterventionStatus::New,
                    'started_on' => now()->toDateString(),
                    'available_for_reports' => true,
                    'include_in_report' => true,
                    'support_measure_level' => $level,
                    'support_measure_code' => $code,
                    'legal_mapping_source' => LegalMappingSource::SystemSuggestedConfirmed,
                    'legal_framework_code' => $framework->code(),
                    'origin' => InterventionOrigin::CharacterisationImport,
                ],
                supportMeasures: [['level' => $level->value, 'code' => $code->value]],
                participantIds: [$enrollment->getKey()],
                createdBy: $this->user,
            );

            $characterisation = EnrollmentCharacterisation::query()->firstOrCreate(['enrollment_id' => $enrollment->getKey()]);

            EnrollmentCharacterisationSourceMeasure::query()->create([
                'enrollment_characterisation_id' => $characterisation->getKey(),
                'support_measure_level' => $level,
                'support_measure_code' => $code,
                'raw_token' => 'apoio tutorial',
                'unresolved_annotations' => [],
            ]);
        });
    }
}
