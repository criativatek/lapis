<?php

namespace Tests\Feature\Interventions;

use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\InterventionPurpose;
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
use App\Services\Interventions\Ai\InterventionStrategySuggester;
use App\Services\Interventions\Ai\InterventionSuggestionParser;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Sugestões pedagógicas (IA)» — the parts of it not already covered
 * end to end by StudentProgressPanelTest: the parser's own defensiveness, the
 * audit trail's content, and the independence of `ai_assistance` from
 * `advanced_analytics`.
 */
class InterventionAiSuggestionTest extends TestCase
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
    }

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

    /**
     * The domain with the highest accumulated average for this enrollment —
     * guaranteed, elsewhere in this suite, to actually carry a value — so a
     * test that asserts on the factual pattern built from it never depends
     * on which domain happens to sit first in the demo scenario's own order.
     *
     * @return array<string, mixed>
     */
    private function domainRow(Enrollment $enrollment): array
    {
        return $this->asTenant(function () use ($enrollment): array {
            $progress = app(BuildStudentProgress::class)->for($this->schoolClass(), $enrollment);
            $highest = $progress['domains']['highlights']['highest'];

            return collect($progress['domains']['rows'])->firstWhere('domain_id', $highest['domain_id']);
        });
    }

    // ------------------------------------------------------ o parser é defensivo

    #[Test]
    public function a_block_missing_objetivo_or_aplicacao_is_dropped_not_guessed(): void
    {
        $text = implode("\n", [
            'NOME: Leitura orientada',
            'OBJETIVO: melhorar a leitura.',
            // No APLICACAO line at all.
            '---',
            'NOME: Consolidação semanal',
            'OBJETIVO: manter o nível atual.',
            'APLICACAO: leitura orientada semanal.',
        ]);

        $suggestions = InterventionSuggestionParser::parse($text, InterventionPurpose::Consolidation);

        $this->assertCount(1, $suggestions);
        $this->assertSame('consolidation', $suggestions[0]->purpose);
    }

    #[Test]
    public function a_missing_strategy_name_is_kept_as_null_while_the_teacher_chosen_purpose_is_stamped(): void
    {
        $text = "OBJETIVO: x.\nAPLICACAO: y.";

        $suggestions = InterventionSuggestionParser::parse($text, InterventionPurpose::Improvement);

        $this->assertCount(1, $suggestions);
        $this->assertNull($suggestions[0]->name);
        $this->assertSame('improvement', $suggestions[0]->purpose);
    }

    #[Test]
    public function empty_text_produces_no_suggestions(): void
    {
        $this->assertSame([], InterventionSuggestionParser::parse('', InterventionPurpose::Recovery));
    }

    #[Test]
    public function no_more_than_three_suggestions_survive(): void
    {
        $block = "OBJETIVO: x.\nAPLICACAO: y.";
        $text = implode("\n---\n", array_fill(0, 5, $block));

        $this->assertCount(3, InterventionSuggestionParser::parse($text, InterventionPurpose::Recovery));
    }

    // -------------------------------------------------------------- a auditoria

    #[Test]
    public function the_audit_trail_records_metadata_never_the_suggested_text(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => 'fake']);
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willReturn("NOME: Leitura orientada\nOBJETIVO: melhorar a leitura em voz alta.\nAPLICACAO: leitura orientada diária.\nFREQUENCIA: diária\nDURACAO: 4 semanas\nINDICADOR: fluência nas leituras seguintes\nREVISAO: após 3 evidências comparáveis");
        $this->app->instance(FakeAiTextProvider::class, $engine);

        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);
        $domain = $this->domainRow($enrollment);

        $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_type' => 'learning_reinforcement',
            'domain_relation' => 'specific',
            'domain_id' => $domain['domain_id'],
            'strategy_label' => 'Leitura modelada',
            'objective' => 'Consolidar a fluência leitora.',
            'description' => 'Texto livre que nunca deve sair para o fornecedor.',
            'started_on' => '2026-11-10',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia", [
                'domain_id' => $domain['domain_id'],
                'purpose' => 'improvement',
                'teacher_objective' => 'Aprofundar a leitura expressiva.',
            ])
            ->assertRedirect();

        $event = $this->asTenant(fn (): ?AuditEvent => AuditEvent::where('event', 'intervention.ai_suggestion_requested')->latest('id')->first());

        $this->assertNotNull($event);
        $this->assertSame('fake', $event->properties['provider']);
        $this->assertSame(1, $event->properties['suggestion_count']);
        $this->assertSame('improvement', $event->properties['purpose']);
        $this->assertTrue($event->properties['teacher_objective_supplied']);
        $this->assertSame(1, $event->properties['existing_strategy_count']);
        $this->assertSame('lapis-intervention-suggestion/2', $event->properties['prompt_version']);

        $encoded = (string) json_encode($event->properties, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('leitura orientada diária', $encoded);
        $this->assertStringNotContainsString('Aprofundar a leitura expressiva', $encoded);
        $this->assertStringNotContainsString('Leitura modelada', $encoded);
        $this->assertStringNotContainsString('Consolidar a fluência leitora', $encoded);

        $request = $engine->lastRequest();
        $this->assertNotNull($request);
        $this->assertStringContainsString('Domínio: '.$domain['name'], $request->content);
        $this->assertStringContainsString('Finalidade escolhida: improvement (Melhoria)', $request->content);
        $this->assertStringContainsString('Padrão factual atual: Resultado atual no domínio:', $request->content);
        $this->assertStringContainsString('<<<INÍCIO DO OBJETIVO DO PROFESSOR>>>', $request->content);
        $this->assertStringContainsString('Aprofundar a leitura expressiva.', $request->content);
        // The prior-strategy line lost its leading «- » when this feature moved
        // onto `AiContext`: a list is now one labelled entry rather than a
        // hand-built bullet, so the label carries the structure the dash used
        // to imply.
        $this->assertStringContainsString('Estratégias já aplicadas neste domínio: Nome: Leitura modelada | Objetivo: Consolidar a fluência leitora.', $request->content);
        $this->assertStringNotContainsString('Texto livre que nunca deve sair para o fornecedor.', $request->content);
        $this->assertStringNotContainsString('2026-11-10', $request->content);
        $this->assertStringNotContainsString($enrollment->student->identity->display_name, $request->content);

        if (($processNumber = $enrollment->student->processNumber()) !== null) {
            $this->assertStringNotContainsString($processNumber, $request->content);
        }
    }

    #[Test]
    public function the_endpoint_requires_a_profile_domain_and_a_valid_teacher_chosen_purpose(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => 'fake']);
        $this->app->instance(FakeAiTextProvider::class, new FakeAiTextProvider('modelo-de-teste'));

        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);
        $url = "/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia";

        $this->actingAs($this->teacher)->from($url)->post($url, [])
            ->assertSessionHasErrors(['domain_id', 'purpose']);

        $this->actingAs($this->teacher)->from($url)->post($url, [
            'domain_id' => 999999,
            'purpose' => 'recovery',
        ])->assertSessionHasErrors(['domain_id']);

        $this->actingAs($this->teacher)->from($url)->post($url, [
            'domain_id' => $this->domainRow($enrollment)['domain_id'],
            'purpose' => 'invented',
        ])->assertSessionHasErrors(['purpose']);
    }

    #[Test]
    public function direct_student_identifiers_in_the_teacher_objective_are_rejected_before_any_ai_request(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => 'fake']);
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $this->app->instance(FakeAiTextProvider::class, $engine);

        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);
        $url = "/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia";

        $this->actingAs($this->teacher)->from($url)->post($url, [
            'domain_id' => $this->domainRow($enrollment)['domain_id'],
            'purpose' => 'recovery',
            'teacher_objective' => 'Apoiar '.$enrollment->student->identity->display_name.' na leitura.',
        ])->assertSessionHasErrors(['teacher_objective']);

        $this->assertSame([], $engine->received);
    }

    #[Test]
    public function all_three_teacher_chosen_purposes_are_supported_end_to_end(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => 'fake']);
        $engine = new FakeAiTextProvider('modelo-de-teste');

        foreach (InterventionPurpose::cases() as $purpose) {
            $engine->willReturn(
                "NOME: Estratégia {$purpose->value}\nOBJETIVO: objetivo pedagógico.\nAPLICACAO: aplicação concreta.\nFREQUENCIA:\nDURACAO:\nINDICADOR: evidências seguintes\nREVISAO: após 3 evidências comparáveis",
            );
        }

        $this->app->instance(FakeAiTextProvider::class, $engine);
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);
        $domain = $this->domainRow($enrollment);
        $url = "/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia";

        foreach (InterventionPurpose::cases() as $purpose) {
            $this->actingAs($this->teacher)->from($url)->post($url, [
                'domain_id' => $domain['domain_id'],
                'purpose' => $purpose->value,
            ])->assertRedirect()->assertSessionHas('aiSuggestion.purpose', $purpose->value)
                ->assertSessionHas('aiSuggestion.suggestions.0.purpose', $purpose->value);
        }

        $this->assertCount(3, $engine->received);
        $this->assertStringContainsString('Finalidade escolhida: consolidation (Consolidação)', $engine->received[1]->content);
        $this->assertStringContainsString('Todas as propostas devem responder exclusivamente à finalidade improvement', $engine->received[2]->instruction);
    }

    #[Test]
    public function generating_again_reuses_the_same_selection_and_can_return_a_different_answer(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => 'fake']);
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willReturn("NOME: Primeira\nOBJETIVO: objetivo.\nAPLICACAO: primeira aplicação.");
        $engine->willReturn("NOME: Segunda\nOBJETIVO: objetivo.\nAPLICACAO: segunda aplicação.");
        $this->app->instance(FakeAiTextProvider::class, $engine);

        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);
        $domain = $this->domainRow($enrollment);
        $url = "/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia";
        $payload = [
            'domain_id' => $domain['domain_id'],
            'purpose' => 'consolidation',
            'teacher_objective' => 'Consolidar a produção escrita.',
        ];

        $this->actingAs($this->teacher)->from($url)->post($url, $payload)
            ->assertSessionHas('aiSuggestion.suggestions.0.name', 'Primeira');
        $this->actingAs($this->teacher)->from($url)->post($url, $payload)
            ->assertSessionHas('aiSuggestion.suggestions.0.name', 'Segunda');

        $this->assertCount(2, $engine->received);
        $this->assertSame($engine->received[0]->content, $engine->received[1]->content);
    }

    #[Test]
    public function ai_prefill_carries_domain_application_and_a_read_only_review_hint(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);
        $domain = $this->domainRow($enrollment);
        $query = http_build_query([
            'aluno' => $enrollment->ulid,
            'dominio' => $domain['domain_id'],
            'estrategia' => 'Reescrita orientada',
            'aplicacao' => 'Produção quinzenal com segunda versão.',
            'objetivo' => 'Consolidar organização e coesão textual.',
            'finalidade' => 'consolidation',
            'revisao' => 'Após 3 evidências comparáveis',
        ]);

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/interventions?{$query}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('prefill.domain_relation', 'specific')
                ->where('prefill.domain_id', $domain['domain_id'])
                ->where('prefill.suggestion.strategy_label', 'Reescrita orientada')
                ->where('prefill.suggestion.description', 'Produção quinzenal com segunda versão.')
                ->where('prefill.suggestion.review_suggestion', 'Após 3 evidências comparáveis'));
    }

    // ------------------------------------------- ai_assistance ≠ advanced_analytics

    #[Test]
    public function a_school_can_be_pro_without_the_ai_engine_and_still_gets_the_right_reason(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => null]);

        $this->asTenant(function (): void {
            $suggester = app(InterventionStrategySuggester::class);

            $this->assertFalse($suggester->isAvailable());
            $this->assertSame('provider', $suggester->unavailableReason());
        });
    }

    #[Test]
    public function ai_assistance_can_be_withdrawn_by_override_while_advanced_analytics_stays(): void
    {
        $this->givePlan('pro');

        $module = Module::where('key', 'ai_assistance')->firstOrFail();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'module_id' => $module->id,
            'enabled' => false,
            'reason' => 'teste',
            'starts_at' => now()->subDay(),
        ]);

        app(Entitlements::class)->flush();

        $this->asTenant(function (): void {
            $this->assertFalse(app(Entitlements::class)->allows('ai_assistance'));
            $this->assertTrue(app(Entitlements::class)->allows('advanced_analytics'));
        });
    }

    #[Test]
    public function ai_assistance_can_be_enabled_while_advanced_analytics_remains_absent(): void
    {
        $this->givePlan('base');
        config(['lapis.ai.driver' => 'fake']);

        $module = Module::where('key', 'ai_assistance')->firstOrFail();

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'module_id' => $module->id,
            'enabled' => true,
            'reason' => 'teste',
            'starts_at' => now()->subDay(),
        ]);

        app(Entitlements::class)->flush();
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertInertia(fn ($page) => $page
                ->missing('pro')
                ->where('ai.available', true));
    }
}
