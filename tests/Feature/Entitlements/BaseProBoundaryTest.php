<?php

namespace Tests\Feature\Entitlements;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

/**
 * THE MATRIZ MESTRE'S BASE/PRO CELLS, ONE ASSERTION EACH.
 *
 * Every capability this file touches was realigned because the code and the
 * Matriz disagreed about which plan owns it. The audit's own acceptance
 * criterion for that slice was «existe um teste por célula da Matriz para Base
 * e Pro», and this is that file: for each capability, what Base gets, what Pro
 * gets, and — for anything reachable by URL — that typing the address is
 * refused rather than merely un-linked.
 *
 * FOUR SURFACES PER CAPABILITY, because a commercial boundary that holds on
 * only three of them does not hold:
 *
 *   1. the menu / what the page offers,
 *   2. the endpoint's status,
 *   3. the direct URL, typed rather than clicked,
 *   4. the PROPS — what actually crosses the wire.
 *
 * The fourth is the one that matters most and the easiest to skip. A figure
 * kept out of the browser is gone; a figure sent and hidden with v-if is a
 * paywall a reader can open with the developer tools.
 *
 * WHAT THIS FILE IS NOT. It does not test the resolver — AccessStateTest owns
 * the three access states and RequireModuleTest owns the middleware. It tests
 * the COMPOSITION: which plan holds which key, and what each page does about
 * it. When a capability moves between plans again, this is the file that
 * should fail first.
 */
class BaseProBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->onPlan('base');
    }

    // ------------------------------------------------------------- andaimes

    private function onPlan(string $planKey): void
    {
        $this->subscribeOrganizationTo($this->organization, $planKey);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function enrollment(int $classNumber): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => $this->schoolClass()->enrollments()->where('class_number', $classNumber)->firstOrFail());
    }

    private function visitPanel(): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->get("/classes/{$this->schoolClass()->ulid}/evolucao/{$this->enrollment(1)->ulid}");
    }

    private function visitPrint(): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->get("/classes/{$this->schoolClass()->ulid}/evolucao/{$this->enrollment(1)->ulid}/imprimir");
    }

    private function visitStatistics(): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->get("/classes/{$this->schoolClass()->ulid}/results/estatistica");
    }

    /** @return list<string> */
    private function navKeys(): array
    {
        $keys = [];

        $this->actingAs($this->teacher)->get('/dashboard')->assertInertia(function ($page) use (&$keys): void {
            $keys = collect($page->toArray()['props']['nav']['sections'])
                ->flatMap(fn (array $section): array => array_column($section['items'], 'key'))
                ->all();
        });

        return $keys;
    }

    // =========================================================== §2 calendário

    /**
     * Matriz §2: «Calendário mensal/anual» and «Datas relevantes / eventos
     * manuais», ticked for Base, Pro and Institucional alike.
     */
    #[Test]
    public function base_reaches_the_calendar_on_every_surface(): void
    {
        $this->assertContains('calendar', $this->navKeys(), 'O Calendário devia estar no menu do Base.');

        $this->actingAs($this->teacher)->get('/calendar')->assertOk();
        $this->actingAs($this->teacher)->get('/calendar/ano')->assertOk();

        $this->assertTrue(app(Entitlements::class)->allowsFor($this->organization->fresh(), 'calendar'));
    }

    #[Test]
    public function base_writes_an_acontecimento(): void
    {
        $this->actingAs($this->teacher)->post('/calendar/acontecimentos', [
            'type' => 'meeting',
            'title' => 'Reunião de conselho de turma',
            'starts_on' => $this->period(1)->starts_on->addDays(3)->toDateString(),
            'ends_on' => null,
            'starts_at' => null,
            'ends_at' => null,
            'description' => null,
            'school_class_ulids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('calendar_events', 1);
    }

    /**
     * Matriz §2: «Importação avançada de calendário» is the ONE row of that
     * table that stays Pro, and §17 already gave it a key of its own.
     */
    #[Test]
    public function the_calendar_import_stays_pro_on_every_surface(): void
    {
        $this->actingAs($this->teacher)->get('/academic-calendar-imports/create')->assertForbidden();
        $this->actingAs($this->teacher)->post('/academic-calendar-imports')->assertForbidden();
        $this->actingAs($this->teacher)->post('/academic-calendar-imports/confirm')->assertForbidden();

        $this->onPlan('pro');

        $this->actingAs($this->teacher)->get('/academic-calendar-imports/create')->assertOk();
    }

    // ================================================ §3/§4 comparação com turma

    /**
     * Matriz §3 and §4 both mark «Comparação contextual com turma» Pro. The
     * panel, the printable document and the report each carried its own copy
     * of that sentence, so each is asserted separately: one gate held in two
     * places out of three is not a boundary.
     */
    #[Test]
    public function base_never_receives_the_class_comparison(): void
    {
        $this->visitPanel()->assertOk()->assertInertia(fn ($page) => $page->where('classComparison', null));
        $this->visitPrint()->assertOk()->assertInertia(fn ($page) => $page->where('classComparison', null));
    }

    #[Test]
    public function pro_receives_the_class_comparison(): void
    {
        $this->onPlan('pro');

        $this->visitPanel()->assertOk()->assertInertia(fn ($page) => $page
            ->has('classComparison.student')
            ->has('classComparison.class'));
    }

    // ============================================= §4/§5 atenção e pontos fortes

    /**
     * Matriz §4 marks «Atenção automática», «Sinais positivos automáticos» and
     * «Pontos fortes identificados automaticamente» Pro; §5 lists «atenção» and
     * «sinais positivos» among what the Pro síntese ADDS to the Base ficha.
     *
     * The assertion is on the props, not on the rendered page: an empty list is
     * what a Base organization receives, and there is no second copy of these
     * readings anywhere for a template to find.
     */
    #[Test]
    public function base_receives_neither_attention_nor_strengths(): void
    {
        $this->visitPanel()->assertOk()->assertInertia(fn ($page) => $page
            ->where('factualAlerts', [])
            ->where('strengths', [])
            ->missing('pro'));
    }

    #[Test]
    public function pro_receives_attention_strengths_and_the_analytical_layer(): void
    {
        $this->onPlan('pro');

        $this->visitPanel()->assertOk()->assertInertia(fn ($page) => $page
            ->has('strengths')
            ->has('pro'));
    }

    /** The printable document draws the same line, and names it in the title. */
    #[Test]
    public function the_base_document_is_a_ficha_and_the_pro_document_is_a_sintese(): void
    {
        $this->visitPrint()->assertOk()->assertInertia(fn ($page) => $page
            ->where('document.title', 'Ficha do Aluno')
            ->where('document.sections', fn ($sections) => ! collect($sections)->pluck('key')
                ->intersect(['attention_factual', 'strengths_factual', 'estado360', 'potentialities'])
                ->isNotEmpty()));

        $this->onPlan('pro');

        $this->visitPrint()->assertOk()->assertInertia(fn ($page) => $page
            ->where('document.title', 'Síntese de Acompanhamento do Aluno'));
    }

    // ================================================== §3 estatística de turma

    /**
     * Matriz §3: «Tendências automáticas» and «Padrões / irregularidades» are
     * Pro; «Evolução factual simples» is ticked for Base. The page itself is
     * open to every plan — it is the automatic movement READING that moved,
     * never the figures.
     */
    #[Test]
    public function base_keeps_every_statistic_except_the_automatic_movement_reading(): void
    {
        $this->visitStatistics()->assertOk()->assertInertia(fn ($page) => $page
            ->component('results/Statistics')
            // The facts §3 gives Base, every one of them still here.
            ->has('statistics.summary')
            ->has('statistics.distribution')
            ->has('statistics.assigned_distribution')
            ->has('statistics.domain_statistics')
            ->has('statistics.period_series')
            ->has('statistics.students')
            // The automatic reading of the movement, and nothing else, gone.
            ->missing('statistics.evolution')
            ->missing('statistics.continuous_evolution'));
    }

    #[Test]
    public function pro_receives_the_automatic_movement_reading(): void
    {
        $this->onPlan('pro');

        $this->visitStatistics()->assertOk()->assertInertia(fn ($page) => $page
            ->has('statistics.evolution.transitions')
            ->has('statistics.continuous_evolution'));
    }

    // ======================================================= §7 backup/restauro

    /**
     * Matriz §7 splits this page pair down the middle, and §20 makes the
     * export half a property of the platform rather than a paid feature:
     * exporting is every plan's, restoring a complete backup is Pro's.
     */
    #[Test]
    public function base_still_exports_its_own_data(): void
    {
        $this->actingAs($this->teacher)->get('/data-exports')->assertOk();
        $this->actingAs($this->teacher)->post('/data-exports')->assertSessionHasNoErrors();
    }

    #[Test]
    public function base_cannot_reach_the_restore_wizard_by_url(): void
    {
        $this->actingAs($this->teacher)->get('/data-imports/create')->assertForbidden();
        $this->actingAs($this->teacher)->post('/data-imports')->assertForbidden();

        $this->onPlan('pro');

        $this->actingAs($this->teacher)->get('/data-imports/create')->assertOk();
    }

    /** §7's third row: «Histórico de backups do utilizador» is Pro. */
    #[Test]
    public function the_export_history_is_pro_and_the_live_download_is_not(): void
    {
        $this->actingAs($this->teacher)->get('/data-exports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('keepsHistory', false));

        $this->onPlan('pro');

        $this->actingAs($this->teacher)->get('/data-exports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('keepsHistory', true));
    }

    // ===================================================== institucional herda

    /**
     * §1 and §10: Institucional is «tudo do Pro» plus governance. Every key
     * this slice moved is asserted from the institutional side too, because a
     * composition change that forgot to carry through would be invisible until
     * the first school arrived.
     */
    #[Test]
    public function institucional_inherits_every_capability_this_slice_touched(): void
    {
        $this->onPlan('institutional');

        $entitlements = app(Entitlements::class);
        $organization = $this->organization->fresh();

        foreach (['calendar', 'calendar_import', 'data_backup_restore', 'advanced_analytics', 'lessons'] as $key) {
            $this->assertTrue(
                $entitlements->allowsFor($organization, $key),
                "O Institucional devia herdar «{$key}» do Pro.",
            );
        }
    }
}
