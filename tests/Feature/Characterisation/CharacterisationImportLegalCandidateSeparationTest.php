<?php

namespace Tests\Feature\Characterisation;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE HOTFIX'S OWN REGRESSION SUITE, end to end through the real preview
 * endpoint and a real LegalCodeResolver — not a unit test of
 * SeparateLegalCandidates in isolation (see that class's own test for the
 * separation logic itself), but proof that the WIRING in
 * BuildCharacterisationPreview::resolutionsFor() actually keeps prose out of
 * `unresolved` without losing a single real legal code.
 *
 * Rejected before this hotfix: c1f55ffe made a dual-purpose column's free
 * text also reach CharacterisationSection::Summary, but its raw text STILL
 * went to LegalCodeResolver whole, so ordinary sentences ("1R 25/26", "Teve
 * alta da Terapia da Fala") came back as fabricated "unresolved" legal codes.
 * Every test below is one of the 9 cases that fix was rejected for not
 * covering.
 */
class CharacterisationImportLegalCandidateSeparationTest extends TestCase
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

    private function enrol(string $name): Enrollment
    {
        return app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): Enrollment => app(StudentEnrollmentService::class)->enrollNew($this->class, ['name' => $name]),
        );
    }

    private function preview(string $pastedText)
    {
        return $this->actingAs($this->user)->postJson(
            '/classes/'.$this->class->ulid.'/characterisation-imports/preview',
            ['pasted_text' => $pastedText],
        );
    }

    // ---------------------------------------------- prosa nunca vira "unresolved"

    /** Cabeçalho de duplo propósito: a coluna diz "medidas", a célula é um ano letivo. */
    #[Test]
    public function a_school_year_shorthand_in_a_dual_purpose_column_never_becomes_unresolved(): void
    {
        $this->enrol('Ana Silva');

        $response = $this->preview("Nome\tOutras medidas/recursos / Observações\nAna Silva\t1R 25/26\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.unresolved', [])
            ->assertJsonPath('preview.rows.0.measures', []);
    }

    #[Test]
    public function a_class_size_reduction_note_never_becomes_unresolved(): void
    {
        $this->enrol('Ana Silva');

        $response = $this->preview("Nome\tOutras medidas/recursos / Observações\nAna Silva\tRedução de turma\n");

        $response->assertOk()->assertJsonPath('preview.rows.0.unresolved', []);
    }

    #[Test]
    public function a_speech_therapy_discharge_note_never_becomes_unresolved(): void
    {
        $this->enrol('Ana Silva');

        $response = $this->preview("Nome\tOutras medidas/recursos / Observações\nAna Silva\tTeve alta da Terapia da Fala\n");

        $response->assertOk()->assertJsonPath('preview.rows.0.unresolved', []);
    }

    // ---------------------------------------------- código continua a resolver

    #[Test]
    public function mu_with_three_subparagraphs_still_resolves_to_three_measures(): void
    {
        $this->enrol('Ana Silva');

        $response = $this->preview("Nome\tMU\nAna Silva\tMU a) b) e)\n");

        $response->assertOk();
        $codes = array_column($response->json('preview.rows.0.measures'), 'code');

        $this->assertContains('pedagogical_differentiation', $codes);
        $this->assertContains('curricular_accommodation', $codes);
        $this->assertContains('academic_focus_small_group', $codes);
        $this->assertCount(3, $codes);
    }

    #[Test]
    public function ms_with_a_named_measure_and_two_subparagraphs_still_resolves(): void
    {
        $this->enrol('Ana Silva');

        $response = $this->preview("Nome\tMS\nAna Silva\tMS b) ACNS c) d)\n");

        $response->assertOk();

        // At minimum, the named measure (ACNS) must still resolve —
        // whatever the framework says about c)/d) at the Selective level.
        $codes = array_column($response->json('preview.rows.0.measures'), 'code');
        $this->assertContains('non_significant_curricular_adaptation', $codes);
    }

    /** A bare alínea, alone, is genuinely ambiguous — it must stay that way. */
    #[Test]
    public function a_bare_subparagraph_letter_alone_stays_ambiguous(): void
    {
        $this->enrol('Ana Silva');

        $response = $this->preview("Nome\tMedidas\nAna Silva\tb)\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.measures', [])
            ->assertJsonPath('preview.rows.0.unresolved.0.confidence', 'ambiguous');
    }

    // ---------------------------------------------- o caso difícil

    /**
     * THE DESIGN-DEFINING CASE: "MS b)" must still resolve as a measure —
     * "Preencher ACNS" must never appear as a fabricated unresolved legal
     * code. The full sentence stays available in the Characterisation
     * section when the column is dual-purpose; see
     * CharacterisationRealisticTableImportTest for that half of the
     * guarantee.
     */
    #[Test]
    public function ms_b_preencher_acns_resolves_the_measure_and_drops_the_instruction(): void
    {
        $this->enrol('Ana Silva');

        $response = $this->preview("Nome\tOutras medidas/recursos / Observações\nAna Silva\tMS b) Preencher ACNS\n");

        $response->assertOk();

        $codes = array_column($response->json('preview.rows.0.measures'), 'code');
        $this->assertContains('non_significant_curricular_adaptation', $codes, 'MS b) must still resolve to ACNS.');

        // Nothing in `unresolved` may be, or contain, the instruction text.
        foreach ($response->json('preview.rows.0.unresolved') as $unresolved) {
            $this->assertStringNotContainsString('Preencher', $unresolved['raw_token']);
        }
    }

    // ---------------------------------------------- não regride classificações existentes

    #[Test]
    public function cri_still_classifies_as_a_resource_not_a_measure(): void
    {
        $this->enrol('Ana Silva');

        $response = $this->preview("Nome\tApoios\nAna Silva\tCRI\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.measures', []);

        $this->assertSame('resource_support', $response->json('preview.rows.0.resources.0.family'));
    }

    #[Test]
    public function plnm_still_stays_unrecognised_as_a_measure(): void
    {
        $this->enrol('Ana Silva');

        $response = $this->preview("Nome\tMedidas\nAna Silva\tPLNM\n");

        $response->assertOk()
            ->assertJsonPath('preview.rows.0.measures', [])
            ->assertJsonPath('preview.rows.0.unresolved.0.confidence', 'unrecognised');

        $this->assertStringContainsString('Português Língua Não Materna', (string) $response->json('preview.rows.0.unresolved.0.note'));
    }
}
