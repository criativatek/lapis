<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageEvent;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Ai\Gateway\AiAsk;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Ai\Gateway\AiQuota;
use App\Services\Ai\Gateway\AiQuotaExceeded;
use App\Services\Ai\Gateway\AiUseCase;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Support\Entitlements\Entitlements;
use App\Support\Privacy\AiPayloadSanitizer;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * QUOTAS, THE POOL, AND WHERE THE NUMBERS COME FROM.
 *
 * The mechanism §3 and §18 of the brief ask for, exercised end to end: a
 * ceiling per capability, a ceiling across all of them, an individual share
 * inside that, a plan that overrides a platform default, and «unlimited»
 * expressed as absence rather than as a large number.
 *
 * NO NUMBER IN THIS FILE IS A PRODUCT RULE. Every ceiling below is set by the
 * test, one or two, because what is being tested is that a configured ceiling
 * BITES — not that any particular ceiling is the right one. A test that
 * asserted «Base includes 20 requests» would be a commercial decision written
 * into a test file, which is exactly what §3 forbids.
 *
 * THE POOL IS INERT BY DEFAULT, and one of the tests below is about precisely
 * that: an Institucional organization with no plafond configured is
 * unrestricted by the pool. «Preparado para» is the requirement; «imposto» is
 * not.
 */
class AiPoolQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();

        $this->seed(EntitlementsSeeder::class);
        $this->givePlan('institutional');

        app(CurrentOrganization::class)->set($this->organization);

        config(['lapis.ai.driver' => 'fake', 'lapis.ai.model' => 'modelo-de-teste']);

        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willReturn('OK');
        $this->app->instance(FakeAiTextProvider::class, $engine);
    }

    // ---------------------------------------------------- per-capability quota

    #[Test]
    public function a_per_capability_daily_ceiling_stops_the_next_request_and_records_the_refusal(): void
    {
        config(['lapis.ai.quotas.help_assistant.user_daily' => 2]);

        $this->ask();
        $this->ask();

        try {
            $this->ask();
            $this->fail('The third request went through a ceiling of two.');
        } catch (AiQuotaExceeded $exception) {
            $this->assertSame('user_daily', $exception->scope());
            $this->assertStringContainsString('limite diário', $exception->publicMessage());
        }

        // Two billable calls and one recorded refusal — the refusal is what
        // makes «o limite está mal posto» visible in the meter.
        $this->assertSame(2, AiUsageEvent::query()->billable()->count());
        $this->assertSame('user_daily', AiUsageEvent::query()->where('status', AiUsageEvent::BLOCKED)->sole()->error_category);
    }

    /**
     * A BLOCKED CALL DOES NOT CONSUME QUOTA. It never reached an engine and
     * cost nothing; counting it would mean hitting a ceiling made the ceiling
     * harder to get back under.
     */
    #[Test]
    public function a_refused_call_does_not_count_towards_the_ceiling_it_hit(): void
    {
        config(['lapis.ai.quotas.help_assistant.user_daily' => 1]);

        $this->ask();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $this->ask();
            } catch (AiQuotaExceeded) {
                // expected
            }
        }

        $this->assertSame(1, AiUsageEvent::query()->billable()->count());
        $this->assertSame(3, AiUsageEvent::query()->where('status', AiUsageEvent::BLOCKED)->count());
    }

    /**
     * NULL MEANS NO CEILING, NOT ZERO. The distinction matters because these
     * values also come from environment variables, where an empty string is
     * the natural way to say «do not cap this».
     */
    #[Test]
    public function an_absent_ceiling_means_unlimited_rather_than_none(): void
    {
        config(['lapis.ai.quotas.help_assistant.user_daily' => null]);
        config(['lapis.ai.quotas.help_assistant.organization_monthly' => null]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->ask();
        }

        $this->assertSame(5, AiUsageEvent::query()->billable()->count());
        $this->assertNull(app(AiQuota::class)->limit($this->organization, AiCapability::HelpAssistant, 'user_daily'));
    }

    /**
     * A ZERO IS A REAL, DIFFERENT INSTRUCTION: the capability is ceilinged
     * shut. It is how an operator turns one feature off without touching the
     * plans.
     */
    #[Test]
    public function a_ceiling_of_zero_closes_the_capability(): void
    {
        config(['lapis.ai.quotas.help_assistant.user_daily' => 0]);

        $this->expectException(AiQuotaExceeded::class);

        $this->ask();
    }

    /**
     * WHERE THE NUMBERS COME FROM, in order: the plan first, the platform
     * default second. A contract that includes more than the technical ceiling
     * gets more, without a deploy.
     */
    #[Test]
    public function a_plan_limit_overrides_the_platform_default(): void
    {
        config(['lapis.ai.quotas.help_assistant.user_daily' => 1]);

        $plan = Plan::where('key', 'institutional')->firstOrFail();
        $plan->update(['limits' => [
            ...($plan->limits ?? []),
            'ai_quota' => ['help_assistant' => ['user_daily' => 3]],
        ]]);

        $this->assertSame(3, app(AiQuota::class)->limit($this->organization, AiCapability::HelpAssistant, 'user_daily'));

        $this->ask();
        $this->ask();
        $this->ask();

        $this->expectException(AiQuotaExceeded::class);
        $this->ask();
    }

    // ------------------------------------------------------------- the pool

    /**
     * PREPARED FOR, NOT IMPOSED. An Institucional organization holds
     * `ai_institutional_pool` and is nevertheless unrestricted by it until a
     * contract writes a figure — which is the honest state of a mechanism built
     * before anybody has negotiated a number (§18).
     */
    #[Test]
    public function the_pool_applies_but_is_unlimited_until_somebody_sets_a_figure(): void
    {
        $this->assertTrue(app(AiQuota::class)->poolApplies($this->organization));
        $this->assertNull(app(AiQuota::class)->poolLimit($this->organization, 'organization_monthly'));
        $this->assertNull(app(AiQuota::class)->poolLimit($this->organization, 'user_monthly'));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->ask();
        }

        $this->assertSame(5, AiUsageEvent::query()->billable()->count());
    }

    /**
     * THE POOL COUNTS ACROSS CAPABILITIES, which is the whole difference
     * between it and a set of per-capability quotas. Two help answers and one
     * pedagogical analysis are three requests against one plafond.
     */
    #[Test]
    public function the_pool_counts_every_capability_together(): void
    {
        config(['lapis.ai.pool.organization_monthly' => 3]);
        $this->grantOverride(AiCapability::PedagogicalAnalysis);

        $this->ask();
        $this->ask();
        $this->ask(AiUseCase::PedagogicalAnalysis);

        try {
            $this->ask();
            $this->fail('The fourth request went through a plafond of three.');
        } catch (AiQuotaExceeded $exception) {
            $this->assertSame('organization_pool_monthly', $exception->scope());
            $this->assertStringContainsString('conjunto das funcionalidades', $exception->publicMessage());
        }
    }

    /**
     * The individual share inside the plafond — «10000 créditos de organização
     * + teto individual opcional» from §18, with the second half switched on.
     */
    #[Test]
    public function an_individual_share_of_the_pool_stops_one_member_without_stopping_the_organization(): void
    {
        config(['lapis.ai.pool.organization_monthly' => 100, 'lapis.ai.pool.user_monthly' => 2]);

        $this->ask();
        $this->ask();

        try {
            $this->ask();
            $this->fail('A third request went through an individual share of two.');
        } catch (AiQuotaExceeded $exception) {
            $this->assertSame('user_pool_monthly', $exception->scope());
            // The message sends them to the right person: the organization's
            // administrator, not the vendor.
            $this->assertStringContainsString('administra a organização', $exception->publicMessage());
        }

        // ANOTHER MEMBER IS UNAFFECTED. An individual ceiling is individual —
        // if it stopped the whole organization it would be a pool ceiling with
        // a misleading name.
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        app(AiGateway::class)->ask($this->askObject(), $colleague);

        $this->assertSame(3, AiUsageEvent::query()->billable()->count());
    }

    /**
     * A PLAN'S OWN PLAFOND WINS, which is what makes the pool a contract figure
     * rather than a platform-wide one — the shape §18 asks for:
     * `{"ai_pool": {"organization_monthly": 10000, "user_monthly": 400}}`.
     */
    #[Test]
    public function a_contract_plafond_on_the_plan_overrides_the_platform_default(): void
    {
        config(['lapis.ai.pool.organization_monthly' => 1]);

        $plan = Plan::where('key', 'institutional')->firstOrFail();
        $plan->update(['limits' => [
            ...($plan->limits ?? []),
            AiQuota::POOL_LIMIT_KEY => ['organization_monthly' => 2],
        ]]);

        $this->assertSame(2, app(AiQuota::class)->poolLimit($this->organization, 'organization_monthly'));

        $this->ask();
        $this->ask();

        $this->expectException(AiQuotaExceeded::class);
        $this->ask();
    }

    /**
     * A PRO ORGANIZATION HAS NO POOL, so nothing about this mechanism can make
     * its ceilings tighter than they were before the pool existed. The pool is
     * an instrument an Institucional contract buys, not a new restriction
     * applied to everybody.
     */
    #[Test]
    public function an_organization_without_the_pool_capability_is_not_governed_by_one(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.pool.organization_monthly' => 1]);

        $this->assertFalse(app(AiQuota::class)->poolApplies($this->organization));

        $this->ask();
        $this->ask();
        $this->ask();

        $this->assertSame(3, AiUsageEvent::query()->billable()->count());
    }

    /**
     * The backoffice writes the plafond into `platform_settings.ai_quotas`
     * under the reserved key, and `AppServiceProvider` reads it back into
     * `config('lapis.ai.pool')` at boot. That round trip is the operator's
     * only way to set a platform-wide default, and it is easy to break
     * silently — hence a test.
     */
    #[Test]
    public function the_backoffice_can_store_the_plafond_in_the_reserved_key(): void
    {
        PlatformSetting::current()->update([
            'ai_enabled' => true,
            'ai_provider' => 'fake',
            'ai_model' => 'modelo-de-teste',
            'ai_quotas' => [
                // A real capability beside the reserved key, so the test also
                // proves the two do not interfere.
                'help_assistant' => ['user_daily' => 9],
                AiQuota::POOL_LIMIT_KEY => ['organization_monthly' => 7, 'user_monthly' => 4],
            ],
        ]);

        config(['lapis.ai.pool.organization_monthly' => null, 'lapis.ai.pool.user_monthly' => null]);

        // The boot-time mapping, run again exactly as a fresh request would run
        // it. `refreshApplication()` would drop the in-memory database with the
        // row this test just wrote, so the method is invoked directly instead.
        (new class(app()) extends AppServiceProvider
        {
            public function reapply(): void
            {
                $this->applyPlatformAiSettings();
            }
        })->reapply();

        $this->assertSame(7, config('lapis.ai.pool.organization_monthly'));
        $this->assertSame(4, config('lapis.ai.pool.user_monthly'));
        // And the reserved key did not land in the capability map.
        $this->assertSame(9, config('lapis.ai.quotas.help_assistant.user_daily'));
        $this->assertNull(config('lapis.ai.quotas.'.AiQuota::POOL_LIMIT_KEY.'.organization_monthly'));
    }

    /**
     * The backoffice form refuses a key that is not a metered capability or the
     * reserved pool key — so a typo cannot become a config entry nothing reads.
     */
    #[Test]
    public function the_backoffice_refuses_an_unknown_quota_key(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)
            ->from('/admin/ai')
            ->put('/admin/ai', [
                'ai_enabled' => true,
                'ai_quotas' => ['nao_existe' => ['user_daily' => 5]],
            ])
            ->assertSessionHasErrors('ai_quotas');
    }

    // ------------------------------------------- «null» não é perigoso

    /**
     * THE SAFETY PROPERTY THE POOL'S NULL DEFAULT DEPENDS ON.
     *
     * The plafond is `null` out of the box, which means an Institucional
     * organization is unrestricted BY THE POOL. That is only safe because the
     * per-capability ceilings are NOT null out of the box: every metered
     * capability ships with an integer default in both windows, so no
     * organization on any plan is ever without a ceiling.
     *
     * If somebody ever ships a capability with a blank default, this fails —
     * and it fails HERE, next to the pool, which is the place where somebody
     * would otherwise conclude that «sem plafond» means «sem limite».
     */
    #[Test]
    public function no_plan_leaves_an_organization_without_a_ceiling(): void
    {
        foreach (['base', 'pro', 'institutional'] as $planKey) {
            $this->givePlan($planKey);

            foreach (AiCapability::metered() as $capability) {
                foreach (AiQuota::CAPABILITY_WINDOWS as $window) {
                    $this->assertIsInt(
                        app(AiQuota::class)->limit($this->organization, $capability, $window),
                        "No plano {$planKey}, {$capability->value}/{$window} não tem teto — «sem plafond» passaria a significar «sem limite».",
                    );
                }
            }
        }
    }

    /**
     * NENHUMA ORGANIZAÇÃO GANHA UM PLAFOND INVENTADO.
     *
     * Nem por omissão de configuração, nem por estar num plano semeado. O
     * plafond existe como mecanismo e não como número — a figura é contratual,
     * e um valor por omissão inventaria um para todos os clientes
     * institucionais de uma vez.
     */
    #[Test]
    public function no_seeded_plan_carries_an_invented_pool_or_quota(): void
    {
        foreach (Plan::all() as $plan) {
            $limits = $plan->limits ?? [];

            $this->assertArrayNotHasKey(
                AiQuota::POOL_LIMIT_KEY,
                $limits,
                "O plano «{$plan->key}» traz um plafond semeado. O plafond é uma figura contratual.",
            );
            $this->assertArrayNotHasKey(
                'ai_quota',
                $limits,
                "O plano «{$plan->key}» traz uma quota de IA semeada. Quanto um plano inclui é uma decisão comercial por tomar.",
            );
        }

        // E a configuração da plataforma também não inventa um plafond.
        $this->assertNull(config('lapis.ai.pool.organization_monthly'));
        $this->assertNull(config('lapis.ai.pool.user_monthly'));
    }

    /**
     * «SEM TETO» É UM ESTADO ALCANÇÁVEL, EXPLÍCITO E TESTADO — não um acidente
     * de configuração em falta.
     *
     * `AiQuota` usa `null` onde `Limits` usa a string `"unlimited"`, e a
     * diferença é deliberada: estes valores também vêm de variáveis de
     * ambiente, onde uma string vazia é a forma natural de dizer «não limites
     * isto», e `(int) ''` seria `0` — que aqui significa o oposto. O ecrã de
     * administração nunca mostra uma caixa vazia por baixo de um valor em
     * vigor: mostra o número que está mesmo a ser aplicado.
     */
    #[Test]
    public function the_two_absent_states_are_distinguishable_and_neither_is_accidental(): void
    {
        // Ausência = sem teto.
        config(['lapis.ai.quotas.help_assistant.user_daily' => null]);
        $this->assertNull(app(AiQuota::class)->limit($this->organization, AiCapability::HelpAssistant, 'user_daily'));

        // Zero = fechado. Um estado real e diferente.
        config(['lapis.ai.quotas.help_assistant.user_daily' => 0]);
        $this->assertSame(0, app(AiQuota::class)->limit($this->organization, AiCapability::HelpAssistant, 'user_daily'));

        // E um valor não inteiro num plano é tratado como «não configurado»,
        // nunca como erro: um override que ninguém definiu não pode partir um
        // caminho de pedido.
        $plan = Plan::where('key', 'institutional')->firstOrFail();
        $plan->update(['limits' => [
            ...($plan->limits ?? []),
            'ai_quota' => ['help_assistant' => ['user_daily' => 'unlimited']],
        ]]);

        $this->assertSame(
            0,
            app(AiQuota::class)->limit($this->organization, AiCapability::HelpAssistant, 'user_daily'),
            'Um valor inválido no plano deve cair para a configuração, não sobrepor-se a ela.',
        );
    }

    // ---------------------------------------------------------------- helpers

    private function ask(AiUseCase $useCase = AiUseCase::HelpAnswer): void
    {
        app(AiGateway::class)->ask($this->askObject($useCase), $this->teacher);
    }

    private function askObject(AiUseCase $useCase = AiUseCase::HelpAnswer): AiAsk
    {
        return new AiAsk(
            useCase: $useCase,
            instruction: 'Responde a partir dos artigos do Centro de Ajuda.',
            content: app(AiPayloadSanitizer::class)->sanitise('Como crio uma turma?'),
            promptVersion: 'teste/1',
        );
    }

    private function grantOverride(AiCapability $capability): void
    {
        OrganizationModuleOverride::withoutGlobalScope('organization')->updateOrCreate(
            [
                'organization_id' => $this->organization->getKey(),
                'module_id' => Module::where('key', $capability->value)->firstOrFail()->getKey(),
            ],
            ['enabled' => true],
        );

        app(Entitlements::class)->flush();
    }

    protected function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->getKey()],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->getKey(),
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }
}
