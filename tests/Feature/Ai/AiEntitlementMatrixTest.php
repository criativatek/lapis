<?php

namespace Tests\Feature\Ai;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiGateway;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE COMMERCIAL MATRIX, ASSERTED ONE CELL AT A TIME.
 *
 * Twenty-four assertions — eight capabilities × three plans — written out in
 * full rather than derived from the seeder they are checking. A test that read
 * `EntitlementsSeeder::PRO_MODULES` and asserted that Pro grants exactly those
 * would pass whatever the seeder said, which is the one thing it must not do:
 * the point is to catch a change to the composition, and a test that changes
 * with it catches nothing.
 *
 * IT ASKS `AiGateway`, NOT THE DATABASE. `$module->plans()->contains(...)` would
 * check that a row exists; this checks that a REQUEST would be allowed, which
 * is the thing a school actually experiences and which runs through the legacy
 * alias, the access-state resolution and the override layer on the way. A
 * composition that is right in `module_plan` and wrong in the resolver is a
 * failure this test sees and a schema test does not.
 *
 * NOT A SINGLE `if ($plan === 'pro')` ANYWHERE IN THE SOURCE, and that is
 * asserted separately by `AiArchitectureTest`. This file is the other half of
 * the same guarantee: the composition lives in data, and here is what the data
 * currently says.
 *
 * THE SOURCE OF THE NUMBERS IS THE MATRIZ MESTRE. Changing a cell below is a
 * commercial decision (CLAUDE.md §31), and the test failing is the point at
 * which somebody notices they are taking one.
 */
class AiEntitlementMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The matrix, transcribed from the Matriz Mestre.
     *
     * @return array<string, array{0: string, 1: array<string, bool>}>
     */
    public static function matrix(): array
    {
        return [
            'Base' => ['base', [
                'help_assistant' => true,
                'ai_pedagogical_analysis' => false,
                'ai_assessment' => false,
                'ai_followup' => false,
                'ai_strategies' => false,
                'ai_reports' => false,
                'ai_governance' => false,
                'ai_institutional_pool' => false,
            ]],
            'Pro' => ['pro', [
                'help_assistant' => true,
                'ai_pedagogical_analysis' => true,
                'ai_assessment' => true,
                'ai_followup' => true,
                'ai_strategies' => true,
                'ai_reports' => true,
                'ai_governance' => false,
                'ai_institutional_pool' => false,
            ]],
            'Institucional' => ['institutional', [
                'help_assistant' => true,
                'ai_pedagogical_analysis' => true,
                'ai_assessment' => true,
                'ai_followup' => true,
                'ai_strategies' => true,
                'ai_reports' => true,
                'ai_governance' => true,
                'ai_institutional_pool' => true,
            ]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EntitlementsSeeder::class);

        // An engine, so `isAvailable()` answers the ENTITLEMENT question rather
        // than «nothing is configured». Without this every cell would be false
        // for the same uninteresting reason.
        config([
            'lapis.ai.driver' => 'fake',
            'lapis.ai.model' => 'modelo-de-teste',
        ]);
    }

    /**
     * @param  array<string, bool>  $expected
     */
    #[DataProvider('matrix')]
    #[Test]
    public function a_plan_grants_exactly_the_capabilities_the_matriz_says(string $planKey, array $expected): void
    {
        $organization = $this->organizationOn($planKey);

        foreach ($expected as $key => $allowed) {
            $capability = AiCapability::from($key);

            $this->assertSame(
                $allowed,
                app(AiGateway::class)->isAvailable($capability),
                sprintf(
                    'Plano %s / %s: esperado %s. A composição comercial mudou — ver a Matriz Mestre antes de alterar este teste.',
                    $planKey,
                    $key,
                    $allowed ? 'incluído' : 'não incluído',
                ),
            );

            // The reason a screen would show. `plan` is the upgrade-shaped
            // state; null means it is genuinely available.
            $this->assertSame(
                $allowed ? null : 'plan',
                app(AiGateway::class)->unavailableReason($capability),
            );
        }

        unset($organization);
    }

    /**
     * NO ORGANIZATION AT ALL IS «PLAN», NOT A CRASH.
     *
     * A job, a console command, or a request that never resolved a tenant asks
     * the gateway the same question, and the answer has to be a refusal rather
     * than an exception out of `CurrentOrganization::get()`.
     */
    #[Test]
    public function with_no_resolved_organization_every_capability_is_refused(): void
    {
        foreach (AiCapability::cases() as $capability) {
            $this->assertFalse(app(AiGateway::class)->isAvailable($capability));
            $this->assertSame('plan', app(AiGateway::class)->unavailableReason($capability));
        }
    }

    /**
     * An override is how a pilot, a voucher or a temporary benefit is expressed
     * — the mechanism §3 of the brief asks for, using what already exists
     * rather than a second system. A Base organization with an override on one
     * capability holds that one and none of the others.
     */
    #[Test]
    public function an_override_grants_one_capability_without_touching_the_rest(): void
    {
        $organization = $this->organizationOn('base');

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'module_id' => Module::where('key', 'ai_assessment')->firstOrFail()->getKey(),
            'enabled' => true,
        ]);
        app(Entitlements::class)->flush();

        $this->assertTrue(app(AiGateway::class)->isAvailable(AiCapability::Assessment));
        $this->assertFalse(app(AiGateway::class)->isAvailable(AiCapability::Followup));
        $this->assertFalse(app(AiGateway::class)->isAvailable(AiCapability::Governance));
    }

    /**
     * An override with a validity window that has passed grants nothing — the
     * «benefício temporário» half of the same mechanism. `isInForce()` on
     * `OrganizationModuleOverride` is what decides, and this is the AI-facing
     * proof that the gateway respects it.
     */
    #[Test]
    public function an_expired_override_grants_nothing(): void
    {
        $organization = $this->organizationOn('base');

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'module_id' => Module::where('key', 'ai_assessment')->firstOrFail()->getKey(),
            'enabled' => true,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
        app(Entitlements::class)->flush();

        $this->assertFalse(app(AiGateway::class)->isAvailable(AiCapability::Assessment));
    }

    /**
     * A TRIAL IS A SUBSCRIPTION STATUS, NOT A SEPARATE PATH. An organization on
     * a Pro trial gets exactly what Pro gets, because `Entitlements` resolves
     * the plan of whichever subscription `isInForce()` and a trial is one.
     * Asserted here so «trial/override: usar comportamento real existente»
     * (§25) is a checked claim rather than an assumption.
     */
    #[Test]
    public function a_pro_trial_grants_what_pro_grants(): void
    {
        $organization = $this->organizationOn('pro', SubscriptionStatus::Trial);

        $this->assertTrue(app(AiGateway::class)->isAvailable(AiCapability::Assessment));
        $this->assertTrue(app(AiGateway::class)->isAvailable(AiCapability::Followup));
        $this->assertFalse(app(AiGateway::class)->isAvailable(AiCapability::Governance));
    }

    private function organizationOn(string $planKey, SubscriptionStatus $status = SubscriptionStatus::Active): Organization
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $organization->getKey()],
            [
                'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
                'status' => $status,
                'starts_at' => now()->subDay(),
                'ends_at' => $status === SubscriptionStatus::Trial ? now()->addDays(20) : null,
            ],
        );

        app(CurrentOrganization::class)->set($organization);
        app(Entitlements::class)->flush();

        return $organization;
    }
}
