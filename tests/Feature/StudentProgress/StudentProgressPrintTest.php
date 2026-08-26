<?php

namespace Tests\Feature\StudentProgress;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acompanhamento do Aluno — print/PDF output.
 *
 * ONE PRINT INFRASTRUCTURE, PROVEN FROM THE OUTSIDE. Every test below drives
 * the route exactly as a browser would and reads only the Inertia props —
 * never the Vue template — because the requirement is that the SERVER never
 * sends the analytical layer at all when the capability is absent (§15),
 * not that the client happens to hide it afterwards.
 *
 * SAME SCENARIO, SAME GUARDS AS THE PANEL. Built on the same
 * ana.martins@lapis.test / 7.º A demo scenario as StudentProgressPanelTest,
 * and every access test compares the print route's behaviour directly
 * against the panel route's, because the brief requires the print route to
 * enforce exactly the guards the panel already does — never a shortcut.
 */
class StudentProgressPrintTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->seed(EntitlementsSeeder::class);
        $this->givePlan('base');
    }

    // ------------------------------------------------------------- andaimes

    protected function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
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

    private function enrollment(int $classNumber): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => $this->schoolClass()->enrollments()->where('class_number', $classNumber)->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function visitPanel(Enrollment $enrollment, ?User $as = null): TestResponse
    {
        $class = $this->schoolClass();

        return $this->actingAs($as ?? $this->teacher)->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}");
    }

    private function visitPrint(Enrollment $enrollment, ?User $as = null): TestResponse
    {
        $class = $this->schoolClass();

        return $this->actingAs($as ?? $this->teacher)->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/imprimir");
    }

    private function record(Enrollment $enrollment, EvidenceKind $kind, string $occurredAt, array $extra = []): void
    {
        $this->asTenant(function () use ($enrollment, $kind, $occurredAt, $extra): void {
            EvidenceRecord::create([
                'class_id' => $enrollment->class_id,
                'enrollment_id' => $enrollment->id,
                'kind' => $kind->value,
                'occurred_at' => $occurredAt,
                'description' => 'Registo de teste.',
                'created_by' => $this->teacher->id,
                ...$extra,
            ]);
        });
    }

    /**
     * The raw JSON of every prop the print route sends — used to scan the
     * WHOLE payload for a forbidden substring, exactly the way a browser
     * receiving this response would see it, never merely the fields a
     * narrower assertion happens to look at.
     */
    private function propsJson(TestResponse $response): string
    {
        $response->assertViewHas('page');
        $page = json_decode(json_encode($response->viewData('page')), true);

        return json_encode($page['props']);
    }

    // ------------------------------------------------------------- §1: acesso

    #[Test]
    public function an_authorized_teacher_can_open_the_print_view(): void
    {
        $this->visitPrint($this->enrollment(1))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('student-progress/Print')
                ->has('document.title')
                ->has('document.sections')
                ->where('student.name', fn ($name) => is_string($name) && $name !== ''));
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden_from_the_print_view(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $this->organization->id])
            ->actingAs($colleague)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/imprimir")
            ->assertForbidden();
    }

    #[Test]
    public function an_enrollment_from_another_class_returns_not_found(): void
    {
        $class = $this->schoolClass();

        $other = $this->asTenant(function () use ($class): Enrollment {
            $second = SchoolClass::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => '7.º B',
            ]);

            return Enrollment::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'class_id' => $second->getKey(),
            ]);
        });

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$other->ulid}/imprimir")
            ->assertNotFound();
    }

    #[Test]
    public function another_organizations_user_cannot_reach_the_print_view(): void
    {
        $enrollment = $this->enrollment(1);
        $stranger = User::factory()->create(['email' => 'estranho@lapis.test']);

        $this->visitPrint($enrollment, $stranger)->assertNotFound();
    }

    /**
     * §: "direct URL access enforces the same guards as the panel" — proven
     * directly, on the SAME fixtures, rather than trusted because the two
     * methods happen to share two lines of code.
     */
    #[Test]
    public function the_print_route_enforces_exactly_the_guards_the_panel_route_enforces(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $panelForbidden = $this->withSession(['organization_id' => $this->organization->id])
            ->actingAs($colleague)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}");
        $printForbidden = $this->withSession(['organization_id' => $this->organization->id])
            ->actingAs($colleague)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/imprimir");

        $panelForbidden->assertForbidden();
        $printForbidden->assertForbidden();

        $stranger = User::factory()->create(['email' => 'outro-estranho@lapis.test']);
        $this->visitPanel($enrollment, $stranger)->assertNotFound();
        $this->visitPrint($enrollment, $stranger)->assertNotFound();
    }

    // -------------------------------------------------------- §2: composição

    #[Test]
    public function the_factual_document_contains_the_expected_factual_sections_and_no_analytical_ones(): void
    {
        $enrollment = $this->enrollment(2);
        $period = $this->period(2);
        $this->record($enrollment, EvidenceKind::Homework, $period->starts_on->addDays(5)->toDateString(), ['homework_status' => HomeworkStatus::NotDone->value]);
        $this->record($enrollment, EvidenceKind::PositiveBehaviour, $period->starts_on->addDays(6)->toDateString());

        $this->actingAs($this->teacher)->post("/classes/{$this->schoolClass()->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_type' => 'learning_reinforcement',
            'domain_relation' => 'none',
            'started_on' => '2026-11-10',
            'purpose' => 'recovery',
        ])->assertSessionHasNoErrors();

        $this->visitPrint($enrollment)->assertInertia(function ($page) {
            $page->missing('pro');
            $page->where('document.title', 'Ficha do Aluno');
            $page->where('document.sections', function ($sections) {
                $keys = collect($sections)->pluck('key')->all();

                foreach (['current_situation', 'domains', 'attention_factual', 'strengths_factual', 'records', 'strategies'] as $expected) {
                    $this->assertContains($expected, $keys, "Esperava-se a secção factual «{$expected}».");
                }

                // Quem o aluno é vive no cabeçalho do documento, e em mais
                // lado nenhum: uma secção «Identificação» logo a seguir
                // repetia-o inteiro (§2 da revisão de impressão).
                $this->assertNotContains('identification', $keys);

                foreach (['attention_analytical', 'positive_signals', 'estado360', 'what_changed', 'potentialities'] as $forbidden) {
                    $this->assertNotContains($forbidden, $keys, "A secção analítica «{$forbidden}» não devia constar de uma ficha Base.");
                }

                $this->assertTrue(collect($sections)->every(fn (array $section): bool => $section['capability'] === 'student_progress'));

                return true;
            });

            return true;
        });
    }

    #[Test]
    public function analytical_sections_and_the_pro_payload_appear_only_when_advanced_analytics_is_allowed(): void
    {
        $this->givePlan('pro');

        $this->visitPrint($this->enrollment(1))->assertInertia(function ($page) {
            $page->where('document.title', 'Síntese de Acompanhamento do Aluno');
            $page->has('pro.estado360.dimensions', 8);
            $page->where('document.sections', function ($sections) {
                $keys = collect($sections)->pluck('key')->all();

                $this->assertContains('estado360', $keys);
                $this->assertContains('what_changed', $keys);

                $analytical = collect($sections)->where('key', 'estado360')->first();
                $this->assertSame('advanced_analytics', $analytical['capability']);

                return true;
            });

            return true;
        });
    }

    #[Test]
    public function downgrading_the_plan_removes_the_pro_payload_and_the_analytical_sections_on_the_same_route(): void
    {
        $enrollment = $this->enrollment(1);

        $this->givePlan('pro');
        $this->visitPrint($enrollment)->assertInertia(fn ($page) => $page
            ->where('document.title', 'Síntese de Acompanhamento do Aluno')
            ->has('pro'));

        // The SAME route, the same student, only the plan changed — and the
        // analytical layer genuinely disappears from the payload (§15, §21).
        $this->givePlan('base');
        $this->visitPrint($enrollment)->assertInertia(function ($page) {
            $page->missing('pro');
            $page->where('document.title', 'Ficha do Aluno');
            $page->where('document.sections', fn ($sections) => ! collect($sections)->pluck('capability')->contains('advanced_analytics'));

            return true;
        });
    }

    /**
     * §20: the trial/voucher mechanism, proven for free. A Base plan with an
     * in-force override granting `advanced_analytics` prints the full
     * Síntese — no new code, only the existing override table.
     */
    #[Test]
    public function an_in_force_override_grants_the_synthesis_on_an_otherwise_base_organization(): void
    {
        $enrollment = $this->enrollment(1);

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->getKey(),
            'module_id' => Module::where('key', 'advanced_analytics')->firstOrFail()->getKey(),
            'enabled' => true,
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->addDays(14),
            'reason' => 'Período de avaliação (trial).',
        ]);
        app(Entitlements::class)->flush();

        $this->visitPrint($enrollment)->assertInertia(fn ($page) => $page
            ->where('document.title', 'Síntese de Acompanhamento do Aluno')
            ->has('pro.estado360.dimensions', 8)
            ->where('document.sections', fn ($sections) => collect($sections)->pluck('key')->contains('estado360')));
    }

    #[Test]
    public function the_printed_document_never_contains_locked_or_upsell_content(): void
    {
        $response = $this->visitPrint($this->enrollment(1))->assertOk();
        $json = $this->propsJson($response);

        foreach ([
            'Disponível no Pro', 'Disponível apenas no', 'Bloqueado', '🔒',
            'Exclusivo Pro', 'Faça upgrade', 'Adquira o plano', 'Subscreva',
            'upsell', 'locked',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "O documento impresso não deve conter «{$forbidden}».");
        }
    }

    // -------------------------------------------------------------------- §3: IA

    #[Test]
    public function an_unaccepted_ai_suggestion_never_reaches_the_print_route(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => 'fake']);
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willReturn(
            "NOME: Leitura orientada\nOBJETIVO: melhorar a leitura.\nAPLICACAO: leitura orientada.\nFREQUENCIA: 2x por semana\nDURACAO: 4 semanas\nINDICADOR: número de textos lidos\nREVISAO: daqui a 4 semanas",
        );
        $this->app->instance(FakeAiTextProvider::class, $engine);

        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);
        $domain = $this->asTenant(fn () => app(BuildStudentProgress::class)
            ->for($class, $enrollment)['domains']['rows'][0]);

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia", [
                'domain_id' => $domain['domain_id'],
                'purpose' => 'recovery',
            ])->assertRedirect();

        $this->assertIsArray(session('aiSuggestion'), 'A sugestão devia ter sido guardada na sessão, tal como no painel.');

        $response = $this->visitPrint($enrollment)->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('ai')
            ->missing('aiSuggestion')
            ->missing('aiPurposeSuggestions')
            ->missing('aiPurposeOptions'));

        $json = $this->propsJson($response);
        $this->assertStringNotContainsString('Leitura orientada', $json, 'Uma sugestão de IA não aceite nunca deve chegar ao documento impresso.');
    }

    #[Test]
    public function an_accepted_and_recorded_strategy_appears_normally_under_estrategias_e_medidas(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_type' => 'learning_reinforcement',
            'domain_relation' => 'none',
            'started_on' => '2026-11-10',
            'purpose' => 'recovery',
            'frequency' => '2x por semana',
            'tracking_indicator' => 'n.º de leituras concluídas',
        ])->assertSessionHasNoErrors();

        $this->visitPrint($enrollment)->assertInertia(fn ($page) => $page
            ->where('document.sections', fn ($sections) => collect($sections)->pluck('key')->contains('strategies'))
            ->where('interventions.rows', fn ($rows) => collect($rows)->pluck('purpose_label')->contains('Recuperação')));
    }

    // -------------------------------------------------------------- §4: formatação

    #[Test]
    public function no_field_on_the_print_route_ever_shows_raw_database_precision(): void
    {
        $this->givePlan('pro');
        $pattern = '/\d+[.,]\d{2,}/';

        foreach ([1, 2, 3, 4, 5, 6] as $classNumber) {
            $enrollment = $this->enrollment($classNumber);

            $this->visitPrint($enrollment)->assertInertia(function ($page) use ($pattern) {
                $page->where('pro', function ($pro) use ($pattern) {
                    $pro = collect($pro);
                    $texts = collect()
                        ->merge(collect($pro->get('analyticalAlerts'))->pluck('sentence'))
                        ->merge(collect($pro->get('positiveSignals'))->pluck('sentence'))
                        ->merge(collect(data_get($pro, 'whatChanged.items'))->pluck('sentence'))
                        ->merge(collect(data_get($pro, 'estado360.dimensions'))->pluck('detail'))
                        ->push(data_get($pro, 'potentialities.narrative'))
                        ->push(data_get($pro, 'potentialities.next_step'))
                        ->filter();

                    foreach ($texts as $text) {
                        $this->assertDoesNotMatchRegularExpression($pattern, $text, "Precisão de base de dados a nu em: {$text}");
                    }

                    return true;
                });
                $page->where('factualAlerts', function ($alerts) use ($pattern) {
                    foreach (collect($alerts)->pluck('sentence') as $sentence) {
                        $this->assertDoesNotMatchRegularExpression($pattern, $sentence);
                    }

                    return true;
                });
                $page->where('strengths', function ($rows) use ($pattern) {
                    foreach (collect($rows)->pluck('sentence') as $sentence) {
                        $this->assertDoesNotMatchRegularExpression($pattern, $sentence);
                    }

                    return true;
                });

                return true;
            });
        }
    }

    #[Test]
    public function the_header_carries_the_real_student_class_subject_and_academic_year(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);
        $name = $this->asTenant(fn () => $enrollment->student->identity->display_name);

        $this->visitPrint($enrollment)->assertInertia(fn ($page) => $page
            ->where('student.name', $name)
            ->where('schoolClass.label', $class->label)
            ->where('schoolClass.subject', $class->subject->name)
            ->where('schoolClass.academic_year', $class->academicYear->label));
    }

    #[Test]
    public function the_title_is_derived_from_the_capability_answer_never_from_a_plan_name(): void
    {
        $enrollment = $this->enrollment(1);

        $this->visitPrint($enrollment)->assertInertia(fn ($page) => $page->where('document.title', 'Ficha do Aluno'));

        $this->givePlan('pro');
        $this->visitPrint($enrollment)->assertInertia(fn ($page) => $page->where('document.title', 'Síntese de Acompanhamento do Aluno'));
    }
}
