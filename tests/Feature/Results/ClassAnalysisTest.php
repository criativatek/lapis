<?php

namespace Tests\Feature\Results;

use App\Models\AiUsageEvent;
use App\Models\AuditEvent;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiUseCase;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Assessment\BuildClassStatistics;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Analisar com IA» on the Estatística page, end to end.
 *
 * TWO GUARANTEES ARE LOAD-BEARING HERE and everything else is detail: that no
 * student identifier reaches the engine, and that nothing in the application
 * changes as a result of an AI answer. Both are asserted against observable
 * facts — what `FakeAiTextProvider` actually received, and the database rows
 * before and after — rather than against the controller's good intentions.
 */
class ClassAnalysisTest extends TestCase
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
        $this->givePlan('pro');
        $this->grant(AiCapability::PedagogicalAnalysis);
    }

    /**
     * `ai_pedagogical_analysis` is catalogued and composed into no plan at all
     * — which subscription includes it is a commercial decision nobody has
     * taken (ai-core contract §10.1). A per-organization override is the
     * supported way to give a pilot access, and it is what these tests use.
     */
    protected function grant(AiCapability $capability, bool $enabled = true): void
    {
        OrganizationModuleOverride::withoutGlobalScope('organization')->updateOrCreate(
            [
                'organization_id' => $this->organization->getKey(),
                'module_id' => Module::where('key', $capability->value)->firstOrFail()->getKey(),
            ],
            ['enabled' => $enabled],
        );

        app(Entitlements::class)->flush();
    }

    protected function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
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

    private function url(): string
    {
        return '/classes/'.$this->schoolClass()->ulid.'/results/estatistica/analise-ia';
    }

    private function statisticsUrl(): string
    {
        return '/classes/'.$this->schoolClass()->ulid.'/results/estatistica';
    }

    protected function engine(?FakeAiTextProvider $engine = null): FakeAiTextProvider
    {
        config(['lapis.ai.driver' => 'fake']);

        $engine ??= new FakeAiTextProvider('modelo-de-teste');
        $this->app->instance(FakeAiTextProvider::class, $engine);

        return $engine;
    }

    private function analysing(): FakeAiTextProvider
    {
        return $this->engine()->willReturn(<<<'TEXT'
        SINTESE: Os resultados concentram-se no nível intermédio da escala.
        PADROES: - A distribuição é estreita.
        - A evolução face ao período anterior é ligeiramente positiva.
        ATENCAO: - Alguns resultados assentam em cobertura parcial.
        SUGESTOES: - Pode ser útil considerar mais evidência no domínio mais fraco.
        TEXT);
    }

    // ─────────────────────────────────────────────────── a análise estruturada

    #[Test]
    public function the_analysis_comes_back_as_four_blocks_and_never_as_raw_text(): void
    {
        $this->analysing();

        $this->actingAs($this->teacher)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertRedirect($this->statisticsUrl())
            ->assertSessionHas('aiAnalysis', function (array $analysis): bool {
                return str_contains($analysis['summary'], 'nível intermédio')
                    && count($analysis['patterns']) === 2
                    && count($analysis['cautions']) === 1
                    && count($analysis['suggestions']) === 1
                    && $analysis['period_label'] !== null;
            });
    }

    #[Test]
    public function the_statistics_page_carries_the_experiences_state_but_never_calls_an_engine_to_render(): void
    {
        $engine = $this->analysing();

        $this->actingAs($this->teacher)
            ->get($this->statisticsUrl())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('results/Statistics')
                ->where('ai.available', true)
                ->where('ai.reason', null));

        // Opening the page must never spend money.
        $this->assertSame([], $engine->received);
    }

    // ────────────────────────────────────────────────────────── privacidade

    #[Test]
    public function no_student_name_or_number_reaches_the_engine(): void
    {
        $engine = $this->analysing();

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url());

        $content = $engine->lastRequest()?->content ?? '';

        // Taken from the read model's OWN output — which is the array the
        // context builder was handed. These are precisely the names that
        // could have leaked, so asserting against them proves the stripping
        // rather than proving that some unrelated query returned nothing.
        // (They cannot be read from `students` directly: this application
        // already stores students under a `pseudonym_code` at rest, with the
        // display name encrypted in `student_identities`.)
        $names = $this->asTenant(fn (): array => array_column(
            app(BuildClassStatistics::class)->for($this->schoolClass())['students'],
            'name',
        ));

        $this->assertNotEmpty($names, 'A turma de demonstração deve ter alunos, ou este teste não prova nada.');

        foreach ($names as $name) {
            $this->assertStringNotContainsString((string) $name, $content);
        }

        // The pseudonyms the Core's `Pseudonyms` map produced, in place of the
        // names the read model handed over.
        $this->assertStringContainsString('Aluno A', $content);
        $this->assertStringContainsString('Resultado por aluno:', $content);
    }

    /**
     * §9 — the whole pipeline, with real fixture names, down to what the
     * provider actually received.
     */
    #[Test]
    public function the_payload_carries_pseudonyms_and_results_but_no_names_or_identifiers(): void
    {
        $engine = $this->analysing();

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url());

        $content = $engine->lastRequest()?->content ?? '';
        $statistics = $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for($this->schoolClass()));

        // 1. NO NAMES. Every name the read model knows, absent.
        foreach (array_column($statistics['students'], 'name') as $name) {
            $this->assertStringNotContainsString((string) $name, $content);
        }

        // 2. NO IDENTIFIERS. The enrollment id is never carried as a field at
        // all — asserting the bare integer is absent would be meaningless,
        // because a one-digit id collides with every «1» in a percentage. What
        // is checked is that the key never travels, and that the one
        // identifier long enough to be unambiguous is nowhere in the payload.
        $this->assertStringNotContainsString('enrollment', $content);
        $this->assertStringNotContainsString('class_number', $content);
        $this->assertStringNotContainsString($this->schoolClass()->ulid, $content);

        // 3. PSEUDONYMS ARRIVE. One per student row, in result order.
        $this->assertStringContainsString('Aluno A', $content);

        // 4. THE PEDAGOGICAL SUBSTANCE ARRIVES — the whole reason for asking.
        // The figures the page computed, and the criteria they belong to.
        $this->assertStringContainsString('Média da turma: '.$statistics['summary']['primary_average'], $content);
        $this->assertStringContainsString('Distribuição pela escala:', $content);
        $this->assertStringContainsString('Por domínio:', $content);
        $this->assertStringContainsString('Taxa de sucesso', $content);

        foreach ($statistics['domain_statistics'] as $domain) {
            $this->assertStringContainsString((string) $domain['label'], $content);
        }
    }

    #[Test]
    public function the_figures_sent_are_the_ones_the_page_computed_and_the_prompt_forbids_recomputing(): void
    {
        $engine = $this->analysing();

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url());

        $request = $engine->lastRequest();

        $this->assertNotNull($request);
        $this->assertStringContainsString('Média da turma:', $request->content);
        $this->assertStringContainsString('não calcules nada', $request->instruction);
        $this->assertStringContainsString('FONTE DA VERDADE', $request->instruction);

        // The two halves never meet: the figures are content, the rules are
        // the instruction, and `AiAsk` will not let a caller concatenate them.
        $this->assertStringNotContainsString('Média da turma:', $request->instruction);
    }

    // ─────────────────────────────────────────── a IA sugere, não escreve

    #[Test]
    public function an_analysis_changes_nothing_in_the_application(): void
    {
        $this->analysing();

        $before = $this->asTenant(fn (): array => [
            'scores' => DB::table('student_item_scores')->count(),
            'classifications' => DB::table('classifications')->count(),
            'interventions' => DB::table('interventions')->count(),
            'checksum' => DB::table('student_item_scores')->orderBy('id')->pluck('points_earned')->join('~'),
        ]);

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url())->assertRedirect();

        $after = $this->asTenant(fn (): array => [
            'scores' => DB::table('student_item_scores')->count(),
            'classifications' => DB::table('classifications')->count(),
            'interventions' => DB::table('interventions')->count(),
            'checksum' => DB::table('student_item_scores')->orderBy('id')->pluck('points_earned')->join('~'),
        ]);

        $this->assertSame($before, $after, 'Uma análise de IA não pode alterar nenhum dado da aplicação.');
    }

    #[Test]
    public function the_analysis_carries_no_identifier_the_application_could_act_on(): void
    {
        $this->analysing();

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url());

        $analysis = session('aiAnalysis');

        // Four blocks of prose, plus which period they describe. Nothing here
        // is a key, and there is no endpoint that would accept one.
        $this->assertSame(
            ['period_id', 'period_label', 'summary', 'patterns', 'cautions', 'suggestions'],
            array_keys($analysis),
        );
    }

    // ──────────────────────────────────────────────────────── capabilities

    #[Test]
    public function an_organization_without_the_capability_is_refused_on_the_server_and_told_it_is_the_plan(): void
    {
        $engine = $this->engine();

        // Revoked rather than downgraded: `ai_pedagogical_analysis` belongs to
        // no plan, so Base and Pro are equally without it.
        $this->grant(AiCapability::PedagogicalAnalysis, enabled: false);

        $this->actingAs($this->teacher)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertSessionHas('aiAnalysisError', fn (array $error): bool => str_contains($error['message'], 'plano'));

        $this->assertSame([], $engine->received);

        $this->actingAs($this->teacher)
            ->get($this->statisticsUrl())
            ->assertInertia(fn ($page) => $page->where('ai.available', false)->where('ai.reason', 'plan'));
    }

    #[Test]
    public function with_no_engine_at_all_the_page_still_works_and_says_it_is_switched_off(): void
    {
        config(['lapis.ai.driver' => null]);

        $this->actingAs($this->teacher)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertSessionHas('aiAnalysisError', fn (array $error): bool => str_contains($error['message'], 'não está ativada'));

        $this->actingAs($this->teacher)
            ->get($this->statisticsUrl())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ai.available', false)->where('ai.reason', 'off'));
    }

    #[Test]
    public function a_quota_ceiling_is_a_readable_state_and_not_an_error_screen(): void
    {
        $this->analysing()->willReturn("SINTESE: Outra leitura.\nPADROES: - Um padrão.\nSUGESTOES: - Uma sugestão.");

        // The gateway's own per-capability ceiling. There is no `throttle:`
        // middleware on this route, precisely so it is counted once.
        config(['lapis.ai.per_minute' => 1]);
        RateLimiter::clear(AiCapability::PedagogicalAnalysis->rateLimiterKey().':user:'.$this->teacher->getKey());

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url())
            ->assertSessionHas('aiAnalysis');

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url())
            ->assertRedirect($this->statisticsUrl())
            ->assertSessionHas('aiAnalysisError')
            ->assertSessionMissing('aiAnalysis');
    }

    #[Test]
    public function the_route_carries_no_throttle_middleware_of_its_own(): void
    {
        $middleware = collect(app('router')->getRoutes()->getByName('results.statistics.analyse')?->gatherMiddleware() ?? [])
            ->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle'));

        $this->assertTrue($middleware->isEmpty(), 'A rota da análise não deve ter throttle próprio: o AiGateway já limita a capability.');
    }

    #[Test]
    public function another_teachers_class_is_not_analysable(): void
    {
        $engine = $this->analysing();

        $stranger = User::factory()->create();

        // 404, not 403, and that is the stronger answer: the organization
        // global scope means route-model binding never resolves another
        // tenant's class at all, so the request dies before `Gate::authorize`
        // is even reached and the URL does not confirm that the class exists.
        $this->actingAs($stranger)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertNotFound();

        $this->assertSame([], $engine->received);
    }

    // ────────────────────────────────────────────────────── estados de erro

    #[Test]
    public function a_timeout_is_a_controlled_retryable_message_with_no_technical_detail(): void
    {
        $this->engine()->willFail(AiRequestFailed::timedOut(20));

        $this->actingAs($this->teacher)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertRedirect($this->statisticsUrl())
            ->assertSessionHas('aiAnalysisError')
            ->assertSessionMissing('aiAnalysis');

        $message = session('aiAnalysisError')['message'];

        $this->assertStringNotContainsString('20s', $message);
        $this->assertStringNotContainsString('http', mb_strtolower($message));
    }

    #[Test]
    public function an_upstream_rate_limit_says_try_again_shortly_without_the_status_code(): void
    {
        $this->engine()->willFail(AiRequestFailed::refused(429));

        $this->actingAs($this->teacher)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertSessionHas('aiAnalysisError', fn (array $error): bool => ! str_contains($error['message'], '429'));
    }

    #[Test]
    public function an_unparseable_answer_fails_cleanly_rather_than_showing_empty_blocks(): void
    {
        $this->engine()->willReturn('A turma está bem, obrigado por perguntar.');

        $this->actingAs($this->teacher)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertSessionHas('aiAnalysisError')
            ->assertSessionMissing('aiAnalysis');
    }

    #[Test]
    public function a_failure_can_be_retried_and_the_next_attempt_succeeds(): void
    {
        $engine = $this->engine();
        $engine->willFail(AiRequestFailed::unreachable());

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url())
            ->assertSessionHas('aiAnalysisError');

        // The scripted failure is consumed; the retry meets a working engine,
        // which is exactly what the «Tentar novamente» button produces.
        $engine->willReturn("SINTESE: Uma leitura.\nPADROES: - Um padrão.\nSUGESTOES: - Uma sugestão.");

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url())
            ->assertSessionHas('aiAnalysis')
            ->assertSessionMissing('aiAnalysisError');
    }

    // ──────────────────────────────────────────────────────── auditoria

    #[Test]
    public function the_audit_trail_records_the_shape_of_the_call_but_never_the_context_or_the_answer(): void
    {
        $this->analysing();

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url());

        $event = AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'results.ai_analysis_requested')
            ->latest('id')
            ->firstOrFail();

        $encoded = (string) json_encode($event->properties, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Aluno A', $encoded);
        $this->assertStringNotContainsString('nível intermédio', $encoded);
        $this->assertTrue($event->properties['pseudonymised']);
        $this->assertTrue($event->properties['parsed']);
        $this->assertSame('fake', $event->properties['provider']);
    }

    #[Test]
    public function the_cores_usage_event_records_the_call_and_carries_no_context_or_answer(): void
    {
        $this->analysing();

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url());

        $event = AiUsageEvent::withoutGlobalScope('organization')->latest('id')->firstOrFail();

        $this->assertSame(AiCapability::PedagogicalAnalysis->value, $event->capability);
        $this->assertSame(AiUseCase::PedagogicalAnalysis, $event->use_case);
        $this->assertSame('fake', $event->provider);
        $this->assertSame('succeeded', $event->status);

        // The schema is the guarantee, not a rule anybody follows: there is no
        // column for a prompt, an answer, a student or a result — so the whole
        // row can be searched for all four (contract §7).
        $row = (string) json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Aluno A', $row);
        $this->assertStringNotContainsString('nível intermédio', $row);
        $this->assertStringNotContainsString('Média da turma', $row);
        $this->assertStringNotContainsString($this->schoolClass()->ulid, $row);
    }

    #[Test]
    public function the_two_experiences_are_metered_under_their_own_use_cases(): void
    {
        $this->analysing();

        $this->actingAs($this->teacher)->from($this->statisticsUrl())->post($this->url());

        // Distinct from the Centro de Ajuda's `help_answer`, so a school can be
        // told what its pedagogical reading cost separately from its help desk.
        $this->assertSame(
            1,
            AiUsageEvent::withoutGlobalScope('organization')
                ->where('use_case', AiUseCase::PedagogicalAnalysis->value)
                ->count(),
        );

        $this->assertSame(
            0,
            AiUsageEvent::withoutGlobalScope('organization')
                ->where('use_case', AiUseCase::HelpAnswer->value)
                ->count(),
        );
    }

    // ─────────────────────────── os nomes, de volta e sem género inventado

    #[Test]
    public function the_answer_reaches_the_teacher_with_real_names_and_never_with_pseudonyms(): void
    {
        // A FRASE EXATA QUE UM MODELO ESCREVE, e a que o produto não conseguia
        // desfazer: o prefixo uma vez, no plural, e as letras soltas (§39).
        $this->engine()->willReturn(<<<'TEXT'
        SINTESE: A turma acompanha, com exceção dos alunos A e B.
        PADROES: - O Aluno A destacou-se em Leitura.
        ATENCAO: - Recomenda-se acompanhamento ao aluno B.
        SUGESTOES: - Pode ser útil rever a evidência do Aluno A.
        TEXT);

        $this->actingAs($this->teacher)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertRedirect($this->statisticsUrl());

        /** @var array<string, mixed> $analysis */
        $analysis = session('aiAnalysis');
        $text = json_encode($analysis, JSON_UNESCAPED_UNICODE);

        // NENHUM PSEUDÓNIMO CHEGA AO ECRÃ, em nenhuma das formas.
        $this->assertDoesNotMatchRegularExpression('/\balunos?\s+[A-Z]\b/iu', (string) $text);

        // E o que chega são nomes reais desta turma, primeiro e último (§40).
        $this->assertMatchesRegularExpression('/\p{Lu}\p{L}+ \p{Lu}\p{L}+/u', (string) $text);
    }

    #[Test]
    public function no_real_name_is_ever_sent_to_the_engine(): void
    {
        $engine = $this->analysing();

        $this->actingAs($this->teacher)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertRedirect($this->statisticsUrl());

        $sent = (string) $engine->lastRequest()?->content;

        // A outra metade da mesma regra, e a mais importante das duas: os nomes
        // voltam porque nunca saíram (§39, §73).
        foreach (['Carolina', 'Nunes', 'Diogo', 'Ferreira', 'Salgado', 'Andrade'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $sent);
        }

        $this->assertStringContainsString('Aluno A', $sent);
    }

    #[Test]
    public function an_article_before_a_pseudonym_never_becomes_an_article_before_a_name(): void
    {
        $this->engine()->willReturn(<<<'TEXT'
        SINTESE: O Aluno A manteve o nível.
        PADROES: - A distribuição é estreita.
        ATENCAO: - Nada a assinalar.
        SUGESTOES: - Nada a sugerir.
        TEXT);

        $this->actingAs($this->teacher)
            ->from($this->statisticsUrl())
            ->post($this->url())
            ->assertRedirect($this->statisticsUrl());

        /** @var array<string, mixed> $analysis */
        $analysis = session('aiAnalysis');

        // «O Aluno A» não pode virar «O Marta Tomás»: o artigo sai com o
        // pseudónimo, e ninguém infere género nenhum a partir de um nome (§42).
        $this->assertDoesNotMatchRegularExpression('/\bO \p{Lu}/u', (string) $analysis['summary']);
    }
}
