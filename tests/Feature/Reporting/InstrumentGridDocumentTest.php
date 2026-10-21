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
use App\Services\Documents\Pdf\LetterheadLayout;
use App\Services\Reporting\ClassDocuments\InstrumentGrid;
use App\Services\Reporting\ClassDocuments\InstrumentGridOptions;
use App\Services\StudentEnrollmentService;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Smalot\PdfParser\Parser;
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

    // ====================================================================== PDF

    private function pdfUrl(?Instrument $instrument = null, string $query = ''): string
    {
        return '/instruments/'.($instrument ?? $this->teste1())->ulid.'/impressao/pdf'.$query;
    }

    /** The PDF the teacher would download, as bytes. */
    private function pdf(string $query = '', ?Instrument $instrument = null, ?User $as = null): string
    {
        $response = $this->actingAs($as ?? $this->teacher)->get($this->pdfUrl($instrument, $query));
        $response->assertOk();

        return (string) $response->getContent();
    }

    /**
     * The text of each page of a PDF, in page order.
     *
     * @return list<string>
     */
    private function pages(string $bytes): array
    {
        return array_values(array_map(
            fn ($page): string => preg_replace('/\s+/u', ' ', $page->getText()) ?? '',
            (new Parser)->parseContent($bytes)->getPages(),
        ));
    }

    private function isLandscape(string $bytes): bool
    {
        return (bool) preg_match('/MediaBox\s*\[[\d.]+\s+[\d.]+\s+841\.89\d*\s+595\.28\d*\]/', $bytes);
    }

    /** A grid wide and long enough to cross pages both ways: `$questions` questions, `$students` more students. */
    private function longInstrument(int $questions, int $students, string $title = 'Longo'): Instrument
    {
        return $this->asTenant(function () use ($questions, $students, $title): Instrument {
            $class = $this->teste1()->schoolClass;
            $numeros = Domain::where('name', 'Números e Operações')->firstOrFail();
            $geometria = Domain::where('name', 'Geometria')->firstOrFail();
            $service = app(StudentEnrollmentService::class);

            foreach (range(10, 9 + $students) as $n) {
                $service->enrollNew($class, ['name' => "Aluno {$n}", 'class_number' => $n, 'enrolled_on' => '2026-09-14']);
            }

            $items = [];
            foreach (range(1, $questions) as $n) {
                $domain = $n % 2 === 0 ? $geometria : $numeros;
                $items[] = [
                    'group_index' => intdiv($n - 1, 14),
                    'code' => "Q{$n}", 'label' => "Questão {$n}", 'points_possible' => 1,
                    'domains' => [['domain_id' => $domain->id, 'allocation_percent' => 100]],
                ];
            }

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $this->teste1()->academic_period_id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => $title, 'applied_on' => '2026-10-15', 'status' => 'in_correction',
                'counts_toward_classification' => true, 'purpose' => 'summative', 'total_points' => $questions,
            ], $items, array_map(fn (int $g): array => ['label' => 'Grupo '.($g + 1)], range(0, intdiv($questions - 1, 14))));

            $enrollments = $class->enrollments()->orderBy('class_number')->get();
            $ids = $instrument->items()->orderBy('sequence')->pluck('id')->all();
            $cells = [];

            foreach ($enrollments as $i => $enrollment) {
                foreach ($ids as $j => $itemId) {
                    $cells[] = ['enrollment_id' => $enrollment->id, 'instrument_item_id' => $itemId, 'result_state' => ResultState::Assessed->value, 'points_earned' => ($i + $j) % 3 === 0 ? 0.0 : 1.0];
                }
            }
            app(RecordScores::class)->save($instrument, $cells, $this->teacher);

            return Instrument::findOrFail($instrument->id);
        });
    }

    #[Test]
    public function the_pdf_is_a_real_attachment_that_is_never_cached_and_never_named_after_a_student(): void
    {
        $response = $this->actingAs($this->teacher)->get($this->pdfUrl());

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment; filename="grelha-8o-a-teste-1-2026-10-15', $disposition);
        $this->assertStringNotContainsString('aluno', strtolower($disposition));

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    #[Test]
    public function the_pdf_is_as_closed_as_the_preview(): void
    {
        $this->get($this->pdfUrl())->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get($this->pdfUrl())->assertNotFound();

        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);
        $this->withSession(['organization_id' => $this->organization->id]);
        $forbidden = $this->actingAs($colleague)->get($this->pdfUrl());
        $forbidden->assertForbidden();
        $this->assertStringStartsNotWith('%PDF', (string) $forbidden->getContent());
        $this->assertStringNotContainsString('Aluno 1', (string) $forbidden->getContent());

        $this->actingAs($this->teacher)->get('/instruments/01JZZZZZZZZZZZZZZZZZZZZZZZ/impressao/pdf')->assertNotFound();
    }

    #[Test]
    public function the_pdf_follows_the_base_plan_and_a_blocked_module(): void
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

        $this->actingAs($this->teacher)->get($this->pdfUrl())->assertOk();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->getKey(),
            'module_id' => Module::where('key', 'instruments')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)->get($this->pdfUrl())->assertForbidden();
    }

    #[Test]
    public function a_draft_and_an_annulled_instrument_get_no_pdf_either(): void
    {
        $draft = $this->asTenant(fn (): Instrument => app(InstrumentBuilder::class)->create($this->teste1()->schoolClass, [
            'academic_period_id' => $this->teste1()->academic_period_id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Rascunho', 'applied_on' => '2026-10-15', 'status' => 'draft',
            'counts_toward_classification' => true, 'purpose' => 'summative', 'total_points' => 10,
        ], [['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10]]));

        $this->actingAs($this->teacher)->get($this->pdfUrl($draft))->assertRedirect(route('instruments.edit', $draft->ulid));

        $this->asTenant(function (): void {
            $this->teste1()->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Anulado'])->save();
        });

        $this->actingAs($this->teacher)->get($this->pdfUrl())->assertRedirect(route('instruments.show', $this->teste1()->ulid));
        $this->assertSame(0, AuditEvent::where('event', 'instruments.grid_document.exported')->count());
    }

    #[Test]
    public function exporting_leaves_exactly_one_audit_event_with_counts_and_never_a_name_or_a_mark(): void
    {
        $tables = ['student_item_scores', 'classifications', 'calculation_snapshots', 'instruments', 'instrument_items', 'reports'];
        $count = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), $tables);
        $before = $count();

        $this->pdf('?bruta=0');

        $this->assertSame($before, $count(), 'nothing of the assessment is written');

        $events = AuditEvent::where('event', 'instruments.grid_document.exported')->get();
        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertSame($this->teacher->id, $event->causer_id);
        // MySQL gives a JSON column back with its keys reordered: compare the payload, not the order.
        $this->assertSameJsonPayload([
            'format' => 'pdf',
            'orientation' => 'portrait',
            'options' => ['raw_score' => false, 'result' => true, 'domains' => true, 'legend' => true, 'orientation' => 'auto'],
            'provisional' => true,
            'students' => 5,
            'excluded' => 1,
            'questions' => 2,
            'blocks' => 1,
        ], collect($event->properties)->only(['format', 'orientation', 'options', 'provisional', 'students', 'excluded', 'questions', 'blocks'])->all());

        $stored = json_encode([$event->summary, $event->properties], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Aluno', (string) $stored);
        $this->assertStringContainsString('Teste 1', (string) $event->summary);
    }

    #[Test]
    public function no_ai_service_is_called_for_the_pdf(): void
    {
        $this->mock(AiGateway::class);

        $this->pdf();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_pdf_carries_every_cell_the_preview_carries_and_the_options_choose_the_same_columns(): void
    {
        $document = $this->props()['document'];
        $pages = implode(' ', $this->pages($this->pdf()));

        foreach ($document['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                $this->assertStringContainsString($row['name'], $pages);
            }
            foreach ($block['columns'] as $column) {
                $this->assertStringContainsString($column['kind'] === 'item' ? $column['code'] : $column['label'], $pages);
            }
        }
        $this->assertStringContainsString('PROVISÓRIO', $pages);
        $this->assertStringContainsString('não é a classificação do período', $pages);

        // The same options the preview takes choose the same columns on paper.
        $without = implode(' ', $this->pages($this->pdf('?bruta=0&legenda=0&dominios=0')));
        $this->assertStringNotContainsString('Pontuação bruta', $without);
        $this->assertStringNotContainsString('Questões', $without);
        $this->assertStringNotContainsString('Geometria', $without);
        $this->assertStringContainsString('Resultado', $without);
    }

    #[Test]
    public function the_blade_draws_exactly_the_document_it_is_given_cell_for_cell(): void
    {
        $instrument = $this->longInstrument(40, 8, 'Paridade');
        $document = $this->asTenant(fn (): array => app(InstrumentGrid::class)->build(Instrument::findOrFail($instrument->id), new InstrumentGridOptions));
        $html = View::make('instruments.grid-document', ['document' => $document, 'layout' => LetterheadLayout::Compact])->render();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new DOMXPath($dom);
        // A line break between a figure and its level is, to a reader, a space.
        foreach (iterator_to_array($xpath->query('//br')) as $break) {
            $break->parentNode->replaceChild($dom->createTextNode(' '), $break);
        }
        $clean = fn (string $text): string => trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        $tables = $xpath->query('//table[contains(@class, "grid")]');
        $this->assertSame(count($document['blocks']), $tables->length);

        foreach ($document['blocks'] as $index => $block) {
            $table = $tables->item($index);

            // Captions only when there are several blocks.
            $captions = $xpath->query('./preceding-sibling::h2', $table);
            $this->assertSame($block['caption'], $captions->length > 0 ? $clean($captions->item($captions->length - 1)->textContent) : null);

            // Header: Nº, Nome, then each column's header.
            $headers = [];
            foreach ($xpath->query('./thead/tr[not(@class)]/th', $table) as $th) {
                $headers[] = $clean($th->textContent);
            }
            $expectedHeaders = ['Nº', 'Nome', ...array_map(fn (array $column): string => $column['kind'] === 'item' ? $column['code'] : $column['label'], $block['columns'])];
            $this->assertSame($expectedHeaders, $headers, "block {$index}");

            // The group line: the labels, in order, with their spans.
            $spans = [];
            foreach ($xpath->query('./thead/tr[contains(@class, "groups")]/th[position() > 1]', $table) as $th) {
                $spans[] = ['label' => $clean($th->textContent) ?: null, 'span' => (int) $th->getAttribute('colspan')];
            }
            $this->assertSame($block['group_spans'] ?? [], $spans, "block {$index}");

            // Every row, every cell, in order.
            $rows = [];
            foreach ($xpath->query('./tbody/tr', $table) as $tr) {
                $cells = [];
                foreach ($xpath->query('./td', $tr) as $td) {
                    $cells[] = $clean($td->textContent);
                }
                $rows[] = $cells;
            }
            $expectedRows = array_map(fn (array $row): array => [
                $row['number'], $row['name'],
                ...array_map(fn (array $cell): string => $clean($cell['text'].($cell['detail'] !== null ? ' '.$cell['detail'] : '')), $row['cells']),
            ], $block['rows']);
            $this->assertSame($expectedRows, $rows, "block {$index}");
        }

        // The legend, the notes, the notice: all of it, as composed.
        $text = $clean($dom->textContent);
        foreach ($document['legend']['questions'] as $question) {
            $this->assertStringContainsString($question['label'], $text);
        }
        foreach ($document['notes'] as $note) {
            $this->assertStringContainsString($clean($note), $text);
        }
        $this->assertStringContainsString($document['notice'], $text);
    }

    #[Test]
    public function the_provisional_stamp_is_drawn_for_a_provisional_grid_and_for_no_other(): void
    {
        $provisional = $this->asTenant(fn (): array => app(InstrumentGrid::class)->build(Instrument::findOrFail($this->teste1()->id), new InstrumentGridOptions));
        $html = View::make('instruments.grid-document', ['document' => $provisional, 'layout' => LetterheadLayout::Standard])->render();
        $this->assertStringContainsString('id="provisional"', $html);

        $concluded = $provisional;
        $concluded['state'] = ['is_provisional' => false, 'label' => 'Correção concluída'];
        $html = View::make('instruments.grid-document', ['document' => $concluded, 'layout' => LetterheadLayout::Standard])->render();
        $this->assertStringNotContainsString('id="provisional"', $html);
    }

    #[Test]
    public function a_short_grid_is_a_portrait_page_and_the_orientation_option_is_respected(): void
    {
        $this->assertFalse($this->isLandscape($this->pdf()));
        $this->assertTrue($this->isLandscape($this->pdf('?orientacao=horizontal')));
        $this->assertFalse($this->isLandscape($this->pdf('?orientacao=vertical')));
    }

    #[Test]
    public function an_extensive_grid_is_landscape_multipage_and_loses_no_question_no_student_and_no_column(): void
    {
        $instrument = $this->longInstrument(40, 22, 'Extenso');
        $bytes = $this->pdf('', $instrument);
        $pages = $this->pages($bytes);
        $all = implode(' ', $pages);

        $this->assertTrue($this->isLandscape($bytes), 'forty questions do not fit a portrait sheet');
        $this->assertGreaterThan(3, count($pages), 'three blocks of columns, each on its own page, and 27 rows');

        // Every question, in every block it belongs to, and the three parts.
        foreach (range(1, 40) as $n) {
            $this->assertMatchesRegularExpression("/\\bQ{$n}\\b/", $all, "Q{$n} was lost");
        }
        foreach ([1, 2, 3] as $part) {
            $this->assertStringContainsString("Parte {$part} de 3", $all);
        }

        // Every student appears, and the name is repeated in each block.
        foreach (array_merge(range(1, 5), range(10, 31)) as $n) {
            $this->assertGreaterThanOrEqual(3, substr_count($all, "Aluno {$n} "), "Aluno {$n} is not in every block");
        }

        // The summary columns are in the last block, so they are there.
        foreach (['Pontuação bruta', 'Resultado', 'Nível', 'Números e Operações', 'Geometria'] as $header) {
            $this->assertStringContainsString($header, $all);
        }
    }

    #[Test]
    public function every_page_has_its_number_out_of_the_total_and_repeats_the_headings(): void
    {
        $instrument = $this->longInstrument(40, 22, 'Extenso');
        $pages = $this->pages($this->pdf('', $instrument));
        $total = count($pages);

        foreach ($pages as $index => $text) {
            $this->assertStringContainsString('Página '.($index + 1).' / '.$total, $text, 'page '.($index + 1));
            // The heading of whichever table the page carries repeats: the grid's
            // «Nº Nome» and the question legend's «Código», and so does the stamp of
            // a provisional grid.
            $heading = str_contains($text, 'Descrição Cotação Domínios') ? 'Código' : 'Nome';
            $this->assertStringContainsString($heading, $text, 'page '.($index + 1).' lost its heading');
            $this->assertStringContainsString('PROVISÓRIO', $text, 'page '.($index + 1).' is not stamped');
        }
    }

    #[Test]
    public function a_concluded_grid_carries_no_provisional_stamp_on_any_page(): void
    {
        $this->asTenant(function (): void {
            $instrument = $this->teste1();
            $enrollments = $instrument->schoolClass->enrollments()->get()->keyBy('class_number');
            $items = $instrument->items()->orderBy('sequence')->get();

            // Numbers 4 and 5 have nothing yet: settle them so the correction can close.
            $cells = [];
            foreach ([4, 5] as $number) {
                foreach ($items as $item) {
                    $cells[] = ['enrollment_id' => $enrollments[$number]->id, 'instrument_item_id' => $item->id, 'result_state' => ResultState::Absent->value];
                }
            }
            app(RecordScores::class)->save($instrument, $cells, $this->teacher);
            app(CompleteCorrection::class)->complete($this->teste1(), $this->teacher);
        });

        $pages = $this->pages($this->pdf());

        foreach ($pages as $text) {
            $this->assertStringNotContainsString('PROVISÓRIO', $text);
        }
        $this->assertStringContainsString('Correção concluída', implode(' ', $pages));
    }

    #[Test]
    public function the_route_is_its_own_and_not_the_spreadsheet_download(): void
    {
        $ulid = $this->teste1()->ulid;

        $this->assertSame(url("/instruments/{$ulid}/impressao"), route('instruments.grid-document', $ulid));
        $this->assertNotSame(route('instruments.grid', $ulid), route('instruments.grid-document', $ulid));
    }
}
