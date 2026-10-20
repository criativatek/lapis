<?php

namespace Tests\Feature\Reporting;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\CompleteCorrection;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\Reporting\ClassDocuments\InstrumentGrid;
use App\Services\Reporting\ClassDocuments\InstrumentGridOptions;
use App\Services\StudentEnrollmentService;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The preview of the printable grid of an instrument (B2): the route, who may
 * open it, what it hands to the page, and what it refuses.
 *
 * The data itself is B1's and is tested there; what this holds is that the page
 * receives THAT structure untouched — the same array, to the byte — and that the
 * route is as closed as the correction grid and Resultados it hangs off.
 */
class InstrumentGridDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // The page prints «Emitido a …»: freeze the clock so the controller's
        // document and the one built here can be compared to the byte.
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();

        $this->seed(SystemScalesSeeder::class);
        $this->seed(InstrumentTypesSeeder::class);

        $this->asTenant(fn () => $this->scenario());
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

    /** Two domains 60/40; six students; «Teste 1» left `in_correction`. */
    private function scenario(): void
    {
        $year = AcademicYear::create([
            'label' => '2026/2027', 'starts_on' => '2026-09-14', 'ends_on' => '2027-06-30',
            'status' => 'active', 'country_code' => 'PT',
        ]);
        $period = $year->periods()->create([
            'label' => '1.º Período', 'kind' => 'term', 'sequence' => 1,
            'starts_on' => '2026-09-14', 'ends_on' => '2026-12-19', 'status' => 'open',
        ]);
        $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática']);

        $profile = app(ProfileBuilder::class)->create(
            ['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => 'Matemática', 'description' => 'Cenário.'],
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
            [['name' => 'Números e Operações', 'weight' => 60], ['name' => 'Geometria', 'weight' => 40]],
            ['8.º'],
        );
        $version = app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $this->teacher);

        $class = SchoolClass::create([
            'academic_year_id' => $year->id, 'subject_id' => $subject->id,
            'label' => '8.º A', 'grade_level' => '8.º', 'status' => 'active',
            'assessment_profile_version_id' => $version->id,
        ]);
        $class->teachers()->attach($this->teacher, ['role' => 'owner']);

        $service = app(StudentEnrollmentService::class);
        foreach (range(1, 5) as $n) {
            $service->enrollNew($class, ['name' => "Aluno {$n}", 'class_number' => $n, 'enrolled_on' => '2026-09-14']);
        }
        $service->enrollNew($class, ['name' => 'Aluno 6', 'class_number' => 6, 'enrolled_on' => '2026-12-01']);

        $numeros = Domain::where('name', 'Números e Operações')->firstOrFail();
        $geometria = Domain::where('name', 'Geometria')->firstOrFail();

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste 1', 'applied_on' => '2026-10-15', 'status' => 'in_correction',
            'counts_toward_classification' => true, 'purpose' => 'summative', 'total_points' => 20,
        ], [
            ['code' => 'Q1', 'label' => 'Equações', 'points_possible' => 10, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]],
            ['code' => 'Q2', 'label' => 'Áreas', 'points_possible' => 10, 'domains' => [['domain_id' => $geometria->id, 'allocation_percent' => 100]]],
        ]);

        $enrollments = $class->enrollments()->get()->keyBy('class_number');
        $items = $instrument->items()->orderBy('sequence')->get()->keyBy('code');
        $cells = [];

        foreach ([[1, 'Q1', 9.0], [1, 'Q2', 8.0], [2, 'Q1', 3.0], [2, 'Q2', 4.0]] as [$number, $code, $points]) {
            $cells[] = [
                'enrollment_id' => $enrollments[$number]->id, 'instrument_item_id' => $items[$code]->id,
                'result_state' => ResultState::Assessed->value, 'points_earned' => $points,
            ];
        }
        foreach (['Q1', 'Q2'] as $code) {
            $cells[] = ['enrollment_id' => $enrollments[3]->id, 'instrument_item_id' => $items[$code]->id, 'result_state' => ResultState::Absent->value];
        }

        app(RecordScores::class)->save($instrument, $cells, $this->teacher);
    }

    private function teste1(): Instrument
    {
        return $this->asTenant(fn (): Instrument => Instrument::where('title', 'Teste 1')->firstOrFail());
    }

    private function url(?Instrument $instrument = null, string $query = ''): string
    {
        return '/instruments/'.($instrument ?? $this->teste1())->ulid.'/impressao'.$query;
    }

    /** @return array<string, mixed> */
    private function props(string $query = '', ?User $as = null): array
    {
        $props = null;

        $this->actingAs($as ?? $this->teacher)->get($this->url(null, $query))->assertOk()->assertInertia(function ($page) use (&$props): void {
            $page->component('instruments/GridDocument');
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    // ================================================================= dados

    #[Test]
    public function the_page_receives_exactly_the_structure_the_builder_makes(): void
    {
        $props = $this->props();

        $expected = $this->asTenant(fn (): array => app(InstrumentGrid::class)->build(
            Instrument::findOrFail($this->teste1()->id),
            new InstrumentGridOptions,
        ));

        // Through JSON, the way it travels; nothing recomputed, nothing added, nothing dropped.
        $this->assertSame(json_decode(json_encode($expected), true), $props['document']);
        $this->assertSame(['ulid', 'title', 'class_ulid'], array_keys($props['instrument']));
    }

    #[Test]
    public function the_options_in_the_query_reach_the_builder_and_come_back_in_the_document(): void
    {
        $props = $this->props('?bruta=0&dominios=0&legenda=0&orientacao=horizontal');

        $this->assertSame(
            ['raw_score' => false, 'result' => true, 'domains' => false, 'legend' => false, 'orientation' => 'landscape'],
            $props['document']['options'],
        );
        $this->assertSame('landscape', $props['document']['orientation']);
        $this->assertSame(['item', 'item', 'result', 'level'], array_column($props['document']['blocks'][0]['columns'], 'kind'));
        $this->assertNull($props['document']['legend']['questions']);

        // The same query through the builder gives the same document.
        $expected = $this->asTenant(fn (): array => app(InstrumentGrid::class)->build(
            Instrument::findOrFail($this->teste1()->id),
            InstrumentGridOptions::fromQuery(['bruta' => '0', 'dominios' => '0', 'legenda' => '0', 'orientacao' => 'horizontal']),
        ));
        $this->assertSame(json_decode(json_encode($expected), true), $props['document']);
    }

    #[Test]
    public function forged_options_are_the_default_and_never_an_error(): void
    {
        $props = $this->props('?bruta[]=0&resultado=banana&orientacao=diagonal&inventada=1');

        $this->assertSame(
            ['raw_score' => true, 'result' => true, 'domains' => true, 'legend' => true, 'orientation' => 'auto'],
            $props['document']['options'],
        );
    }

    #[Test]
    public function a_provisional_grid_says_so_and_a_concluded_one_does_not(): void
    {
        $provisional = $this->props()['document'];

        $this->assertTrue($provisional['state']['is_provisional']);
        $this->assertSame('PROVISÓRIO', $provisional['state']['label']);

        $this->asTenant(function (): void {
            $instrument = $this->teste1();
            $enrollments = $instrument->schoolClass->enrollments()->get()->keyBy('class_number');
            $items = $instrument->items()->orderBy('sequence')->get();

            // Number 4 and 5 have nothing yet: settle them so the correction can close.
            $cells = [];
            foreach ([4, 5] as $number) {
                foreach ($items as $item) {
                    $cells[] = ['enrollment_id' => $enrollments[$number]->id, 'instrument_item_id' => $item->id, 'result_state' => ResultState::Absent->value];
                }
            }
            app(RecordScores::class)->save($instrument, $cells, $this->teacher);
            app(CompleteCorrection::class)->complete($this->teste1(), $this->teacher);
        });

        $concluded = $this->props()['document'];

        $this->assertFalse($concluded['state']['is_provisional']);
        $this->assertSame('Correção concluída', $concluded['state']['label']);
    }

    #[Test]
    public function a_diagnostic_instrument_is_flagged_in_the_document(): void
    {
        $numeros = $this->asTenant(fn (): Domain => Domain::where('name', 'Números e Operações')->firstOrFail());
        $ulid = $this->asTenant(fn (): string => app(InstrumentBuilder::class)->create($this->teste1()->schoolClass, [
            'academic_period_id' => $this->teste1()->academic_period_id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Diagnóstico', 'applied_on' => '2026-10-15', 'status' => 'in_correction',
            'counts_toward_classification' => false, 'purpose' => 'diagnostic', 'total_points' => 10,
        ], [['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]]])->ulid);

        $document = null;
        $this->actingAs($this->teacher)->get("/instruments/{$ulid}/impressao")->assertOk()->assertInertia(function ($page) use (&$document): void {
            $document = $page->toArray()['props']['document'];
        });

        $this->assertTrue($document['is_diagnostic']);
        $this->assertSame('Diagnóstica', $document['meta']['purpose']);
    }

    // ============================================================ rascunho, anulado

    #[Test]
    public function a_draft_goes_back_to_the_editor_and_an_annulled_instrument_back_to_its_page(): void
    {
        $draft = $this->asTenant(fn (): Instrument => app(InstrumentBuilder::class)->create($this->teste1()->schoolClass, [
            'academic_period_id' => $this->teste1()->academic_period_id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Rascunho', 'applied_on' => '2026-10-15', 'status' => 'draft',
            'counts_toward_classification' => true, 'purpose' => 'summative', 'total_points' => 10,
        ], [['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10]]));

        $this->actingAs($this->teacher)->get($this->url($draft))->assertRedirect(route('instruments.edit', $draft->ulid));

        $this->asTenant(function (): void {
            $this->teste1()->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Anulado'])->save();
        });

        $this->actingAs($this->teacher)->get($this->url())->assertRedirect(route('instruments.show', $this->teste1()->ulid));
    }

    // ============================================================= autorização

    #[Test]
    public function a_guest_is_sent_to_login(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }

    #[Test]
    public function an_instrument_of_another_organization_does_not_resolve(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get($this->url())->assertNotFound();
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_gets_403_and_no_data(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);

        $this->withSession(['organization_id' => $this->organization->id]);

        $response = $this->actingAs($colleague)->get($this->url());

        $response->assertForbidden();
        $this->assertStringNotContainsString('Aluno 1', (string) $response->getContent());
    }

    #[Test]
    public function an_altered_ulid_is_not_found(): void
    {
        $this->actingAs($this->teacher)->get('/instruments/01JZZZZZZZZZZZZZZZZZZZZZZZ/impressao')->assertNotFound();
        $this->actingAs($this->teacher)->get('/instruments/não-é-um-ulid/impressao')->assertNotFound();
    }

    #[Test]
    public function the_base_plan_may_open_it_and_a_blocked_module_may_not(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'base')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)->get($this->url())->assertOk();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->getKey(),
            'module_id' => Module::where('key', 'instruments')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)->get($this->url())->assertForbidden();
    }

    // =================================================== o que nunca faz / usa

    #[Test]
    public function opening_it_writes_nothing_of_the_assessment(): void
    {
        $tables = ['student_item_scores', 'classifications', 'calculation_snapshots', 'instruments', 'instrument_items', 'audit_events', 'reports'];
        $count = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), $tables);

        $before = $count();
        $this->props();
        $this->props('?bruta=0&orientacao=vertical');

        $this->assertSame($before, $count());
        $this->assertSame(0, AuditEvent::where('event', 'like', 'instruments.grid_document%')->count());
    }

    #[Test]
    public function no_ai_service_is_called(): void
    {
        $this->mock(AiGateway::class);

        $this->props();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_scores_of_the_instrument_are_untouched_by_a_view(): void
    {
        $scores = fn (): array => $this->asTenant(
            fn (): array => StudentItemScore::query()->orderBy('id')->get(['id', 'result_state', 'points_earned', 'lock_version'])->toArray(),
        );
        $before = $scores();

        $this->props();

        $this->assertSame($before, $scores());
    }

    #[Test]
    public function the_route_is_its_own_and_not_the_spreadsheet_download(): void
    {
        $ulid = $this->teste1()->ulid;

        $this->assertSame(url("/instruments/{$ulid}/impressao"), route('instruments.grid-document', $ulid));
        $this->assertNotSame(route('instruments.grid', $ulid), route('instruments.grid-document', $ulid));
    }
}
