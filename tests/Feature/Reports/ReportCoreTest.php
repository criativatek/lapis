<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionKey;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportLibraryEntry;
use App\Models\ReportStatus;
use App\Models\ReportTone;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\ReportCapabilities;
use App\Services\Reporting\ReportListing;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\EntitlementsSeeder;
use Database\Seeders\ReportLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The report core: what a report IS before anything is generated into it.
 *
 * Three properties are load-bearing and are asserted here rather than trusted:
 * a finalized report cannot be rewritten, one organization's reports are
 * invisible to another, and what a plan allows is decided on the server.
 */
class ReportCoreTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback, ?Organization $organization = null): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization ?? $this->organization, $callback);
    }

    // ---------------------------------------------------------- immutability

    #[Test]
    public function a_finalized_report_refuses_to_be_rewritten(): void
    {
        $report = $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->finalized(['version' => 1, 'sections' => [['key' => 'overall_assessment', 'body' => 'Texto.']]])
            ->create(['created_by' => $this->teacher->id]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('não se reescreve');

        $this->asTenant(fn () => $report->update(['tone' => ReportTone::Formal]));
    }

    #[Test]
    public function a_finalized_report_may_still_have_its_title_corrected(): void
    {
        $report = $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->finalized()
            ->create(['created_by' => $this->teacher->id, 'title' => 'Relatoria de turma']));

        $this->asTenant(fn () => $report->update(['title' => 'Relatório de turma']));

        $this->assertSame('Relatório de turma', $report->fresh()->title);
        // The document itself did not move.
        $this->assertTrue($report->fresh()->isIntact());
    }

    #[Test]
    public function a_draft_is_freely_editable(): void
    {
        $report = $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->create(['created_by' => $this->teacher->id]));

        $this->asTenant(fn () => $report->update(['tone' => ReportTone::Formal, 'title' => 'Outro título']));

        $this->assertSame(ReportTone::Formal, $report->fresh()->tone);
    }

    #[Test]
    public function the_document_hash_detects_a_rewrite_around_the_model(): void
    {
        $report = $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->finalized(['version' => 1, 'sections' => [['key' => 'x', 'body' => 'Original.']]])
            ->create(['created_by' => $this->teacher->id]));

        $this->assertTrue($report->isIntact());

        // Straight past Eloquent, as a stray query or a restored dump would.
        \DB::table('reports')->where('id', $report->id)->update([
            'document' => json_encode(['version' => 1, 'sections' => [['key' => 'x', 'body' => 'Adulterado.']]]),
        ]);

        $this->assertFalse($this->asTenant(fn () => $report->fresh()->isIntact()));
    }

    // --------------------------------------------------------------- tenancy

    #[Test]
    public function a_report_belongs_to_one_organization_and_is_invisible_to_another(): void
    {
        $mine = $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->create(['created_by' => $this->teacher->id]));

        $stranger = User::factory()->create();
        $otherOrganization = $stranger->personalOrganization();

        $visible = app(CurrentOrganization::class)->runFor(
            $otherOrganization,
            fn () => Report::query()->pluck('ulid')->all(),
        );

        $this->assertNotContains($mine->ulid, $visible);
        $this->assertSame([], $visible);
    }

    #[Test]
    public function the_listing_never_crosses_organizations(): void
    {
        $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->create(['created_by' => $this->teacher->id, 'title' => 'O meu relatório']));

        $stranger = User::factory()->create();

        $rows = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn () => app(ReportListing::class)->for($stranger),
        );

        $this->assertSame([], $rows['rows']);
        $this->assertSame(0, $rows['total']);
    }

    // ---------------------------------------------------------- capabilities

    #[Test]
    public function a_base_plan_gets_the_descriptive_sections_and_not_the_interpretive_ones(): void
    {
        $this->givePlan('base');

        $keys = $this->asTenant(fn () => array_map(
            fn ($definition) => $definition->key,
            app(ReportCapabilities::class)->sectionsFor(ReportType::SchoolClass),
        ));

        // Description of data: always available.
        $this->assertContains(SectionKey::OverallAssessment, $keys);
        $this->assertContains(SectionKey::DomainResults, $keys);
        $this->assertContains(SectionKey::ClassEvolution, $keys);
        // The teacher's own statement about the planning is transcription, not
        // analysis, so it stays in Base.
        $this->assertContains(SectionKey::PlanningCompliance, $keys);

        // Interpretation: not on this plan.
        $this->assertNotContains(SectionKey::BehaviourAttitude, $keys);
        $this->assertNotContains(SectionKey::Difficulties, $keys);
        $this->assertNotContains(SectionKey::ImprovementProposals, $keys);
        $this->assertNotContains(SectionKey::StudentsRequiringAttention, $keys);
    }

    #[Test]
    public function a_pro_plan_gets_the_interpretive_sections_too(): void
    {
        $this->givePlan('pro');

        $keys = $this->asTenant(fn () => array_map(
            fn ($definition) => $definition->key,
            app(ReportCapabilities::class)->sectionsFor(ReportType::SchoolClass),
        ));

        $this->assertContains(SectionKey::BehaviourAttitude, $keys);
        $this->assertContains(SectionKey::Difficulties, $keys);
        $this->assertContains(SectionKey::ImprovementProposals, $keys);
    }

    #[Test]
    public function the_school_report_is_institutional_only(): void
    {
        $this->givePlan('pro');

        $types = $this->asTenant(fn () => app(ReportCapabilities::class)->availableTypes());

        $this->assertContains(ReportType::SchoolClass, $types);
        $this->assertContains(ReportType::Student, $types);
        $this->assertContains(ReportType::Records, $types);
        $this->assertNotContains(ReportType::School, $types);

        $this->givePlan('institutional');
        app(Entitlements::class)->flush();

        $types = $this->asTenant(fn () => app(ReportCapabilities::class)->availableTypes());
        $this->assertContains(ReportType::School, $types);
    }

    #[Test]
    public function the_catalogue_shows_unavailable_sections_rather_than_hiding_them(): void
    {
        $this->givePlan('base');

        $catalogue = $this->asTenant(fn () => app(ReportCapabilities::class)->catalogueFor(ReportType::SchoolClass));

        $difficulties = collect($catalogue)->firstWhere('key', SectionKey::Difficulties->value);

        $this->assertNotNull($difficulties, 'A secção deve continuar a aparecer no catálogo.');
        $this->assertFalse($difficulties['available']);
        // And is never ticked by default when it cannot be produced.
        $this->assertFalse($difficulties['default_included']);
        $this->assertSame(SectionCatalogue::PEDAGOGICAL_MODULE, $difficulties['module']);
    }

    #[Test]
    public function the_sections_that_can_name_a_student_are_never_included_by_default(): void
    {
        foreach (ReportType::cases() as $type) {
            foreach (SectionCatalogue::for($type) as $definition) {
                if ($definition->mayNameStudents) {
                    $this->assertFalse(
                        $definition->defaultIncluded,
                        "«{$definition->heading}» pode identificar alunos e não pode vir ligada por omissão.",
                    );
                }
            }
        }
    }

    // ---------------------------------------------------------------- library

    #[Test]
    public function every_seeded_strategy_answers_a_seeded_difficulty(): void
    {
        $this->seed(ReportLibrarySeeder::class);

        $difficulties = $this->asTenant(fn () => ReportLibraryEntry::query()
            ->ofKind(ReportLibraryEntry::KIND_DIFFICULTY)->pluck('code')->all());

        $strategies = $this->asTenant(fn () => ReportLibraryEntry::query()
            ->ofKind(ReportLibraryEntry::KIND_STRATEGY)->get());

        $this->assertNotEmpty($strategies);

        foreach ($strategies as $strategy) {
            $this->assertContains(
                $strategy->related_code,
                $difficulties,
                "A estratégia «{$strategy->label}» aponta para uma dificuldade que não existe.",
            );
            // §14: a strategy without a stated objective is a generic list item,
            // which is exactly what the library exists to avoid.
            $this->assertNotNull($strategy->objective, "«{$strategy->label}» não declara objetivo.");
        }
    }

    #[Test]
    public function system_library_entries_are_visible_to_every_organization(): void
    {
        $this->seed(ReportLibrarySeeder::class);

        $stranger = User::factory()->create();

        $count = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn () => ReportLibraryEntry::query()->ofKind(ReportLibraryEntry::KIND_DIFFICULTY)->count(),
        );

        $this->assertGreaterThan(0, $count);
    }

    #[Test]
    public function one_schools_own_library_entry_is_invisible_to_another(): void
    {
        $mine = $this->asTenant(fn () => ReportLibraryEntry::create([
            'kind' => ReportLibraryEntry::KIND_DIFFICULTY,
            'code' => 'minha_dificuldade',
            'label' => 'Uma dificuldade da minha escola',
        ]));

        $this->assertSame($this->organization->id, $mine->organization_id);

        $stranger = User::factory()->create();

        $visible = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn () => ReportLibraryEntry::query()->pluck('code')->all(),
        );

        $this->assertNotContains('minha_dificuldade', $visible);
    }

    // ---------------------------------------------------------------- listing

    #[Test]
    public function the_listing_shows_a_report_about_a_class_the_user_teaches(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);

        $class = $this->asTenant(fn () => SchoolClass::factory()->recycle($this->organization)->create());
        $this->asTenant(fn () => $class->teachers()->syncWithoutDetaching([
            $this->teacher->id => ['role' => 'owner'],
            $colleague->id => ['role' => 'teacher'],
        ]));

        $this->asTenant(fn () => Report::factory()->recycle($this->organization)->create([
            'class_id' => $class->id,
            'created_by' => $this->teacher->id,
            'title' => 'Relatório do 1.º Período',
        ]));

        $rows = $this->asTenant(fn () => app(ReportListing::class)->for($colleague));

        $this->assertCount(1, $rows['rows']);
        $this->assertSame('Relatório do 1.º Período', $rows['rows'][0]['title']);
    }

    #[Test]
    public function a_row_states_its_temporal_scope(): void
    {
        $report = $this->asTenant(fn () => Report::factory()->recycle($this->organization)->create([
            'created_by' => $this->teacher->id,
            'scope_label' => '2.º Semestre (acumulado)',
        ]));

        $row = $this->asTenant(fn () => app(ReportListing::class)->row($report));

        $this->assertSame('2.º Semestre (acumulado)', $row['scope_label']);
        $this->assertSame(ReportStatus::Draft->value, $row['status']);
    }

    protected function givePlan(string $planKey): void
    {
        $this->seed(EntitlementsSeeder::class);

        $plan = Plan::where('key', $planKey)->firstOrFail();

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();

        // Guard: without this the capability assertions would pass vacuously.
        $this->assertTrue(Module::where('key', SectionCatalogue::PEDAGOGICAL_MODULE)->exists());
    }
}
